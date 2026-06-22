<?php
// dialer.php — Полная мультипоточная версия фонового робота (Очереди, IVR, Стоп-лист, Планировщик и Автореконнект AMI)
require_once __DIR__ . '/config.php';

set_time_limit(0);

$statuses = ['paused' => 0, 'active' => 1, 'completed' => 2];
$socket = null; // Глобальный ресурс для сокета AMI

try {
    $pdo = db_pdo('dialer'); // Используем твои родные функции конфигурации
    $pdo_asterisk = db_pdo('asterisk'); // База FreePBX
} catch (PDOException $e) {
    die("[" . date('Y-m-d H:i:s') . "] Ошибка подключения к БД: " . $e->getMessage() . "\n");
}

// --- ФУНКЦИЯ БЕЗОПАСНОГО ПОДКЛЮЧЕНИЯ И АВТОРИЗАЦИИ В AMI ---
function connectToAMI() {
    global $socket;
    
    // Если сокет уже открыт (например, завис) — аккуратно закрываем его перед переподключением
    if (is_resource($socket)) {
        @fclose($socket);
    }

    $ami = app_config('ami'); // Вытягиваем параметры авторизации из твоего config.php
    echo "[" . date('Y-m-d H:i:s') . "] [AMI] Попытка подключения к Asterisk AMI...\n";
    
    $socket = @fsockopen($ami['host'], (int)$ami['port'], $errno, $errstr, (int)$ami['timeout']);
    if (!$socket) {
        echo "[" . date('Y-m-d H:i:s') . "] [AMI ERROR] Подключение не удалось: $errstr ($errno). Повтор через 5 секунд...\n";
        return false;
    }

    fgets($socket, 1024); // Читаем приветственную строку Asterisk
    usleep(100000);

    // Отправляем пакет авторизации
    fputs($socket, "Action: Login\r\nUsername: {$ami['user']}\r\nSecret: {$ami['pass']}\r\n\r\n");
    usleep(100000);

    $response = "";
    while (!feof($socket)) {
        $line = fgets($socket, 1024);
        $response .= $line;
        if (trim($line) == "") break; // Конец блока ответа
    }

    if (strpos($response, "Response: Success") === false) {
        echo "[" . date('Y-m-d H:i:s') . "] [AMI ERROR] Ошибка авторизации (Неверный логин/пароль)!\n";
        return false;
    }

    echo "[" . date('Y-m-d H:i:s') . "] [AMI] Успешно авторизовано в Asterisk. Линия готова.\n";
    stream_set_blocking($socket, false); // Переводим в неблокирующий асинхронный режим
    return true;
}

// Первичный запуск сокета при старте демона
while (!connectToAMI()) {
    sleep(5);
}

echo "[" . date('Y-m-d H:i:s') . "] Демон VoxDial (МУЛЬТИПОТОК + СТОП-ЛИСТ + ПЛАНИРОВЩИК) успешно запущен.\n";
$last_debug_time = 0;

// =========================================================================
// 🔥 ГЛАВНЫЙ БЕСКОНЕЧНЫЙ ЦИКЛ ОБЗВОНА КЛИЕНТОВ
// =========================================================================
while (true) {
    usleep(500000); // Пауза полсекунды между общими кругами опроса всей системы

    // 🛡️ ПРОВЕРКА ЖИВУЧЕСТИ СОКЕТА: Если Asterisk закрыл сессию — уходим в цикл восстановления
    if (!is_resource($socket) || feof($socket)) {
        echo "[" . date('Y-m-d H:i:s') . "] [AMI] Обнаружен разрыв соединения в режиме ожидания. Восстановление...\n";
        while (!connectToAMI()) {
            sleep(5);
        }
    }

    // Безопасная чистка буфера входящих AMI событий
    while ($socket && !feof($socket) && $ami_line = @fgets($socket, 1024)) { /* Чистим буфер */ }

    $show_debug = (time() - $last_debug_time >= 10); // Ограничитель дебаг-логов до 10 сек
    if ($show_debug) $last_debug_time = time();

    // =========================================================================
    // ⏰ БЛОК 1. АВТОМАТИЧЕСКИЙ ПЛАНИРОВЩИК: КОНТРОЛЬ ВРЕМЕНИ СТАРТА И ПАУЗЫ
    // =========================================================================
    static $last_scheduler_check = 0;
    
    if (time() - $last_scheduler_check >= 30) {
        $last_scheduler_check = time();
        $current_time = date('H:i:00'); // Текущее время сервера с округлением до минут

        // 1. Ищем кампании, которым ПОРА СТАРТОВАТЬ (они на паузе (0), работают по расписанию и время пришло)
        $stmt_sched_start = $pdo->prepare("
            SELECT id, name FROM campaigns 
            WHERE status = 0 
              AND start_immediately = 0 
              AND scheduled_start_time <= :current_time 
              AND (scheduled_pause_time IS NULL OR scheduled_pause_time > :current_time)
        ");
        $stmt_sched_start->execute(['current_time' => $current_time]);
        $campaigns_to_start = $stmt_sched_start->fetchAll(PDO::FETCH_ASSOC);

        foreach ($campaigns_to_start as $c_to_start) {
            $pdo->prepare("UPDATE campaigns SET status = 1 WHERE id = ?")->execute([(int)$c_to_start['id']]);
            echo "[" . date('Y-m-d H:i:s') . "] [ПЛАНИРОВЩИК] Кампания [{$c_to_start['name']}] автоматически ВКЛЮЧЕНА по расписанию.\n";
        }

        // 2. Ищем кампании, которым ПОРА НА ПАУЗУ (они активны (1), работают по расписанию и время вышло)
        $stmt_sched_pause = $pdo->prepare("
            SELECT id, name FROM campaigns 
            WHERE status = 1 
              AND start_immediately = 0 
              AND scheduled_pause_time IS NOT NULL 
              AND :current_time >= scheduled_pause_time
        ");
        $stmt_sched_pause->execute(['current_time' => $current_time]);
        $campaigns_to_pause = $stmt_sched_pause->fetchAll(PDO::FETCH_ASSOC);

        foreach ($campaigns_to_pause as $c_to_pause) {
            $pdo->prepare("UPDATE campaigns SET status = 0 WHERE id = ?")->execute([(int)$c_to_pause['id']]);
            echo "[" . date('Y-m-d H:i:s') . "] [ПЛАНИРОВЩИК] Кампания [{$c_to_pause['name']}] автоматически поставлена на ПАУЗУ по расписанию.\n";
        }
    }

    // =========================================================================
    // БЛОК 2. ЗАГРУЖАЕМ ВСЕ АКТИВНЫЕ НА ТЕКУЩИЙ МОМЕНТ КАМПАНИИ
    // =========================================================================
    $stmt_camp = $pdo->prepare("SELECT * FROM campaigns WHERE status = :status");
    $stmt_camp->execute(['status' => $statuses['active']]);
    $active_campaigns = $stmt_camp->fetchAll(PDO::FETCH_ASSOC);

    if (empty($active_campaigns)) {
        if ($show_debug) echo "[" . date('Y-m-d H:i:s') . "] [ДЕБАГ] Нет запущенных кампаний обзвона.\n";
        continue; 
    }

    // Буфер фиксации выстрела, чтобы не грузить транки за один круг цикла, если кампаний много
    $any_dial_done = false;

    // ПЕРЕБИРАЕМ КАЖДУЮ АКТИВНУЮ КАМПАНИЮ ПАРАЛЛЕЛЬНО
    foreach ($active_campaigns as $campaign) {
        $campaign_id = (int)$campaign['id'];
        $trunk_id = (int)$campaign['trunk_id'];
        $max_channels = isset($campaign['channel_limit']) ? (int)$campaign['channel_limit'] : 5;
        
        $dest_type = isset($campaign['destination_type']) ? $campaign['destination_type'] : 'queue';
        $dest_value = (!empty($campaign['destination_value'])) ? trim($campaign['destination_value']) : trim($campaign['queue_num']);

        // 1. Получаем имя и технологию транка из FreePBX
        $dial_trunk_string = "";
        $trunk_name = "";
        
        try {
            $stmt_t = $pdo_asterisk->prepare("SELECT tech, name FROM trunks WHERE trunkid = ? LIMIT 1");
            $stmt_t->execute([$trunk_id]);
            $trunk_data = $stmt_t->fetch(PDO::FETCH_ASSOC);
            if ($trunk_data) {
                $trunk_name = $trunk_data['name'];
                $dial_trunk_string = strtoupper($trunk_data['tech']) . "/" . $trunk_name;
            } else {
                continue; // Пропускаем кампанию, если транк удален во FreePBX
            }
        } catch (Exception $ex) {
            continue;
        }
        
        // 2. Жесткий физический контроль текущей загрузки конкретного транка этой кампании в Астериске
        $real_trunk_calls = getRealTrunkCallsCount($trunk_name);
        if ($real_trunk_calls >= $max_channels) {
            if ($show_debug) echo "[" . date('Y-m-d H:i:s') . "] [ТРАНК] Кампания [{$campaign['name']}] ждет. Транк {$dial_trunk_string} занят: {$real_trunk_calls} из {$max_channels}.\n";
            continue; 
        }

        // 3. Считаем активные вызовы именно этой кампании в нашей локальной базе данных
        $stmt_active = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE campaign_id = ? AND status = 1");
        $stmt_active->execute([$campaign_id]);
        $current_active_channels = (int)$stmt_active->fetchColumn();

        $effective_active = max($current_active_channels, $real_trunk_calls); // Берем максимальное значение безопасности
        if ($effective_active >= $max_channels) {
            continue; 
        }

        $free_slots = $max_channels - $effective_active;

        // 4. Интеллектуальный расчет лимитов набора (Очередь или Робот IVR)
        if ($dest_type === 'queue') {
            $free_agents_in_queue = getFreeAgentsCount($dest_value, $show_debug);

            // Если вообще нет ни одного свободного оператора (все говорят или оффлайн) — тогда стоим
            if ($free_agents_in_queue <= 0) {
                if ($show_debug) echo "[" . date('Y-m-d H:i:s') . "] [ОЧЕРЕДЬ] Кампания [{$campaign['name']}] ждет свободных операторов в очереди {$dest_value}...\n";
                continue;
            }
            
            // Корректный лимит: берем столько номеров, сколько у нас есть свободных слотов в транке, 
            // но не больше, чем физически сидит свободных операторов на линии.
            $fetch_limit = min($free_slots, $free_agents_in_queue);
        } else {
            // Для IVR-роботов ограничением выступает только емкость транка
            $fetch_limit = $free_slots;
        }

        if ($fetch_limit <= 0) continue;

        // 5. Выгружаем следующую порцию номеров строго для этой кампании
        $stmt_leads = $pdo->prepare("SELECT id, phone FROM leads WHERE campaign_id = :campaign_id AND status = 0 ORDER BY id ASC LIMIT :limit");
        $stmt_leads->bindValue(':campaign_id', $campaign_id, PDO::PARAM_INT);
        $stmt_leads->bindValue(':limit', $fetch_limit, PDO::PARAM_INT);
        $stmt_leads->execute();
        $leads_to_dial = $stmt_leads->fetchAll(PDO::FETCH_ASSOC);

        if (empty($leads_to_dial)) {
            // Номеров в очереди (0) нет. Проверяем, идут ли еще текущие звонки
            $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE campaign_id = ? AND status = 1");
            $stmt_check->execute([$campaign_id]);
            $still_calling = (int)$stmt_check->fetchColumn();
            
            if ($still_calling === 0) {
                // База пуста, звонков нет — переводим кампанию в статус "Завершена" (2)
                $stmt_comp = $pdo->prepare("UPDATE campaigns SET status = 2, updated_at = NOW() WHERE id = ?");
                $stmt_comp->execute([$campaign_id]);
                echo "[" . date('Y-m-d H:i:s') . "] [ФИНИШ] Кампания [{$campaign['name']}] полностью завершена!\n";
            }
            continue;
        }

        // 6. СТРЕЛЯЕМ ПАЧКОЙ ЗВОНКОВ В AMI (С предварительным Стоп-лист анализом)
        foreach ($leads_to_dial as $lead) {
            $lead_id = (int)$lead['id'];
            $phone   = trim($lead['phone']);

            // 🛑 1. ПРОВЕРКА НОМЕРА В СТОП-ЛИСТЕ ПЕРЕ ПЕРЕД ОРИДЖИНЕЙТОМ
            $stmt_bl = $pdo->prepare("SELECT COUNT(*) FROM blacklist WHERE phone = ?");
            $stmt_bl->execute([$phone]);
            $is_blacklisted = (int)$stmt_bl->fetchColumn();

            if ($is_blacklisted > 0) {
                // Маркируем лид как отфильтрованный стоп-листом (5)
                $stmt_skip = $pdo->prepare("UPDATE leads SET status = 5, updated_at = NOW() WHERE id = ?");
                $stmt_skip->execute([$lead_id]);
                
                echo "[" . date('Y-m-d H:i:s') . "] [СТОП-ЛИСТ] Номер {$phone} (Лид #{$lead_id}) заблокирован Стоп-листом. Пропущен.\n";
                continue; // Отсекаем Originate, переходим к следующему лиду
            }

            // Номера в стоп-листе нет — временно переводим в статус "Звоним" (1)
            $stmt_up = $pdo->prepare("UPDATE leads SET status = 1, updated_at = NOW() WHERE id = ?");
            $stmt_up->execute([$lead_id]);

            if ($dest_type === 'ivr') {
                $context = "ivr-" . $dest_value; // Направление на робота
                $exten   = "s";
            } else {
                $context = "dialer-priority-queue"; // Направление на операторов
                $exten   = "s";
            }

            // Формируем AMI пакет инициализации вызова
            $originate_packet = "Action: Originate\r\n";
            $originate_packet .= "Channel: Local/{$phone}@dialer-outbound\r\n"; 
            $originate_packet .= "Context: {$context}\r\n";          
            $originate_packet .= "Exten: {$exten}\r\n";
            $originate_packet .= "Priority: 1\r\n";
            $originate_packet .= "Timeout: " . ((int)$campaign['ring_time'] * 1000) . "\r\n"; 
            $originate_packet .= "CallerID: VoxDial <{$phone}>\r\n";
            $originate_packet .= "Account: dl-{$campaign_id}-{$lead_id}\r\n";
	    //$originate_packet .= "Account: dialer-lead-{$lead_id}\r\n"; // Идентификатор для CDR аналитики
            $originate_packet .= "Async: true\r\n";
            $originate_packet .= "Variable: LEAD_ID={$lead_id},QUEUE_NUM={$dest_value},RING_TIME={$campaign['ring_time']},DIAL_TRUNK={$dial_trunk_string}\r\n\r\n";

            // 🛑 2. БЕЗОПАСНАЯ ОТПРАВКА С ФИКСАЦИЕЙ КРАША СОКЕТА (Broken pipe)
            $send_result = @fputs($socket, $originate_packet);
            
            if ($send_result === false) {
                // Пакет не улетел — возвращаем лид обратно на исходную (status=0)
                $stmt_rollback = $pdo->prepare("UPDATE leads SET status = 0, updated_at = NOW() WHERE id = ?");
                $stmt_rollback->execute([$lead_id]);
                echo "[" . date('Y-m-d H:i:s') . "] [КРИТИКА] AMI сокет оборвался (Broken pipe). Лид #{$lead_id} сохранен и возвращен в очередь.\n";
                
                // Входим в цикл экстренного восстановления сессии прямо на ходу
                while (!connectToAMI()) {
                    sleep(5);
                }
                
                $any_dial_done = true;
                break; // Ломаем внутренний цикл по этой кампании, чтобы пойти на новый чистый круг опроса
            }

            usleep(30000); // Микропауза 30мс между пакетами для разгрузки стека AMI Asterisk

            echo "[" . date('Y-m-d H:i:s') . "] Комп. [{$campaign['name']}]: Звонок летит на {$phone} (Канал транка: " . ($real_trunk_calls + 1) . ")\n";
            $real_trunk_calls++;
            $any_dial_done = true;
        }
    }

    if ($any_dial_done) {
        sleep(1); // Легкий таймаут удержания темпа после набора пачки, чтобы поберечь процессор
    }
}

// =========================================================================
// ВСПУМОГАТЕЛЬНЫЕ ФУНКЦИИ ВЗАИМОДЕЙСТВИЯ С CONSOLE ASTERISK CLI
// =========================================================================

function getRealTrunkCallsCount($trunk_name) {
    $asterisk_bin = app_config('asterisk.bin');
    $output = shell_exec(escapeshellcmd($asterisk_bin) . " -rx " . escapeshellarg('core show channels'));
    if (empty($output)) return 0;

    $lines = explode("\n", $output);
    $active_calls = 0;
    $search_pattern = trim(strtolower($trunk_name));

    foreach ($lines as $line) {
        $line_lower = strtolower($line);
        if (strpos($line_lower, $search_pattern) !== false) {
            if (strpos($line_lower, 'active channel') === false && strpos($line_lower, 'active call') === false) {
                $active_calls++;
            }
        }
    }
    return $active_calls;
}

function getFreeAgentsCount($queue, $show_debug) {
    $asterisk_bin = app_config('asterisk.bin');
    $output = shell_exec(escapeshellcmd($asterisk_bin) . " -rx " . escapeshellarg("queue show $queue"));
    if (empty($output)) return 0;
    
    $lines = explode("\n", $output);
    $total_free = 0;
    
    foreach ($lines as $line) {
        $line_lower = strtolower($line);
        
        // Проверяем, что это строка оператора (содержит Local/, SIP/ или PJSIP/)
        if (strpos($line_lower, 'local/') !== false || strpos($line_lower, 'sip/') !== false || strpos($line_lower, 'pjsip/') !== false) {
            
            // Игнорируем тех, кто ушел на перерыв (paused) или отключен (unavailable)
            if (strpos($line_lower, 'paused') !== false) continue;
            if (strpos($line_lower, 'unavailable') !== false) continue;
            
            // Если оператор имеет статус строго "not in use" (свободен и готов принимать звонки)
            if (strpos($line_lower, 'not in use') !== false) {
                $total_free++;
            } 
        }
    }
    
    if ($show_debug) {
        echo "[" . date('Y-m-d H:i:s') . "] [АНАЛИЗ ОЧЕРЕДИ] Очередь {$queue}: найдено свободно операторов — {$total_free}.\n";
    }
    
    return $total_free;
}
?>

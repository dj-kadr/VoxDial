<?php
// dialer.php — Мультипоточная версия: одновременный запуск множества кампаний (Queue / IVR) с поддержкой Стоп-листа
require_once __DIR__ . '/config.php';

set_time_limit(0);

$statuses = ['paused' => 0, 'active' => 1, 'completed' => 2];

try {
    $pdo = db_pdo('dialer');
    $pdo_asterisk = db_pdo('asterisk');
} catch (PDOException $e) {
    die("[" . date('Y-m-d H:i:s') . "] Ошибка подключения к БД: " . $e->getMessage() . "\n");
}

$ami = app_config('ami');
$socket = @fsockopen($ami['host'], (int)$ami['port'], $errno, $errstr, (int)$ami['timeout']);
if (!$socket) {
    die("[" . date('Y-m-d H:i:s') . "] AMI Connection failed: $errstr ($errno)\n");
}

fgets($socket, 1024);
usleep(100000); 

fputs($socket, "Action: Login\r\nUsername: {$ami['user']}\r\nSecret: {$ami['pass']}\r\n\r\n");
usleep(100000); 

$response = "";
while (!feof($socket)) {
    $line = fgets($socket, 1024);
    $response .= $line;
    if (trim($line) == "") break; 
}

if (strpos($response, "Response: Success") === false) {
    die("[" . date('Y-m-d H:i:s') . "] AMI Authentication Failed!\n");
}

echo "[" . date('Y-m-d H:i:s') . "] Демон VoxDial (МУЛЬТИПОТОК + СТОП-ЛИСТ) успешно запущен.\n";
stream_set_blocking($socket, false);

$last_debug_time = 0;

while (true) {
    usleep(500000); // Пауза полсекунды между общими кругами опроса всей системы

    while ($ami_line = fgets($socket, 1024)) { /* Чистим буфер AMI */ }

    $show_debug = (time() - $last_debug_time >= 10);
    if ($show_debug) $last_debug_time = time();
    // =========================================================================
    // ⏰ АВТОМАТИЧЕСКИЙ ПЛАНИРОВЩИК: КОНТРОЛЬ ВРЕМЕНИ СТАРТА И ПАУЗЫ
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
    // ЗАГРУЖАЕМ ВСЕ АКТИВНЫЕ КАМПАНИИ (Убрали LIMIT 1)
    $stmt_camp = $pdo->prepare("SELECT * FROM campaigns WHERE status = :status");
    $stmt_camp->execute(['status' => $statuses['active']]);
    $active_campaigns = $stmt_camp->fetchAll(PDO::FETCH_ASSOC);

    if (empty($active_campaigns)) {
        if ($show_debug) echo "[" . date('Y-m-d H:i:s') . "] [ДЕБАГ] Нет запущенных кампаний.\n";
        continue; 
    }

    // Буфер, чтобы за один круг цикла не долбить провайдера, если запущено много кампаний
    $any_dial_done = false;

    // ПЕРЕБИРАЕМ КАЖДУЮ АКТИВНУЮ КАМПАНИЮ ПАРАЛЛЕЛЬНО
    foreach ($active_campaigns as $campaign) {
        $campaign_id = (int)$campaign['id'];
        $trunk_id = (int)$campaign['trunk_id'];
        $max_channels = isset($campaign['channel_limit']) ? (int)$campaign['channel_limit'] : 5;
        
        $dest_type = isset($campaign['destination_type']) ? $campaign['destination_type'] : 'queue';
        $dest_value = (!empty($campaign['destination_value'])) ? trim($campaign['destination_value']) : trim($campaign['queue_num']);

        // 1. Получаем имя и тех транка из FreePBX
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
                continue; // Пропускаем сбойную кампанию
            }
        } catch (Exception $ex) {
            continue;
        }
        
        // 2. Жесткий физический контроль загрузки конкретного транка этой кампании
        $real_trunk_calls = getRealTrunkCallsCount($trunk_name);
        if ($real_trunk_calls >= $max_channels) {
            if ($show_debug) echo "[" . date('Y-m-d H:i:s') . "] [ТРАНК] Кампания [{$campaign['name']}] временно стоит. Транк {$dial_trunk_string} занят: {$real_trunk_calls} из {$max_channels}.\n";
            continue; 
        }

        // 3. Считаем active каналы именно этой кампании в локальной базе
        $stmt_active = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE campaign_id = ? AND status = 1");
        $stmt_active->execute([$campaign_id]);
        $current_active_channels = (int)$stmt_active->fetchColumn();

        $effective_active = max($current_active_channels, $real_trunk_calls);
        if ($effective_active >= $max_channels) {
            continue; 
        }

        $free_slots = $max_channels - $effective_active;

        // 4. Балансировка по типу направления (Очередь или IVR)
        if ($dest_type === 'queue') {
            $free_agents_in_queue = getFreeAgentsCount($dest_value, $show_debug);
            $real_allowed_agents = $free_agents_in_queue - $current_active_channels;

            if ($real_allowed_agents <= 0) {
                if ($show_debug) echo "[" . date('Y-m-d H:i:s') . "] [ОЧЕРЕДЬ] Кампания [{$campaign['name']}] ждет операторов в очереди {$dest_value}...\n";
                continue;
            }
            $fetch_limit = min($free_slots, $real_allowed_agents);
        } else {
            // Для IVR ограничением выступает только свободный кусок лимита транка
            $fetch_limit = $free_slots;
        }

        if ($fetch_limit <= 0) continue;

        // 5. Берем следующую порцию номеров строго для этой кампании
        $stmt_leads = $pdo->prepare("SELECT id, phone FROM leads WHERE campaign_id = :campaign_id AND status = 0 ORDER BY id ASC LIMIT :limit");
        $stmt_leads->bindValue(':campaign_id', $campaign_id, PDO::PARAM_INT);
        $stmt_leads->bindValue(':limit', $fetch_limit, PDO::PARAM_INT);
        $stmt_leads->execute();
        $leads_to_dial = $stmt_leads->fetchAll(PDO::FETCH_ASSOC);

        if (empty($leads_to_dial)) {
            // Если номеров со статусом 0 больше нет, проверяем, завершена ли кампания полностью
            $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE campaign_id = ? AND status = 1");
            $stmt_check->execute([$campaign_id]);
            $still_calling = (int)$stmt_check->fetchColumn();
            
            if ($still_calling === 0) {
                // Если никто уже не звонит и базы нет — переводим в статус "Завершена"
                $stmt_comp = $pdo->prepare("UPDATE campaigns SET status = 2, updated_at = NOW() WHERE id = ?");
                $stmt_comp->execute([$campaign_id]);
                echo "[" . date('Y-m-d H:i:s') . "] [ФИНИШ] Кампания [{$campaign['name']}] успешно завершена!\n";
            }
            continue;
        }

        // 6. Стреляем пачкой звонков в AMI (С предварительным Стоп-лист анализом)
        foreach ($leads_to_dial as $lead) {
            $lead_id = (int)$lead['id'];
            $phone   = trim($lead['phone']);

            // 🛑 ПРОВЕРКА НОМЕРА В СТОП-ЛИСТЕ ПЕРЕД ИНИЦИАЛИЗАЦИЕЙ ВЫЗОВА
            $stmt_bl = $pdo->prepare("SELECT COUNT(*) FROM blacklist WHERE phone = ?");
            $stmt_bl->execute([$phone]);
            $is_blacklisted = (int)$stmt_bl->fetchColumn();

            if ($is_blacklisted > 0) {
                // Если номер найден в черном списке — жестко маркируем его как Пропущен/Сброс (5)
                $stmt_skip = $pdo->prepare("UPDATE leads SET status = 5, updated_at = NOW() WHERE id = ?");
                $stmt_skip->execute([$lead_id]);
                
                echo "[" . date('Y-m-d H:i:s') . "] [СТОП-ЛИСТ] Номер {$phone} (Лид #{$lead_id}) найден в черном списке. Пропущен без набора.\n";
                continue; // Полностью отсекаем выполнение Originate и переходим к следующему лиду
            }

            // Если номера в стоп-листе нет — переводим в статус "Звоним" и шлем пакет
            $stmt_up = $pdo->prepare("UPDATE leads SET status = 1, updated_at = NOW() WHERE id = ?");
            $stmt_up->execute([$lead_id]);

            if ($dest_type === 'ivr') {
                $context = "ivr-" . $dest_value; 
                $exten   = "s";
            } else {
                $context = "dialer-priority-queue";
                $exten   = "s";
            }

            $originate_packet = "Action: Originate\r\n";
            $originate_packet .= "Channel: Local/{$phone}@dialer-outbound\r\n"; 
            $originate_packet .= "Context: {$context}\r\n";          
            $originate_packet .= "Exten: {$exten}\r\n";
            $originate_packet .= "Priority: 1\r\n";
            $originate_packet .= "Timeout: " . ((int)$campaign['ring_time'] * 1000) . "\r\n"; 
            $originate_packet .= "CallerID: VoxDial <{$phone}>\r\n";
            $originate_packet .= "Account: dialer-lead-{$lead_id}\r\n";         
            $originate_packet .= "Async: true\r\n";
            $originate_packet .= "Variable: LEAD_ID={$lead_id},QUEUE_NUM={$dest_value},RING_TIME={$campaign['ring_time']},DIAL_TRUNK={$dial_trunk_string}\r\n\r\n";

            fputs($socket, $originate_packet);
            usleep(30000); // 30мс микропауза между отправкой пакетов для стабильности AMI

            echo "[" . date('Y-m-d H:i:s') . "] Комп. [{$campaign['name']}]: Звонок летит на {$phone} (Канал транка: " . ($real_trunk_calls + 1) . ")\n";
            $real_trunk_calls++;
            $any_dial_done = true;
        }
    }

    if ($any_dial_done) {
        sleep(1); // Легкий таймаут удержания темпа после набора пачки, чтобы поберечь процессор
    }
}

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
        if (strpos($line_lower, 'local/') !== false || strpos($line_lower, 'sip/') !== false || strpos($line_lower, 'pjsip/') !== false) {
            preg_match('/([0-9]+)/', $line_lower, $num_matches);
            $agent_ext = isset($num_matches[1]) ? $num_matches[1] : '???';
            
            if (strlen($agent_ext) > 4) continue;
            if (strpos($line_lower, 'paused') !== false) continue;
            
            if (strpos($line_lower, 'not in use') !== false) {
                $total_free++;
            } 
        }
    }
    return $total_free;
}
?>

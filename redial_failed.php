<?php
// redial_failed.php — Создание копии кампании с суффиксом R (Защита от копирования успешных звонков)
require_once __DIR__ . '/config.php';

$campaign_id = 0;
if (isset($_GET['id'])) {
    $campaign_id = (int)$_GET['id'];
} elseif (isset($_REQUEST['id'])) {
    $campaign_id = (int)$_REQUEST['id'];
}

if ($campaign_id <= 0) {
    die("Ошибка: Некорректный ID кампании.");
}

try {
    $pdo = db_pdo('dialer');
    $pdo_cdr = db_pdo('cdr');

    // 1. Получаем данные старой кампании
    $stmt = $pdo->prepare("SELECT * FROM campaigns WHERE id = ?");
    $stmt->execute([$campaign_id]);
    $old_campaign = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$old_campaign) {
        die("Ошибка: Исходная кампания не найдена.");
    }

    // Формируем компактное имя без пробела
    $new_name = $old_campaign['name'] . 'R';

    // 2. Создаем новую кампанию-дубликат в статусе Пауза (0)
    $sql_create_camp = "INSERT INTO campaigns (
                            name, queue_num, destination_type, destination_value, 
                            trunk_id, channel_limit, ring_time, min_success_duration, 
                            max_retries, retry_time, start_immediately, 
                            scheduled_start_time, scheduled_pause_time, status
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)";

    $stmt_create = $pdo->prepare($sql_create_camp);
    $stmt_create->execute([
        $new_name,
        $old_campaign['queue_num'],
        $old_campaign['destination_type'],
        $old_campaign['destination_value'],
        $old_campaign['trunk_id'],
        $old_campaign['channel_limit'],
        $old_campaign['ring_time'],
        $old_campaign['min_success_duration'],
        $old_campaign['max_retries'],
        $old_campaign['retry_time'],
        $old_campaign['start_immediately'],
        $old_campaign['scheduled_start_time'],
        $old_campaign['scheduled_pause_time']
    ]);

    // Получаем ID новой кампании
    $new_campaign_id = $pdo->lastInsertId();

    // 3. Выгребаем все лиды старой кампании со статусом 5 (Сбои/Сбросы)
    $stmt_leads = $pdo->prepare("SELECT id, phone, name FROM leads WHERE campaign_id = ? AND status = 5");
    $stmt_leads->execute([$campaign_id]);
    $old_leads = $stmt_leads->fetchAll(PDO::FETCH_ASSOC);

    if (empty($old_leads)) {
        header("Location: index.php?msg=nocopy");
        exit;
    }

    // 4. Подготавливаем запрос на вставку в новую базу
    $sql_insert_lead = "INSERT INTO leads (campaign_id, phone, name, status) VALUES (?, ?, ?, 0)";
    $stmt_insert_lead = $pdo->prepare($sql_insert_lead);

    // ИСПРАВЛЕННЫЙ SQL-ЗАПРОС: Считаем сколько всего записей и сколько из них успешных (ANSWERED)
    $query_cdr_check = "SELECT 
                            COUNT(*) as total_calls,
                            SUM(CASE WHEN disposition = 'ANSWERED' THEN 1 ELSE 0 END) as answered_calls
                        FROM asteriskcdrdb.cdr 
                        WHERE accountcode = :accountcode";
    $stmt_cdr_check = $pdo_cdr->prepare($query_cdr_check);

    $copied_count = 0;

    foreach ($old_leads as $lead) {
        $unique_accountcode = "dl-" . $campaign_id . "-" . (int)$lead['id'];

        // Проверяем полную историю этого лида в Asterisk CDR
        $stmt_cdr_check->execute(['accountcode' => $unique_accountcode]);
        $cdr_stats = $stmt_cdr_check->fetch(PDO::FETCH_ASSOC);

        $total_calls = (int)$cdr_stats['total_calls'];
        $answered_calls = (int)$cdr_stats['answered_calls'];

        // 🚨 КРИТИЧЕСКИЙ ФИЛЬТР: Копируем ТОЛЬКО если попытки были (total > 0), 
        // но среди них НЕТ ни одного успешного ответа (answered === 0)
        if ($total_calls > 0 && $answered_calls === 0) {
            $stmt_insert_lead->execute([
                $new_campaign_id,
                $lead['phone'],
                $lead['name']
            ]);
            $copied_count++;
        }
    }

    // Если все лиды отфильтровались как успешные и копировать нечего — удаляем пустую кампанию R
    if ($copied_count === 0) {
        $pdo->prepare("DELETE FROM campaigns WHERE id = ?")->execute([$new_campaign_id]);
        header("Location: index.php?msg=nodeads");
        exit;
    }

    header("Location: index.php?success=1");
    exit;

} catch (PDOException $e) {
    die("Критическая ошибка базы данных при клонировании: " . $e->getMessage());
}
?>

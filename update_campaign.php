<?php
// update_campaign.php — Обработчик сохранения измененных параметров кампании и планировщика
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo = db_pdo('dialer');
    } catch (PDOException $e) {
        die("Ошибка подключения к БД: " . $e->getMessage());
    }

    $id = (int)$_POST['id'];
    $channel_limit = (int)$_POST['channel_limit'];
    $ring_time = (int)$_POST['ring_time'];
    $min_success_duration = (int)$_POST['min_success_duration'];
    $max_retries = (int)$_POST['max_retries'];
    $retry_time = (int)$_POST['retry_time'];

    // Сбор параметров планировщика
    $start_immediately = isset($_POST['start_immediately']) ? 1 : 0;
    $scheduled_start_time = (!empty($_POST['scheduled_start_time'])) ? $_POST['scheduled_start_time'] . ':00' : null;
    $scheduled_pause_time = (!empty($_POST['scheduled_pause_time'])) ? $_POST['scheduled_pause_time'] . ':00' : null;

    if ($id > 0) {
        $sql = "UPDATE campaigns SET 
                    channel_limit = ?, 
                    ring_time = ?, 
                    min_success_duration = ?, 
                    max_retries = ?, 
                    retry_time = ?,
                    start_immediately = ?,
                    scheduled_start_time = ?,
                    scheduled_pause_time = ?
                WHERE id = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $channel_limit,
            $ring_time,
            $min_success_duration,
            $max_retries,
            $retry_time,
            $start_immediately,
            $scheduled_start_time,
            $scheduled_pause_time,
            $id
        ]);
        
        // Дополнительно: Если администратор переключил кампанию в режим "По расписанию" прямо сейчас,
        // и текущее время сервера находится вне рабочего окна расписания,
        // ставим статус кампании на 0 (Пауза), чтобы демон её не набирал.
        if ($start_immediately === 0 && !empty($scheduled_start_time) && !empty($scheduled_pause_time)) {
            $current_time = date('H:i:00');
            if ($current_time < $scheduled_start_time || $current_time >= $scheduled_pause_time) {
                $pdo->prepare("UPDATE campaigns SET status = 0 WHERE id = ?")->execute([$id]);
            }
        }
    }
}

// Возвращаем администратора на дашборд
header("Location: index.php");
exit;

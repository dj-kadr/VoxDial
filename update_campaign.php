<?php
// update_campaign.php — Обработчик сохранения измененных параметров
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

    if ($id > 0) {
        $sql = "UPDATE campaigns SET 
                    channel_limit = ?, 
                    ring_time = ?, 
                    min_success_duration = ?, 
                    max_retries = ?, 
                    retry_time = ? 
                WHERE id = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $channel_limit,
            $ring_time,
            $min_success_duration,
            $max_retries,
            $retry_time,
            $id
        ]);
    }
}

// Возвращаем администратора на дашборд
header("Location: index.php");
exit;

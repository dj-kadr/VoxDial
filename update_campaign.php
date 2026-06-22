<?php
// update_campaign.php — Обработчик сохранения измененных параметров кампании
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("Ошибка: Неверный метод запроса.");
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$trunk_id = isset($_POST['trunk_id']) ? (int)$_POST['trunk_id'] : 0; // ЧИТАЕМ ТРАНК ИЗ ФОРМЫ
$channel_limit = isset($_POST['channel_limit']) ? (int)$_POST['channel_limit'] : 5;
$ring_time = isset($_POST['ring_time']) ? (int)$_POST['ring_time'] : 30;
$min_success_duration = isset($_POST['min_success_duration']) ? (int)$_POST['min_success_duration'] : 10;
$max_retries = isset($_POST['max_retries']) ? (int)$_POST['max_retries'] : 1;
$retry_time = isset($_POST['retry_time']) ? (int)$_POST['retry_time'] : 60;

$start_immediately = isset($_POST['start_immediately']) ? 1 : 0;
$scheduled_start_time = !empty($_POST['scheduled_start_time']) ? $_POST['scheduled_start_time'] : null;
$scheduled_pause_time = !empty($_POST['scheduled_pause_time']) ? $_POST['scheduled_pause_time'] : null;

if ($id <= 0 || $trunk_id <= 0) {
    die("Ошибка: Некорректные данные кампании или транка.");
}

try {
    $pdo = db_pdo('dialer');
    
    // ДОБАВИЛИ trunk_id = ? В SQL ЗАПРОС
    $sql = "UPDATE campaigns SET 
                trunk_id = ?, 
                channel_limit = ?, 
                ring_time = ?, 
                min_success_duration = ?, 
                max_retries = ?, 
                retry_time = ?, 
                start_immediately = ?, 
                scheduled_start_time = ?, 
                scheduled_pause_time = ?,
                updated_at = NOW()
            WHERE id = ?";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $trunk_id, // Передаем ID нового транка в базу
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

    // Возвращаемся на главную страницу после успешного сохранения
    header("Location: index.php?success=1");
    exit;

} catch (PDOException $e) {
    die("Ошибка сохранения в базу данных: " . $e->getMessage());
}
?>

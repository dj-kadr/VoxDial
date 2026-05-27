<?php
// callback.php — Автоматическая фиксация результатов звонка из диалплана
require_once __DIR__ . '/config.php';

// Бронебойное получение аргументов: если не переданы, берем дефолт
$lead_id  = isset($argv[1]) ? (int)$argv[1] : 0;
$status   = isset($argv[2]) ? (int)$argv[2] : 4; // По дефолту "Нет ответа"
$duration = isset($argv[3]) ? (int)$argv[3] : 0; // По дефолту 0 секунд

// Если ID лида вообще не прилетел, то обновлять нечего — пишем лог и выходим
if ($lead_id === 0) {
    file_put_contents('/tmp/dialer_callback_error.log', date('Y-m-d H:i:s') . " Ошибка: Вызов без ID лида. Аргументы: " . json_encode($argv) . "\n", FILE_APPEND);
    exit;
}

try {
    $pdo = db_pdo('dialer');

    // Если звонок отвечен (статус 2), проверяем минимальный KPI длительности разговора
	if ($status === 2) {
        $stmt = $pdo->prepare("
            SELECT c.min_success_duration 
            FROM leads l 
            JOIN campaigns c ON l.campaign_id = c.id 
            WHERE l.id = ?
        ");
        $stmt->execute([$lead_id]);
        $min_dur = $stmt->fetchColumn();

        // Если поговорили меньше положенного (например, сбросил автоответчик), ставим "Сбой/Сброс" (5) вместо Успеха
        if ($min_dur !== false && $duration < (int)$min_dur) {
            $status = 5; 
        }
    }

    // Обновляем статус лида и освобождаем линию для dialer.php
    $stmt = $pdo->prepare("UPDATE leads SET status = ? WHERE id = ?");
    $stmt->execute([$status, $lead_id]);

} catch (PDOException $e) {
    file_put_contents('/tmp/dialer_callback_error.log', date('Y-m-d H:i:s') . " Ошибка БД: " . $e->getMessage() . "\n", FILE_APPEND);
}

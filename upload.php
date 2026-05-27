<?php
// upload.php — Полная адаптация под очереди и IVR-меню без нарушения импорта файлов
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/SimpleXLSX.php'; // Подключаем библиотеку
use Shuchkin\SimpleXLSX; // Используем пространство имен библиотеки

$pdo = db_pdo('dialer');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $campaign_name = $_POST['campaign_name'];
    $trunk_id = (int)$_POST['trunk_id'];
    
    $channel_limit = (int)$_POST['channel_limit'];
    $ring_time = (int)$_POST['ring_time'];
    $min_success_duration = (int)$_POST['min_success_duration'];
    $max_retries = (int)$_POST['max_retries'];
    $retry_time = (int)$_POST['retry_time'];
    
    // --- ИНТЕЛЛЕКТУАЛЬНЫЙ РАЗБОР НАПРАВЛЕНИЯ (QUEUE или IVR) ---
    $dest_type = isset($_POST['destination_type']) ? $_POST['destination_type'] : 'queue';

    if ($dest_type === 'queue') {
        $dest_value = isset($_POST['queue_num']) ? trim($_POST['queue_num']) : '';
        $queue_num  = $dest_value; // Дублируем в старое поле для обратной совместимости
    } else {
        $dest_value = isset($_POST['ivr_id']) ? trim($_POST['ivr_id']) : '';
        $queue_num  = ''; // Защита от MySQL Column cannot be null: пишем пустую строку
    }
    
    // Создаем кампанию (Добавлены новые колонки в структуру позиционного запроса)
    $sql = "INSERT INTO campaigns (
                name, queue_num, trunk_id, destination_type, destination_value, 
                status, channel_limit, ring_time, min_success_duration, max_retries, retry_time
            ) VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?)";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $campaign_name, 
        $queue_num, 
        $trunk_id, 
        $dest_type, 
        $dest_value, 
        $channel_limit, 
        $ring_time, 
        $min_success_duration, 
        $max_retries, 
        $retry_time
    ]);
    
    $campaign_id = $pdo->lastInsertId();
    
    // --- ТВОЙ РОДНОЙ КУБ ПАРСИНГА И ЗАКЛАДКИ ЛИДОВ (БЕЗ ИЗМЕНЕНИЙ) ---
    $file = $_FILES['file']['tmp_name'];
    $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
    
    $pdo->beginTransaction();
    $insert_stmt = $pdo->prepare("INSERT INTO leads (campaign_id, phone, name) VALUES (?, ?, ?)");

    if ($ext === 'xlsx') {
        // --- ОБРАБОТКА XLSX ---
        if ($xlsx = SimpleXLSX::parse($file)) {
            foreach ($xlsx->rows() as $row) {
                if (!isset($row[0]) || empty($row[0])) continue;
                
                $phone = preg_replace('/[^0-9]/', '', $row[0]);
                $name = isset($row[1]) ? trim($row[1]) : null;
                
                if (!empty($phone)) {
                    $insert_stmt->execute([$campaign_id, $phone, $name]);
                }
            }
        }
    } elseif ($ext === 'csv') {
        // --- ОБРАБОТКА CSV ---
        if (($handle = fopen($file, "r")) !== FALSE) {
            while (($data = fgetcsv($handle, 1000, ";")) !== FALSE) {
                if (!isset($data[0]) || empty($data[0])) continue;
                
                $phone = preg_replace('/[^0-9]/', '', $data[0]);
                $name = isset($data[1]) ? trim($data[1]) : null;
                
                if (!empty($phone)) {
                    $insert_stmt->execute([$campaign_id, $phone, $name]);
                }
            }
            fclose($handle);
        }
    }
    
    $pdo->commit();
}

header("Location: index.php");
exit;

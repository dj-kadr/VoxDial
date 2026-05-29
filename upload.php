<?php
// upload.php — Полная адаптация под очереди, IVR-меню и Планировщик времени
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/SimpleXLSX.php'; // Подключаем библиотеку
use Shuchkin\SimpleXLSX; // Используем пространство имен библиотеки

date_default_timezone_set('Europe/Kyiv');

try {
    $pdo = db_pdo('dialer'); // Используем твою родную функцию из config.php
} catch (PDOException $e) {
    die("Ошибка подключения к локальной базе диалера: " . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $campaign_name = $_POST['campaign_name'];
    $trunk_id = (int)$_POST['trunk_id'];
    
    $channel_limit = (int)$_POST['channel_limit'];
    $ring_time = (int)$_POST['ring_time'];
    $min_success_duration = (int)$_POST['min_success_duration'];
    $max_retries = (int)$_POST['max_retries'];
    $retry_time = (int)$_POST['retry_time'];
    
    // --- 1. ИНТЕЛЛЕКТУАЛЬНЫЙ РАЗБОР НАПРАВЛЕНИЯ (QUEUE или IVR) ---
    $dest_type = isset($_POST['destination_type']) ? $_POST['destination_type'] : 'queue';

    if ($dest_type === 'queue') {
        $dest_value = isset($_POST['queue_num']) ? trim($_POST['queue_num']) : '';
        $queue_num  = $dest_value; // Дублируем в старое поле для обратной совместимости
    } else {
        $dest_value = isset($_POST['ivr_id']) ? trim($_POST['ivr_id']) : '';
        $queue_num  = ''; // Защита от MySQL Column cannot be null
    }
    
    // --- 2. ПАРАМЕТРЫ АВТОМАТИЧЕСКОГО ПЛАНИРОВЩИКА ВРЕМЕНИ ---
    $start_immediately = isset($_POST['start_immediately']) ? 1 : 0;
    $scheduled_start_time = (!empty($_POST['scheduled_start_time'])) ? $_POST['scheduled_start_time'] . ':00' : null;
    $scheduled_pause_time = (!empty($_POST['scheduled_pause_time'])) ? $_POST['scheduled_pause_time'] . ':00' : null;

    // Вычисляем стартовый статус кампании:
    // Если "Начать немедленно" — ставим 1 (Активна), если по расписанию — 0 (Пауза, робот включит сам)
    $initial_status = $start_immediately ? 1 : 0;

    // --- 3. СОЗДАНИЕ КАМПАНИИ (Обновленный позиционный SQL-запрос со всеми 14 полями) ---
    $sql = "INSERT INTO campaigns (
                name, queue_num, trunk_id, destination_type, destination_value, 
                status, channel_limit, ring_time, min_success_duration, max_retries, retry_time,
                start_immediately, scheduled_start_time, scheduled_pause_time, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $campaign_name, 
        $queue_num, 
        $trunk_id, 
        $dest_type, 
        $dest_value, 
        $initial_status, // Динамический статус вместо захардкоженного 0
        $channel_limit, 
        $ring_time, 
        $min_success_duration, 
        $max_retries, 
        $retry_time,
        $start_immediately,    // Записываем флаг немедленного старта
        $scheduled_start_time, // Время старта
        $scheduled_pause_time  // Время паузы
    ]);
    
    $campaign_id = $pdo->lastInsertId();
    
    // --- 4. ТВОЙ РОДНОЙ КУБ ПАРСИНГА И ЗАКЛАДКИ ЛИДОВ (БЕЗ ИЗМЕНЕНИЙ) ---
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

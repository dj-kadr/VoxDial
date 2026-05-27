<?php
// action.php — Переключение статусов обзвона
require_once __DIR__ . '/config.php';

try {
    $pdo = db_pdo('dialer');
} catch (PDOException $e) {
    die("Ошибка подключения к БД: " . $e->getMessage());
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$action = isset($_GET['act']) ? $_GET['act'] : '';

if ($id > 0 && !empty($action)) {
    if ($action === 'start') {
        $stmt = $pdo->prepare("UPDATE campaigns SET status = 1 WHERE id = ?");
        $stmt->execute([$id]);
    } elseif ($action === 'pause') {
        $stmt = $pdo->prepare("UPDATE campaigns SET status = 0 WHERE id = ?");
        $stmt->execute([$id]);
    } elseif ($action === 'delete') {
        // Удаляем саму кампанию
        $stmt = $pdo->prepare("DELETE FROM campaigns WHERE id = ?");
        $stmt->execute([$id]);
    }
}

header("Location: index.php");
exit;

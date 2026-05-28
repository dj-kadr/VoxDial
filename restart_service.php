<?php
// restart_service.php — Безопасный перезапуск фонового демона из веб-интерфейса
require_once __DIR__ . '/config.php';

// Выполняем системную команду через sudo (права на которую мы дали в visudo)
$service_name = app_config('services.dialer');
shell_exec("sudo /usr/bin/systemctl restart " . escapeshellarg($service_name));

// Возвращаем администратора обратно на главную панель управления
header("Location: index.php");
exit;

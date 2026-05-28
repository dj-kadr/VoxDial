<?php
// agents.php — Компактный мониторинг операторов (Wallboard со сводной статистикой)
require_once __DIR__ . '/config.php';

$output = shell_exec(escapeshellcmd(app_config('asterisk.bin')) . " -rx " . escapeshellarg('queue show'));
$queues = [];

if (!empty($output)) {
    $lines = explode("\n", $output);
    $current_queue = null;

    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;

        if (preg_match('/^(\d+)\s+has\s+(\d+)\s+calls/', $line, $matches)) {
            $current_queue = $matches[1];
            $queues[$current_queue] = [
                'calls' => (int)$matches[2],
                'members' => [],
                // Инициализируем суммарные счетчики для шапки
                'count_free' => 0,
                'count_talk' => 0,
                'count_offline' => 0
            ];
            continue;
        }

        if ($current_queue && preg_match('/(Local|SIP|PJSIP)\/(\d+)/i', $line, $matches)) {
            $agent_num = $matches[2]; 
            $status_text = 'Неизвестно';
            $status_class = 'border-secondary text-secondary';
            $dot_class = 'bg-secondary';
            $icon = 'fa-question-circle';
            $status_type = 'unknown'; // Для внутренней калькуляции

            $safe_line = strtolower($line);
            $safe_line = str_replace('ringinuse', '', $safe_line);

            if (strpos($safe_line, 'unavailable') !== false) {
                $status_text = 'Офлайн';
                $status_class = 'border-danger text-danger';
                $dot_class = 'bg-danger';
                $icon = 'fa-phone-slash';
                $status_type = 'offline';
            } elseif (strpos($safe_line, 'paused') !== false) {
                $status_text = 'Пауза';
                $status_class = 'border-warning text-warning-emphasis bg-warning-subtle';
                $dot_class = 'bg-warning';
                $icon = 'fa-coffee';
                $status_type = 'offline'; // Паузу логично отнести к неактивным на линии
            } elseif (strpos($safe_line, 'not in use') !== false) {
                $status_text = 'Свободен';
                $status_class = 'border-success text-success';
                $dot_class = 'bg-success';
                $icon = 'fa-circle-check';
                $status_type = 'free';
            } elseif (strpos($safe_line, 'in use') !== false) {
                $status_text = 'Разговор';
                $status_class = 'border-primary text-primary';
                $dot_class = 'bg-primary';
                $icon = 'fa-headset';
                $status_type = 'talk';
            } elseif (strpos($safe_line, 'ringing') !== false) {
                $status_text = 'Вызов...';
                $status_class = 'border-info text-info animate-pulse';
                $dot_class = 'bg-info';
                $icon = 'fa-bell';
                $status_type = 'talk'; // При вызове линия уже условно занята
            }

            // Плюсуем в общую копилку очереди
            if ($status_type === 'free') $queues[$current_queue]['count_free']++;
            if ($status_type === 'talk') $queues[$current_queue]['count_talk']++;
            if ($status_type === 'offline') $queues[$current_queue]['count_offline']++;

            $calls_taken = 0;
            if (preg_match('/has taken (\d+) calls/i', $line, $call_matches)) {
                $calls_taken = (int)$call_matches[1];
            }

            $queues[$current_queue]['members'][] = [
                'number' => $agent_num,
                'status' => $status_text,
                'class'  => $status_class,
                'dot'    => $dot_class,
                'icon'   => $icon,
                'calls'  => $calls_taken
            ];
        }	
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Мониторинг операторов — VoxDial</title>
    <meta http-equiv="refresh" content="5">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f6f9; font-family: 'Segoe UI', system-ui, sans-serif; overflow-x: hidden; }
        .sidebar { background: #1e2229; min-height: 100vh; color: white; }
        .card-queue { border: none; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.04); margin-bottom: 1rem; }
        .queue-header { background-color: #eaedf1; border-radius: 8px 8px 0 0; padding: 0.5rem 0.75rem; }
        
        /* Микро-карточки операторов */
        .agent-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 8px; padding: 10px; }
        .agent-mini-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 6px 10px; display: flex; flex-direction: column; justify-content: space-between; position: relative; }
        
        .status-pill { font-size: 0.75rem; font-weight: 600; padding: 2px 6px; border: 1px solid; border-radius: 4px; display: inline-flex; align-items: center; gap: 4px; width: fit-content; }
        .status-dot { width: 6px; height: 6px; border-radius: 50%; display: inline-block; }
        
        /* Стили для счетчиков в шапке */
        .header-counter { font-size: 0.75rem; font-weight: bold; padding: 3px 8px; border-radius: 4px; margin-left: 4px; display: inline-flex; align-items: center; gap: 4px; }
        
        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.4; } 100% { opacity: 1; } }
        .animate-pulse { animation: pulse 1s infinite; }
    </style>
</head>
<body>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-2 d-none d-md-block sidebar p-3">
            <h4 class="text-center mb-3"><i class="fa-solid fa-phone-volume me-2 text-info"></i>VoxDial</h4>
            <hr class="mt-0">
            <ul class="nav nav-pills flex-column mb-auto">
                <li><a href="index.php" class="nav-link text-white py-1"><i class="fa-solid fa-chart-pie me-2"></i>Кампании</a></li>
                <li><a href="create.php" class="nav-link text-white py-1 mt-1"><i class="fa-solid fa-plus me-2"></i>Создать обзвон</a></li>
                <li><a href="agents.php" class="nav-link text-white active bg-info py-1 mt-1"><i class="fa-solid fa-users me-2"></i>Операторы</a></li>
                <li><a href="stats.php" class="nav-link text-white py-1 mt-1"><i class="fa-solid fa-chart-column me-2"></i>Статистика</a></li>
		<li><a href="blacklist.php" class="nav-link text-white mt-2"><i class="fa-solid fa-user-slash me-2"></i>Стоп-лист</a></li>
            </ul>
        </div>

        <div class="col-md-10 p-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div>
                    <h4 class="mb-0 fw-bold text-dark">Контроль линий онлайн</h4>
                    <small class="text-muted small">Обновление: <strong><?= date('H:i:s') ?></strong></small>
                </div>
            </div>

            <?php if (empty($queues)): ?>
                <div class="alert alert-warning p-3 text-center card">
                    <h6 class="mb-0"><i class="fa-solid fa-triangle-exclamation me-2"></i>Активные очереди не найдены</h6>
                </div>
            <?php else: ?>
                <div class="row g-2">
                    <?php foreach ($queues as $q_num => $q_info): ?>
                        <div class="col-12">
                            <div class="card card-queue bg-white">
                                <div class="queue-header d-flex justify-content-between align-items-center border-bottom">
                                    <span class="fw-bold text-secondary" style="font-size: 0.95rem;">
                                        <i class="fa-solid fa-users-rectangle text-info me-1"></i> Очередь <?= $q_num ?>
                                    </span>
                                    <div class="d-flex align-items-center">
                                        <span class="header-counter bg-success-subtle text-success border border-success-subtle"><i class="fa-solid fa-circle-check"></i> Свободен: <?= $q_info['count_free'] ?></span>
                                        <span class="header-counter bg-primary-subtle text-primary border border-primary-subtle"><i class="fa-solid fa-headset"></i> Разговор: <?= $q_info['count_talk'] ?></span>
                                        <span class="header-counter bg-danger-subtle text-danger border border-danger-subtle"><i class="fa-solid fa-phone-slash"></i> Офлайн: <?= $q_info['count_offline'] ?></span>
                                        
                                        <span class="vertical-divider mx-2 text-black-50">|</span>
                                        
                                        <span class="badge bg-danger pt-1" style="font-size: 0.75rem;">Ожидают: <?= $q_info['calls'] ?></span>
                                        <span class="badge bg-secondary pt-1 ms-1" style="font-size: 0.75rem;">Всего: <?= count($q_info['members']) ?></span>
                                    </div>
                                </div>
                                
                                <div class="agent-grid">
                                    <?php if (empty($q_info['members'])): ?>
                                        <div class="w-100 text-center text-muted small py-2">Нет операторов.</div>
                                    <?php else: ?>
                                        <?php foreach ($q_info['members'] as $agent): ?>
                                            <div class="agent-mini-card">
                                                <div class="d-flex justify-content-between align-items-start mb-1">
                                                    <span class="fw-bold fs-5 text-dark" style="line-height: 1.2;">
                                                        <i class="fa-solid fa-user text-black-50 fs-6 me-1"></i><?= $agent['number'] ?>
                                                    </span>
                                                    <span class="text-muted" style="font-size: 0.7rem;" title="Отвечено звонков за день">
                                                        Звонков: <strong><?= $agent['calls'] ?></strong>
                                                    </span>
                                                </div>
                                                <div>
                                                    <div class="status-pill <?= $agent['class'] ?>">
                                                        <span class="status-dot <?= $agent['dot'] ?>"></span>
                                                        <i class="fa-solid <?= $agent['icon'] ?>" style="font-size: 0.7rem;"></i>
                                                        <?= $agent['status'] ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>

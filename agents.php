<?php
// agents.php — Профессиональный Live Wallboard: компактная сетка, фильтр офлайна, таймеры статусов, графики и звуковые алерты
require_once __DIR__ . '/config.php';

// Если это AJAX-запрос от JavaScript — отдаем только чистый JSON и мгновенно завершаем скрипт
if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    header('Content-Type: application/json');
    echo json_encode(getLiveQueueData());
    exit;
}

function getLiveQueueData() {
    $output = shell_exec(escapeshellcmd(app_config('asterisk.bin')) . " -rx " . escapeshellarg('queue show'));
    $queues = [];

    $agent_names = [];
    $queue_descrs = [];
    try {
        $pdo_ast = db_pdo('asterisk');
        
        // Подгружаем имена сотрудников из FreePBX
        $stmt_u = $pdo_ast->query("SELECT extension, name FROM users");
        while ($r = $stmt_u->fetch(PDO::FETCH_ASSOC)) {
            $agent_names[$r['extension']] = $r['name'];
        }

        // Подгружаем текстовые описания очередей
        $stmt_q = $pdo_ast->query("SELECT extension, descr FROM queues_config");
        while ($r = $stmt_q->fetch(PDO::FETCH_ASSOC)) {
            $queue_descrs[$r['extension']] = $r['descr'];
        }
    } catch (Exception $e) {
        // Защита: если базы нет — массивы имен будут пустыми
    }

    if (!empty($output)) {
        $lines = explode("\n", $output);
        $current_queue = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            if (preg_match('/^(\d+)\s+has\s+(\d+)\s+calls/', $line, $matches)) {
                $current_queue = $matches[1];
                $queues[$current_queue] = [
                    'queue_num' => $current_queue,
                    'descr' => isset($queue_descrs[$current_queue]) ? $queue_descrs[$current_queue] : 'Без описания',
                    'calls' => (int)$matches[2],
                    'members' => [],
                    'count_free' => 0,
                    'count_talk' => 0,
                    'count_paused' => 0,
                    'count_offline' => 0
                ];
                continue;
            }

            if ($current_queue && preg_match('/(Local|SIP|PJSIP)\/(\d+)/i', $line, $matches)) {
                $agent_num = $matches[2]; 
                $status_text = 'Неизвестно';
                $status_class = 'border-secondary text-secondary bg-secondary-subtle';
                $dot_class = 'bg-secondary';
                $icon = 'fa-question-circle';
                $status_type = 'unknown';

                $safe_line = strtolower($line);
                $safe_line = str_replace('ringinuse', '', $safe_line);

                if (strpos($safe_line, 'unavailable') !== false) {
                    $status_text = 'Офлайн';
                    $status_class = 'border-danger text-danger bg-danger-subtle';
                    $dot_class = 'bg-danger';
                    $icon = 'fa-phone-slash';
                    $status_type = 'offline';
                } elseif (strpos($safe_line, 'paused') !== false) {
                    $status_text = 'Пауза';
                    $status_class = 'border-warning text-warning-emphasis bg-warning-subtle';
                    $dot_class = 'bg-warning';
                    $icon = 'fa-coffee';
                    $status_type = 'paused';
                } elseif (strpos($safe_line, 'not in use') !== false) {
                    $status_text = 'Свободен';
                    $status_class = 'border-success text-success bg-success-subtle';
                    $dot_class = 'bg-success';
                    $icon = 'fa-circle-check';
                    $status_type = 'free';
                } elseif (strpos($safe_line, 'in use') !== false) {
                    $status_text = 'Разговор';
                    $status_class = 'border-primary text-primary bg-primary-subtle';
                    $dot_class = 'bg-primary';
                    $icon = 'fa-headset';
                    $status_type = 'talk';
                } elseif (strpos($safe_line, 'ringing') !== false) {
                    $status_text = 'Вызов...';
                    $status_class = 'border-info text-info bg-info-subtle animate-pulse';
                    $dot_class = 'bg-info';
                    $icon = 'fa-bell';
                    $status_type = 'ringing'; 
                }

                if ($status_type === 'free') $queues[$current_queue]['count_free']++;
                if ($status_type === 'talk' || $status_type === 'ringing') $queues[$current_queue]['count_talk']++;
                if ($status_type === 'paused') $queues[$current_queue]['count_paused']++;
                if ($status_type === 'offline') $queues[$current_queue]['count_offline']++;

                $calls_taken = 0;
                if (preg_match('/has taken (\d+) calls/i', $line, $call_matches)) {
                    $calls_taken = (int)$call_matches[1];
                }

                $queues[$current_queue]['members'][] = [
                    'number' => $agent_num,
                    'name'   => isset($agent_names[$agent_num]) ? $agent_names[$agent_num] : 'Оператор',
                    'status' => $status_text,
                    'status_type' => $status_type, 
                    'class'  => $status_class,
                    'dot'    => $dot_class,
                    'icon'   => $icon,
                    'calls'  => $calls_taken
                ];
            }	
        }
    }
    return array_values($queues); 
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Live Wallboard — VoxDial</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f6f9; font-family: 'Segoe UI', system-ui, sans-serif; overflow-x: hidden; }
        .sidebar { background: #1e2229; min-height: 100vh; color: white; }
        .card-queue { border: none; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.03); margin-bottom: 1.5rem; }
        .queue-header { background-color: #eaedf1; border-radius: 10px 10px 0 0; padding: 0.6rem 1rem; }
        
        .agent-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 8px; padding: 12px; }
        .agent-mini-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 6px 10px; display: flex; flex-direction: column; justify-content: space-between; min-height: 70px; transition: background-color 0.5s ease; }
        .agent-title { font-size: 0.82rem; font-weight: 700; line-height: 1.2; }
        
        .status-pill { font-size: 0.68rem; font-weight: 700; padding: 2px 6px; border: 1px solid; border-radius: 4px; display: inline-flex; align-items: center; gap: 4px; width: fit-content; text-transform: uppercase; }
        .status-dot { width: 6px; height: 6px; border-radius: 50%; display: inline-block; }
        .status-timer { font-size: 0.72rem; font-weight: bold; color: #495057; margin-left: auto; }
        
        .header-counter { font-size: 0.75rem; font-weight: bold; padding: 4px 10px; border-radius: 6px; margin-left: 5px; display: inline-flex; align-items: center; gap: 5px; }
        
        /* Стили для критических триггеров алертов */
        .alert-flash-red { background-color: #fff5f5 !important; border-color: #e53e3e !important; animation: borderPulse 1s infinite; }
        .text-alert-danger { color: #e53e3e !important; font-weight: 900 !important; animation: textPulse 1s infinite; }
        .badge-pulse-red { animation: bgPulse 1s infinite; }

        @keyframes borderPulse { 0% { border-color: #e53e3e; } 50% { border-color: #edf2f7; } 100% { border-color: #e53e3e; } }
        @keyframes textPulse { 0% { opacity: 1; } 50% { opacity: 0.4; } 100% { opacity: 1; } }
        @keyframes bgPulse { 0% { background-color: #dc3545; } 50% { background-color: #ffc107; } 100% { background-color: #dc3545; } }
        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.4; } 100% { opacity: 1; } }
        .animate-pulse { animation: pulse 1s infinite; }
    </style>
</head>
<body>

<script>
    let audioCtx = null;
    function playAlertSound() {
        try {
            if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            let osc = audioCtx.createOscillator();
            let gain = audioCtx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(587.33, audioCtx.currentTime); // Нота Ре второй октавы
            gain.gain.setValueAtTime(0.08, audioCtx.currentTime); // Тихий комфортный уровень звука
            osc.connect(gain);
            gain.connect(audioCtx.destination);
            osc.start();
            osc.stop(audioCtx.currentTime + 0.15); // Короткий писк 150мс
        } catch(e) { console.log("Audio play blocked by browser policy"); }
    }
</script>

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

        <div class="col-md-10 p-4">
            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-3">
                <div>
                    <h3 class="mb-0 fw-bold text-dark">Live Wallboard — Контроль линий онлайн</h3>
                    <small class="text-muted fs-6">Время системы: <strong id="clock-display"><?= date('H:i:s') ?></strong></small>
                </div>
                
                <div class="d-flex align-items-center gap-2 bg-white preg-panel px-3 py-2 rounded shadow-sm border">
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" role="switch" id="hideOfflineToggle" style="cursor: pointer;">
                        <label class="form-check-label small fw-bold text-danger" for="hideOfflineToggle" style="cursor: pointer; user-select: none;">
                            <i class="fa-solid fa-eye-slash me-1"></i> Скрыть Офлайн
                        </label>
                    </div>
                    <span class="text-muted mx-1">|</span>
                    <span class="small text-muted fw-bold me-1"><i class="fa-solid fa-chart-line text-info me-1"></i>Нагрузка (1ч):</span>
                    <canvas id="miniTrendCanvas" width="90" height="20" style="background:#f8f9fa; border:1px solid #e2e8f0; border-radius:3px;"></style></canvas>
                    <div class="spinner-border text-info spinner-border-sm ms-1" id="live-spinner" role="status" style="opacity:0; transition: 0.2s;"></div>
                </div>
            </div>

            <div id="queues-target-container" class="row g-3">
                </div>
        </div>
    </div>
</div>

<script>
    const targetContainer = document.getElementById('queues-target-container');
    const spinner = document.getElementById('live-spinner');
    const clockDisplay = document.getElementById('clock-display');
    const hideOfflineToggle = document.getElementById('hideOfflineToggle');
    
    // Глобальный объект для хранения таймеров состояний операторов между AJAX запросами
    // Хранит структуру: { "507": { status: "Разговор", timestamp: 17133344 } }
    let agentStateTracker = {};
    let trendHistory = []; // Хранит последние 30 точек загрузки для холста

    if (localStorage.getItem('hideOfflineAgents') === 'true') {
        hideOfflineToggle.checked = true;
    }

    hideOfflineToggle.addEventListener('change', function() {
        localStorage.setItem('hideOfflineAgents', this.checked);
        updateWallboard();
    });

    function formatTimerString(seconds) {
        const m = floor(seconds / 60);
        const s = seconds % 60;
        return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
    }
    function floor(n) { return Math.floor(n); }

    function updateWallboard() {
        if (spinner) spinner.style.opacity = "1";

        // Клик в любом месте активирует Web Audio API
        document.body.addEventListener('click', () => { if(audioCtx && audioCtx.state === 'suspended') audioCtx.resume(); }, {once:true});

        fetch('agents.php?ajax=1')
            .then(response => response.json())
            .then(data => {
                if (spinner) spinner.style.opacity = "0";
                
                const nowUnix = floor(Date.now() / 1000);
                const now = new Date();
                if (clockDisplay) clockDisplay.textContent = now.toTimeString().split(' ')[0];

                if (!data || data.length === 0) {
                    targetContainer.innerHTML = `
                        <div class="col-12">
                            <div class="alert alert-warning p-4 text-center card shadow-sm">
                                <h5 class="mb-0 fw-bold"><i class="fa-solid fa-triangle-exclamation me-2"></i>Активные очереди звонков в Asterisk не найдены</h5>
                            </div>
                        </div>`;
                    return;
                }

                // Логика расчета мини-графика нагрузки (Считаем сколько всего операторов в разговоре по всей системе)
                let totalCurrentTalking = 0;
                data.forEach(q => { totalCurrentTalking += q.count_talk; });
                trendHistory.push(totalCurrentTalking);
                if (trendHistory.length > 30) trendHistory.shift(); // Держим только последние 30 точек
                drawMiniTrend();

                const hideOffline = hideOfflineToggle.checked;
                let html = '';
                let triggeringAlarm = false; // Системный флаг для подачи писка

                data.forEach(q => {
                    let membersHtml = '';
                    let visibleMembersCount = 0;

                    // Алерт черги: Если в очереди висят клиенты в ожидании > 0
                    let queueCallAlertClass = q.calls > 0 ? 'badge-pulse-red' : 'bg-danger';
                    if (q.calls > 0) triggeringAlarm = true; // Будем пищать
                    
                    if (q.members && q.members.length > 0) {
                        q.members.forEach(m => {
                            if (hideOffline && m.status_type === 'offline') return; 
                            
                            visibleMembersCount++;

                            // --- ⏱️ УПРАВЛЕНИЕ ЖИВЫМИ ТАЙМЕРАМИ СТАТУСОВ ---
                            if (!agentStateTracker[m.number] || agentStateTracker[m.number].status !== m.status) {
                                // Если оператор сменил статус или только вошел в систему — сбрасываем секундомер
                                agentStateTracker[m.number] = {
                                    status: m.status,
                                    status_type: m.status_type,
                                    timestamp: nowUnix
                                };
                            }

                            const elapsedSeconds = nowUnix - agentStateTracker[m.number].timestamp;
                            const timerString = formatTimerString(elapsedSeconds);

                            // --- 🚨 АНАЛИЗ И СРАБАТЫВАНИЕ КРИТИЧЕСКИХ КРИТЕРИЕВ АЛЕРТОВ ---
                            let cardAlertClass = '';
                            let textAlertClass = '';

                            if (m.status_type === 'paused' && elapsedSeconds >= 900) { 
                                // Алерт 1: На паузе больше 15 минут (900 сек)
                                cardAlertClass = 'alert-flash-red';
                                textAlertClass = 'text-alert-danger';
                                triggeringAlarm = true;
                            } else if (m.status_type === 'ringing' && elapsedSeconds >= 15) { 
                                // Алерт 2: Робот звонит оператору, а тот не берет трубку > 15 секунд
                                cardAlertClass = 'alert-flash-red';
                                textAlertClass = 'text-alert-danger';
                                triggeringAlarm = true;
                            }

                            membersHtml += `
                                <div class="agent-mini-card ${cardAlertClass}" data-agent="${m.number}">
                                    <div class="d-flex justify-content-between align-items-start mb-1 gap-1">
                                        <span class="agent-title text-dark text-truncate ${textAlertClass}" title="${m.name} (${m.number})">
                                            <i class="fa-solid fa-user text-black-50 fs-7 me-1"></i>${m.number} — ${m.name}
                                        </span>
                                        <span class="badge bg-light text-dark border border-secondary-subtle" style="font-size: 0.62rem; padding: 2px 4px;">
                                            ${m.calls} зв.
                                        </span>
                                    </div>
                                    <div class="d-flex align-items-center mt-1">
                                        <div class="status-pill ${m.class}">
                                            <span class="status-dot ${m.dot}"></span>
                                            <i class="fa-solid ${m.icon}" style="font-size: 0.62rem;"></i>
                                            ${m.status}
                                        </div>
                                        <span class="status-timer ${textAlertClass}" id="timer-${m.number}">${timerString}</span>
                                    </div>
                                </div>`;
                        });
                    }

                    if (hideOffline && visibleMembersCount === 0 && q.members.length > 0) {
                        membersHtml = '<div class="w-100 text-center text-muted small py-3"><i class="fa-solid fa-user-slash me-1"></i>Все операторы этой очереди сейчас оффлайн.</div>';
                    }

                    html += `
                        <div class="col-12">
                            <div class="card card-queue bg-white">
                                <div class="queue-header d-flex justify-content-between align-items-center border-bottom">
                                    <span class="fw-bold text-dark" style="font-size: 1.05rem;">
                                        <i class="fa-solid fa-users-rectangle text-info me-2"></i>${q.queue_num} <span class="text-muted fw-normal fs-6">(${q.descr})</span>
                                    </span>
                                    <div class="d-flex align-items-center flex-wrap gap-1">
                                        <span class="header-counter bg-success-subtle text-success border border-success-subtle"><i class="fa-solid fa-circle-check"></i> Свободны: ${q.count_free}</span>
                                        <span class="header-counter bg-primary-subtle text-primary border border-primary-subtle"><i class="fa-solid fa-headset"></i> В разговоре: ${q.count_talk}</span>
                                        <span class="header-counter bg-warning-subtle text-warning-emphasis border border-warning-subtle"><i class="fa-solid fa-coffee"></i> На паузе: ${q.count_paused}</span>
                                        <span class="header-counter bg-danger-subtle text-danger border border-danger-subtle"><i class="fa-solid fa-phone-slash"></i> Офлайн: ${q.count_offline}</span>
                                        
                                        <span class="mx-2 text-black-50 d-none d-lg-inline">|</span>
                                        
                                        <span class="badge ${queueCallAlertClass} px-2 py-1 fw-bold fs-7">В ожидании: ${q.calls}</span>
                                        <span class="badge bg-secondary px-2 py-1 fw-bold fs-7">Показано: ${visibleMembersCount} из ${q.members.length}</span>
                                    </div>
                                </div>
                                <div class="agent-grid">
                                    ${membersHtml}
                                </div>
                            </div>
                        </div>`;
                });

                targetContainer.innerHTML = html;
                
                // Стреляем звуковым сигналом, если триггер сработал
                if (triggeringAlarm) playAlertSound();
            })
            .catch(err => {
                if (spinner) spinner.style.opacity = "0";
                console.error("Ошибка обновления Wallboard:", err);
            });
    }

    // Дополнительный локальный таймер, работающий каждую секунду
    // Позволяет цифрам времени бежать плавно на экране, не дергая каждую секунду запросы к Asterisk
    function runLocalTimers() {
        const nowUnix = floor(Date.now() / 1000);
        for (const agentNum in agentStateTracker) {
            const timerElement = document.getElementById('timer-' + agentNum);
            if (timerElement) {
                const elapsed = nowUnix - agentStateTracker[agentNum].timestamp;
                timerElement.textContent = formatTimerString(elapsed);
            }
        }
    }

    // Рисование холста мини-тренда нагрузки
    function drawMiniTrend() {
        const canvas = document.getElementById('miniTrendCanvas');
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        
        if (trendHistory.length < 2) return;
        
        ctx.beginPath();
        ctx.strokeStyle = '#0dcaf0'; // Твой корпоративный цвет инфо
        ctx.lineWidth = 1.5;
        
        const maxVal = Math.max(...trendHistory, 5); // Базовый масштаб минимум на 5 каналов
        const stepX = canvas.width / 30;
        
        trendHistory.forEach((val, index) => {
            const x = index * stepX;
            const y = canvas.height - (val / maxVal * (canvas.height - 4)) - 2;
            if (index === 0) ctx.moveTo(x, y);
            else ctx.lineTo(x, y);
        });
        ctx.stroke();
    }

    // Настройка интервалов:
    setInterval(updateWallboard, 3000); // Опрос Астериска раз в 3 сек
    setInterval(runLocalTimers, 1000);   // Плавный бег цифр на экране каждую секунду
    
    updateWallboard();
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

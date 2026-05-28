<?php
// report.php — Финальная версия с защитой от наложения старых тестов и поддержкой Стоп-листа
require_once __DIR__ . '/config.php';

try {
    $pdo = db_pdo('dialer');
    $pdo_cdr = db_pdo('cdr');
} catch (PDOException $e) {
    die("Ошибка подключения к БД: " . $e->getMessage());
}

$campaign_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt_c = $pdo->prepare("SELECT * FROM campaigns WHERE id = ?");
$stmt_c->execute([$campaign_id]);
$campaign = $stmt_c->fetch(PDO::FETCH_ASSOC);

if (!$campaign) {
    die("Campaign не найдена!");
}

$min_success_duration = (int)$campaign['min_success_duration'];

$stmt_l = $pdo->prepare("SELECT id, phone, name, campaign_id, status, updated_at FROM leads WHERE campaign_id = ? ORDER BY id ASC");
$stmt_l->execute([$campaign_id]);
$leads = $stmt_l->fetchAll(PDO::FETCH_ASSOC);

function getStatusBadge($status_id) {
    switch ($status_id) {
        case 0: return '<span class="badge bg-secondary px-2 py-1"><i class="fa-solid fa-clock me-1"></i>В очереди</span>';
        case 1: return '<span class="badge bg-info text-white px-2 py-1 animate-pulse"><i class="fa-solid fa-spinner fa-spin me-1"></i>Звоним...</span>';
        case 2: return '<span class="badge bg-success px-3 py-1"><i class="fa-solid fa-circle-check me-1"></i>Успешно</span>';
        case 3: return '<span class="badge bg-warning text-dark px-3 py-1"><i class="fa-solid fa-circle-minus me-1"></i>Занято</span>';
        case 4: return '<span class="badge bg-danger px-3 py-1"><i class="fa-solid fa-circle-xmark me-1"></i>Нет ответа</span>';
        case 5: return '<span class="badge bg-dark text-white px-3 py-1"><i class="fa-solid fa-triangle-exclamation me-1"></i>Сброс</span>';
        case 6: return '<span class="badge bg-danger-subtle text-danger border border-danger-subtle px-3 py-1 fw-bold"><i class="fa-solid fa-phone-slash me-1"></i>Отвечен/Сброс</span>';
        // НОВЫЙ БЕЙДЖ ДЛЯ ОТОБРАЖЕНИЯ СТОП-ЛИСТА
        case 7: return '<span class="badge bg-danger text-white border border-danger px-3 py-1 fw-bold" style="background-color: #dc3545 !important;"><i class="fa-solid fa-user-slash me-1"></i>Стоп-лист</span>';
        default: return '<span class="badge bg-light text-muted">Неизвестно</span>';
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Детализация кампании — <?= htmlspecialchars($campaign['name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; font-family: 'Segoe UI', system-ui, sans-serif; }
        .sidebar { background: #212529; min-height: 100vh; color: white; }
        .card { border: none; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.4; } 100% { opacity: 1; } }
        .animate-pulse { animation: pulse 1s infinite; }
    </style>
</head>
<body>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-2 d-none d-md-block sidebar p-3">
            <h4 class="text-center mb-4"><i class="fa-solid fa-phone-volume me-2 text-info"></i>VoxDial</h4>
            <hr>
            <ul class="nav nav-pills flex-column mb-auto">
                <li class="nav-item"><a href="index.php" class="nav-link text-white active bg-info"><i class="fa-solid fa-chart-pie me-2"></i>Кампании</a></li>
                <li><a href="create.php" class="nav-link text-white mt-2"><i class="fa-solid fa-plus me-2"></i>Создать обзвон</a></li>
                <li><a href="agents.php" class="nav-link text-white mt-2"><i class="fa-solid fa-users me-2"></i>Операторы</a></li>
                <li><a href="stats.php" class="nav-link text-white mt-2"><i class="fa-solid fa-chart-column me-2"></i>Статистика</a></li>
                <li><a href="blacklist.php" class="nav-link text-white mt-2"><i class="fa-solid fa-user-slash me-2"></i>Стоп-лист</a></li>
            </ul>
        </div>

        <div class="col-md-10 p-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <a href="index.php" class="btn btn-outline-secondary btn-sm mb-2"><i class="fa-solid fa-arrow-left me-1"></i> Назад к кампаниям</a>
                    <h2>Кампания: «<?= htmlspecialchars($campaign['name']) ?>»</h2>
                <p class="text-muted mb-0">
    <?php 
    $dest_type = isset($campaign['destination_type']) ? $campaign['destination_type'] : 'queue';
    $dest_val = !empty($campaign['destination_value']) ? $campaign['destination_value'] : $campaign['queue_num'];
    if ($dest_type === 'ivr'): ?>
        Направление: <span class="badge bg-info text-white"><i class="fa-solid fa-robot me-1"></i> IVR (ID: <?= htmlspecialchars($dest_val) ?>)</span>
    <?php else: ?>
        Направление: <span class="badge bg-success text-white"><i class="fa-solid fa-headset me-1"></i> Очередь <?= htmlspecialchars($dest_val) ?></span>
    <?php endif; ?>
    | Критерий успешности: <strong>>= <?= $min_success_duration ?> сек.</strong>
</p>    
                </div>
            </div>

            <div class="card p-4">
                <h5 class="fw-bold text-secondary mb-3"><i class="fa-solid fa-address-book text-info me-2"></i>Список контактов (Посекундный UID accountcode-анализ)</h5>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 8%;">ID лида</th>
                                <th style="width: 17%;">Телефон</th>
                                <th style="width: 20%;">Имя клиента</th>
                                <th style="width: 12%;"><i class="fa-solid fa-headset me-1"></i>Оператор</th>
                                <th style="width: 18%; text-align: center;"><i class="fa-solid fa-bell me-1 text-warning"></i>Ожидание (Гудки)</th>
                                <th style="width: 15%; text-align: center;"><i class="fa-solid fa-comments me-1 text-success"></i>Разговор</th>
                                <th style="width: 10%;">Результат</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($leads)): ?>
                                <tr><td colspan="7" class="text-center text-muted py-4">В этой кампании пока нет номеров.</td></tr>
                            <?php else: ?>
                                <?php foreach ($leads as $lead): 
                                    $final_status = 4; 
                                    $final_duration = 0;
                                    $final_hold = 0;
                                    $final_agent = '—';

                                    $db_status = (int)$lead['status'];

                                    if ($db_status !== 0) {
                                        $unique_accountcode = "dialer-lead-" . (int)$lead['id'];
                                        $time_threshold = date('Y-m-d H:i:s', strtotime($lead['updated_at']) - 120);

                                        $query = "SELECT disposition, duration, billsec, channel, dstchannel, dst, src 
                                                  FROM cdr 
                                                  WHERE accountcode = :accountcode 
                                                    AND calldate >= :time_threshold
                                                  ORDER BY calldate ASC";
                                                  
                                        $stmt = $pdo_cdr->prepare($query);
                                        $stmt->execute([
                                            'accountcode' => $unique_accountcode,
                                            'time_threshold' => $time_threshold
                                        ]);
                                        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                                        if (!empty($rows)) {
                                            $has_answered = false;
                                            $has_busy = false;
                                            $has_no_answer = false;
                                            $max_billsec = 0;
                                            $max_duration = 0;

                                            foreach ($rows as $row) {
                                                $disp = strtoupper(trim($row['disposition']));
                                                $billsec = (int)$row['billsec'];
                                                $duration = (int)$row['duration'];

                                                if ($duration > $max_duration) {
                                                    $max_duration = $duration;
                                                }

                                                if ($disp === 'ANSWERED') {
                                                    $has_answered = true;
                                                    if ($billsec > $max_billsec) {
                                                        $max_billsec = $billsec;
                                                    }
                                                    
                                                    if (is_numeric($row['dst']) && strlen($row['dst']) >= 2 && strlen($row['dst']) <= 4) {
                                                        $final_agent = $row['dst'];
                                                    } elseif (is_numeric($row['src']) && strlen($row['src']) >= 2 && strlen($row['src']) <= 4) {
                                                        $final_agent = $row['src'];
                                                    } elseif (preg_match('/(?:Local|SIP|PJSIP)\/([0-9]+)/i', $row['dstchannel'], $matches)) {
                                                        if (strlen($matches[1]) <= 4) $final_agent = $matches[1];
                                                    }
                                                }
                                                if ($disp === 'BUSY') $has_busy = true;
                                                if ($disp === 'NO ANSWER') $has_no_answer = true;
                                            }

                                            if ($has_answered) {
                                                $final_duration = $max_billsec;
                                                $final_hold = $max_duration - $max_billsec;
                                                if ($final_hold <= 0) $final_hold = 4;

                                                $final_status = ($max_billsec >= $min_success_duration) ? 2 : 6;
                                            } else {
                                                $final_duration = 0;
                                                $final_hold = 0;
                                                $final_agent = '—';

                                                if ($has_busy) {
                                                    $final_status = 5; 
                                                } elseif ($has_no_answer) {
                                                    $final_status = ($max_duration >= 25) ? 3 : 4; 
                                                }
                                            }
                                        } else {
                                            // АНАЛИЗ СТОП-ЛИСТА: Если логов нет, но статус равен 5 — выводим бейдж Стоп-лист (7)
                                            if ($db_status === 5) {
                                                $final_status = 7;
                                            } else {
                                                $final_status = 4;
                                            }
                                            $final_duration = 0;
                                            $final_hold = 0;
                                            $final_agent = '—';
                                        }
                                    } else {
                                        $final_status = 0; 
                                    }
                                ?>
                                <tr>
                                    <td><span class="text-muted fw-bold">#<?= $lead['id'] ?></span></td>
                                    <td><strong class="text-dark"><i class="fa-solid fa-mobile-screen me-2 text-black-50"></i><?= htmlspecialchars($lead['phone']) ?></strong></td>
                                    <td><?= !empty($lead['name']) ? htmlspecialchars($lead['name']) : '<span class="text-muted text-black-50">—</span>' ?></td>
                                    
                                    <td>
                                        <?php if ($final_agent !== '—'): ?>
                                            <span class="badge bg-info-subtle text-info border border-info-subtle px-2 py-1 fw-bold">Ext: <?= htmlspecialchars($final_agent) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>

                                    <td style="text-align: center;">
                                        <?php if (in_array($final_status, [2, 3, 4, 5, 6]) && $final_hold > 0): ?>
                                            <span class="text-secondary fw-bold"><i class="fa-regular fa-bell me-1"></i> <?= $final_hold ?> сек.</span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>

                                    <td style="text-align: center;">
                                        <?php if ($final_duration > 0): ?>
                                            <span class="fw-bold text-success"><i class="fa-solid fa-phone-volume me-1"></i> <?= $final_duration ?> сек.</span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <td><?= getStatusBadge($final_status) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>

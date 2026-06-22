<?php
// index.php — Главная панель управления (Дашборд) с правильной иерархией статусов CDR и кнопкой редиала
require_once __DIR__ . '/config.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

try {
    $pdo = db_pdo('dialer'); 
    $pdo_cdr = db_pdo('cdr');
} catch (PDOException $e) {
    die("Ошибка подключения к БД: " . $e->getMessage());
}

// 🚀 ОПРЕДЕЛЯЕМ СТАТУС СЛУЖБЫ DIALER.SERVICE В СИСТЕМЕ
$service_status_raw = shell_exec("systemctl is-active " . escapeshellarg(app_config('services.dialer')) . " 2>&1");
$service_status = trim($service_status_raw);

if ($service_status === 'active') {
    $service_badge = '<span class="badge bg-success fs-6 px-3 py-2"><i class="fa-solid fa-circle-check me-1"></i> Запущена (Active)</span>';
} else {
    $service_badge = '<span class="badge bg-danger fs-6 px-3 py-2 animate-pulse"><i class="fa-solid fa-circle-exclamation me-1"></i> Остановлена (Inactive)</span>';
}

// Загружаем все кампании
$query_c = "SELECT * FROM campaigns ORDER BY id DESC";
$campaigns_raw = $pdo->query($query_c)->fetchAll(PDO::FETCH_ASSOC);

$campaigns = [];
$global_total_success = 0;
$running_campaigns_count = 0; 

// ПЕРЕБИРАЕМ КАМПАНИИ И СЧИТАЕМ СТАТИСТИКУ
foreach ($campaigns_raw as $c) {
    $campaign_id = (int)$c['id'];
    $min_dur = (int)$c['min_success_duration'];

    if ((int)$c['status'] === 1) {
        $running_campaigns_count++;
    }

    $stmt_l = $pdo->prepare("SELECT id, status, updated_at FROM leads WHERE campaign_id = ?");
    $stmt_l->execute([$campaign_id]);
    $leads = $stmt_l->fetchAll(PDO::FETCH_ASSOC);

    $stats = [
        'id' => $c['id'],
        'name' => $c['name'],
        'queue_num' => $c['queue_num'],
        'destination_type' => isset($c['destination_type']) ? $c['destination_type'] : 'queue',
        'destination_value' => !empty($c['destination_value']) ? $c['destination_value'] : $c['queue_num'],
        'status' => $c['status'],
        'total' => count($leads),
        'new' => 0,
        'success' => 0,
        'busy' => 0,
        'no_answer' => 0,
        'error' => 0,
        'resp_drop' => 0,
        'blacklisted' => 0,
        'failed_network' => 0 
    ];

    foreach ($leads as $lead) {
        $db_status = (int)$lead['status'];

        if ($db_status === 0) {
            $stats['new']++;
            continue;
        }

        $unique_accountcode = "dl-" . $campaign_id . "-" . (int)$lead['id'];

        // 1. ЗАПРОС К CDR БЕЗ ПРЕДВЗЯТОСТИ К СТАТУСУ 5 (Иерархический анализ мульти-строк)
        $query_cdr = "SELECT disposition, MAX(duration) as duration, MAX(billsec) as billsec 
                      FROM asteriskcdrdb.cdr 
                      WHERE accountcode = :accountcode
                      GROUP BY accountcode, disposition";
                      
        $stmt_cdr = $pdo_cdr->prepare($query_cdr);
        $stmt_cdr->execute(['accountcode' => $unique_accountcode]);
        $rows = $stmt_cdr->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($rows)) {
            $has_answered = false;
            $has_busy = false;
            $has_no_answer = false;
            $has_failed = false;

            $max_billsec = 0;

            foreach ($rows as $row) {
                $disp = strtoupper(trim($row['disposition']));
                $billsec = (int)$row['billsec'];

                if ($disp === 'ANSWERED') {
                    $has_answered = true;
                    if ($billsec > $max_billsec) $max_billsec = $billsec;
                }
                if ($disp === 'BUSY') $has_busy = true;
                if ($disp === 'NO ANSWER') $has_no_answer = true;
                if ($disp === 'FAILED' || $disp === 'CONGESTION') $has_failed = true;
            }

            // 2. ЖЕСТКАЯ ИЕРАРХИЯ РАСПРЕДЕЛЕНИЯ: ANSWERED всегда бьет любые ошибки!
            if ($has_answered) {
                if ($max_billsec >= $min_dur) {
                    $stats['success']++;
                    $global_total_success++;
                } else {
                    $stats['resp_drop']++; 
                }
            } elseif ($has_busy) {
                $stats['busy']++; 
            } elseif ($has_no_answer) {
                $stats['no_answer']++; 
            } else {
                // Если зафиксированы только сбои сети и робот пометил лид как финальный сбой (5)
                if ($has_failed && $db_status === 5) {
                    $stats['failed_network']++;
                } else {
                    $stats['error']++; 
                }
            }
        } else {
            // 3. Если вызовов в CDR вообще нет, распределяем строго по статусу нашей БД
            if ($db_status === 5) {
                $stats['blacklisted']++; 
            } elseif ($db_status === 3) {
                $stats['busy']++;
            } else {
                $stats['no_answer']++;
            }
        }
    }

    $campaigns[] = $stats;
}

$total_campaigns = count($campaigns);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Autodialer Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; font-family: 'Segoe UI', system-ui, sans-serif; }
        .sidebar { background: #212529; min-height: 100vh; color: white; }
        .card { border: none; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .status-badge { font-size: 0.85rem; padding: 0.4em 0.8em; }
        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.5; } 100% { opacity: 1; } }
        .animate-pulse { animation: pulse 1.5s infinite; }
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
        <h2>Панель управления автообзвоном</h2>
        <div class="mt-1 d-flex align-items-center gap-2">
            <span>Статус службы демона:</span> 
            <?= $service_badge ?>
            <a href="restart_service.php" class="btn btn-sm btn-warning fw-bold text-dark ms-2 shadow-sm" onclick="return confirm('Вы уверены, что хотите перезапустить службу автообзвона?')">
                <i class="fa-solid fa-arrows-rotate me-1"></i> Перезапустить робота
            </a>
        </div>
    </div>
    	<a href="create.php" class="btn btn-info text-white fw-bold"><i class="fa-solid fa-plus me-2"></i>Новая кампания</a>
	</div>           

            <div class="row mb-4 g-3">
                <div class="col-md-3">
                    <div class="card bg-white p-3 d-flex flex-row align-items-center h-100">
                        <div class="rounded-circle bg-light text-info p-3 fs-3 me-3"><i class="fa-solid fa-bullhorn"></i></div>
                        <div><h3 class="mb-0 fw-bold"><?= $total_campaigns ?></h3><small class="text-muted">Всего кампаний</small></div>
                    </div>
                </div>
                
                <div class="col-md-3">
                    <div class="card bg-white p-3 d-flex flex-row align-items-center h-100">
                        <div class="rounded-circle bg-success-subtle text-success p-3 fs-3 me-3"><i class="fa-solid fa-play"></i></div>
                        <div><h3 class="mb-0 fw-bold text-success"><?= $running_campaigns_count ?></h3><small class="text-muted">Запущено сейчас</small></div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="card bg-white p-3 d-flex flex-row align-items-center h-100">
                        <div class="rounded-circle bg-light text-success p-3 fs-3 me-3"><i class="fa-solid fa-phone-flip"></i></div>
                        <div><h3 class="mb-0 fw-bold"><?= $global_total_success ?></h3><small class="text-muted">Успешных разговоров</small></div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="card bg-white p-3 d-flex flex-row align-items-center h-100">
                        <div class="rounded-circle p-3 fs-3 me-3 <?= $service_status === 'active' ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger' ?>"><i class="fa-solid fa-server"></i></div>
                        <div>
                            <h5 class="mb-0 fw-bold <?= $service_status === 'active' ? 'text-success' : 'text-danger' ?>"><?= $service_status === 'active' ? 'ONLINE' : 'OFFLINE' ?></h5>
                            <small class="text-muted">Робот dialer.service</small>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card p-4">
                <h5 class="mb-3 fw-bold text-secondary">Текущие кампании обзвона</h5>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Название</th>
                                <th>Направление</th>
                                <th>Статус</th>
                                <th style="width: 20%;">Прогресс обзвона</th>
                                <th>Статистика результатов из CDR</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($campaigns as $c): 
                                $processed = $c['total'] - $c['new'];
                                $percent = $c['total'] > 0 ? round(($processed / $c['total']) * 100) : 0;
                            ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($c['name']) ?></strong></td>
                                <td>
                                    <?php if ($c['destination_type'] === 'ivr'): ?>
                                        <span class="badge bg-info text-white"><i class="fa-solid fa-robot me-1"></i> IVR (ID: <?= htmlspecialchars($c['destination_value']) ?>)</span>
                                    <?php else: ?>
                                        <span class="badge bg-success text-white"><i class="fa-solid fa-headset me-1"></i> Очередь <?= htmlspecialchars($c['destination_value']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($c['status'] == 1): ?>
                                        <span class="badge bg-success status-badge"><i class="fa-solid fa-play me-1"></i> : Активна</span>
                                    <?php elseif ($c['status'] == 2): ?>
                                        <span class="badge bg-dark status-badge"><i class="fa-solid fa-check-double me-1"></i> Завершена</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning status-badge text-dark"><i class="fa-solid fa-pause me-1"></i> Пауза</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="progress w-100 me-2" style="height: 10px;">
                                            <div class="progress-bar bg-info" role="progressbar" style="width: <?= $percent ?>%"></div>
                                        </div>
                                        <small class="fw-bold"><?= $percent ?>%</small>
                                    </div>
                                    <small class="text-muted">Обработано <?= $processed ?> из <?= $c['total'] ?></small>
                                </td>
                                <td>
                                    <span class="text-success fw-bold me-2" title="Успешно"><i class="fa-solid fa-circle-check"></i> <?= (int)$c['success'] ?></span>
                                    <span class="text-warning fw-bold me-2" title="Занято"><i class="fa-solid fa-circle-minus"></i> <?= (int)$c['busy'] ?></span>
                                    <span class="text-danger fw-bold me-2" title="Нет ответа"><i class="fa-solid fa-circle-xmark"></i> <?= (int)$c['no_answer'] ?></span>
                                    <span class="text-dark fw-bold me-2" title="Сбой / Ошибка линии"><i class="fa-solid fa-phone-slash"></i> <?= (int)$c['error'] ?></span>
                                    <span class="text-danger-emphasis fw-bold me-2" title="Отвечен/Сброс (Короткий)"><i class="fa-solid fa-handset-slash"></i> <?= (int)$c['resp_drop'] ?></span>
                                    <span class="text-danger fw-bold me-2" title="Пропущено по Стоп-листу" style="color: #dc3545 !important;"><i class="fa-solid fa-user-slash"></i> <?= (int)$c['blacklisted'] ?></span>
                                    <span class="text-warning-emphasis fw-bold" title="Технические сбои сети (FAILED / CONGESTION)" style="color: #fd7e14 !important;"><i class="fa-solid fa-triangle-exclamation"></i> <?= (int)$c['failed_network'] ?></span>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="report.php?id=<?= $c['id'] ?>" class="btn btn-outline-info" title="Детализация звонков"><i class="fa-solid fa-list-numeric"></i></a>
                                        <a href="edit.php?id=<?= $c['id'] ?>" class="btn btn-outline-primary" title="Редактировать параметры"><i class="fa-solid fa-pencil"></i></a>
                                        <a href="redial_failed.php?id=<?= $c['id'] ?>" class="btn btn-outline-warning text-dark" onclick="return confirm('Создать новую кампанию с суффиксом R и скопировать туда все сбои сети?')" title="Повторный обзвон технических сбоев"><i class="fa-solid fa-arrows-spin"></i></a>
                                        <?php if ($c['status'] == 1): ?>
                                            <a href="action.php?id=<?= $c['id'] ?>&act=pause" class="btn btn-outline-warning" title="Поставить на паузу"><i class="fa-solid fa-pause"></i></a>
                                        <?php else: ?>
                                            <a href="action.php?id=<?= $c['id'] ?>&act=start" class="btn btn-outline-success" title="Запустить обзвон"><i class="fa-solid fa-play"></i></a>
                                        <?php endif; ?>
                                        <a href="action.php?id=<?= $c['id'] ?>&act=delete" class="btn btn-outline-danger" onclick="return confirm('Удалить кампанию и все её контакты?')" title="Удалить"><i class="fa-solid fa-trash"></i></a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>

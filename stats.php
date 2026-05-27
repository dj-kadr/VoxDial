<?php
// stats.php — Аналитика VoxDial с группировкой операторов по сотням (500-е, 600-е, 700-е)
require_once __DIR__ . '/config.php';

$agent_stats = [];
$queue_stats = [];

// =========================================================================
// 1. ЛОГИКА ОПРЕДЕЛЕНИЯ ВРЕМЕННЫХ ИНТЕРВАЛОВ
// =========================================================================
$period = isset($_GET['period']) ? $_GET['period'] : 'today';
$date_start = isset($_GET['date_start']) ? $_GET['date_start'] : date('Y-m-d');
$date_end = isset($_GET['date_end']) ? $_GET['date_end'] : date('Y-m-d');

switch ($period) {
    case 'today':
        $date_start = date('Y-m-d');
        $date_end = date('Y-m-d');
        break;
    case 'week':
        $date_start = date('Y-m-d', strtotime('monday this week'));
        $date_end = date('Y-m-d');
        break;
    case 'month':
        $date_start = date('Y-m-01');
        $date_end = date('Y-m-d');
        break;
    case 'last_month':
        $date_start = date('Y-m-01', strtotime('first day of last month'));
        $date_end = date('Y-m-t', strtotime('last day of last month'));
        break;
    case 'custom':
        break;
}

$sql_start = $date_start . ' 00:00:00';
$sql_end = $date_end . ' 23:59:59';

$url_params = "&period=" . urlencode($period) . "&date_start=" . urlencode($date_start) . "&date_end=" . urlencode($date_end);

try {
    $pdo_cdr = db_pdo('cdr');
    $pdo_ast = db_pdo('asterisk');

    // Получаем очереди FreePBX
    $stmt_q_list = $pdo_ast->query("SELECT extension, descr FROM queues_config ORDER BY extension ASC");
    $all_queues = $stmt_q_list->fetchAll(PDO::FETCH_ASSOC);

    foreach ($all_queues as $q_item) {
        $queue_stats[$q_item['extension']] = [
            'descr' => $q_item['descr'],
            'entered' => 0,
            'answered' => 0,
            'abandoned' => 0,
            'total_hold_time' => 0
        ];
    }
    
    // Выборка для операторов
    $query_agents = "
        SELECT calldate, dst, dstchannel, duration, billsec, disposition 
        FROM cdr 
        WHERE calldate BETWEEN :sql_start AND :sql_end
          AND lastapp = 'Queue'
          AND dstchannel LIKE '%/%'
    ";
    
    $stmt_a = $pdo_cdr->prepare($query_agents);
    $stmt_a->execute(['sql_start' => $sql_start, 'sql_end' => $sql_end]);
    $records_agents = $stmt_a->fetchAll(PDO::FETCH_ASSOC);

    foreach ($records_agents as $row) {
        $agent_num = null;

        if (preg_match('/(?:Local|SIP|PJSIP)\/([0-9]+)/i', $row['dstchannel'], $matches)) {
            $parsed_num = $matches[1];
            if (strlen($parsed_num) >= 2 && strlen($parsed_num) <= 4) {
                $agent_num = $parsed_num;
            }
        }

        if ($agent_num) {
            if (!isset($agent_stats[$agent_num])) {
                $agent_stats[$agent_num] = [
                    'agent_num' => $agent_num,
                    'answered' => 0,
                    'effective' => 0,
                    'missed' => 0,
                    'total_talk_time' => 0
                ];
            }

            if ($row['disposition'] === 'ANSWERED') {
                $agent_stats[$agent_num]['answered']++;
                $agent_stats[$agent_num]['total_talk_time'] += (int)$row['billsec'];
                
                if ((int)$row['billsec'] > 10) {
                    $agent_stats[$agent_num]['effective']++;
                }
            } else {
                $agent_stats[$agent_num]['missed']++;
            }
        }
    }

    // Аналитика по очередям
    foreach ($queue_stats as $q_num => &$q_data) {
        $query_queue = "
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN disposition = 'ANSWERED' THEN 1 ELSE 0 END) as success,
                SUM(CASE WHEN disposition != 'ANSWERED' THEN 1 ELSE 0 END) as lost,
                SUM(duration) as total_hold
            FROM cdr 
            WHERE calldate BETWEEN :sql_start AND :sql_end
              AND lastapp = 'Queue'
              AND (lastdata LIKE :q_exact OR lastdata LIKE :q_param)
        ";
        
        $stmt_q = $pdo_cdr->prepare($query_queue);
        $stmt_q->execute([
            'sql_start' => $sql_start,
            'sql_end'   => $sql_end,
            'q_exact'   => $q_num,
            'q_param'   => $q_num . ',%'
        ]);
        $res = $stmt_q->fetch(PDO::FETCH_ASSOC);

        if ($res) {
            $q_data['entered']         = (int)$res['total'];
            $q_data['answered']        = (int)$res['success'];
            $q_data['abandoned']       = (int)$res['lost'];
            $q_data['total_hold_time'] = (int)$res['total_hold'];
        }
    }
    unset($q_data);

} catch (PDOException $e) {
    die("Ошибка: " . $e->getMessage());
}

// =========================================================================
// 🚀 ЛОГИКА ГРУППИРОВКИ ОПЕРАТОРОВ ПО СОТНЯМ
// =========================================================================
$grouped_agents = [];

foreach ($agent_stats as $agent_num => $data) {
    // Определяем "сотню" (например, 507 -> 500, 603 -> 600, 701 -> 700)
    $hundred = floor((int)$agent_num / 100) * 100;
    
    // Если номера странные/короткие (меньше 100), кидаем в группу 0
    if ($hundred <= 0) {
        $hundred = "Другие";
    }

    $grouped_agents[$hundred][$agent_num] = $data;
}

// Сортируем группы по возрастанию (400, 500, 600, 700)
ksort($grouped_agents);

// Сортируем операторов внутри каждой группы по количеству принятых вызовов (от большего к меньшему)
foreach ($grouped_agents as $hundred => &$agents_in_group) {
    uasort($agents_in_group, function($a, $b) {
        return $b['answered'] <=> $a['answered'];
    });
}
unset($agents_in_group);

function formatTime($seconds) {
    $hours = floor($seconds / 3600);
    $min = floor(($seconds % 3600) / 60);
    $sec = $seconds % 60;
    
    if ($hours > 0) {
        return sprintf("%d ч %02d мин %02d сек", $hours, $min, $sec);
    }
    return sprintf("%02d мин %02d сек", $min, $sec);
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Аналитика VoxDial</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; font-family: 'Segoe UI', system-ui, sans-serif; }
        .sidebar { background: #212529; min-height: 100vh; color: white; }
        .card { border: none; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .table-title { font-weight: bold; color: #495057; display: flex; align-items: center; }
        .filter-panel { background-color: #fff; border-radius: 10px; border: 1px solid #e3e6f0; }
        .group-header-row { background-color: #f1f3f9 !important; font-weight: bold; color: #2c3e50; font-size: 0.95rem; }
    </style>
</head>
<body>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-2 d-none d-md-block sidebar p-3">
            <h4 class="text-center mb-4"><i class="fa-solid fa-phone-volume me-2 text-info"></i>VoxDial</h4>
            <hr>
            <ul class="nav nav-pills flex-column mb-auto">
                <li class="nav-item"><a href="index.php" class="nav-link text-white"><i class="fa-solid fa-chart-pie me-2"></i>Кампании</a></li>
                <li><a href="create.php" class="nav-link text-white mt-2"><i class="fa-solid fa-plus me-2"></i>Создать обзвон</a></li>
                <li><a href="agents.php" class="nav-link text-white mt-2"><i class="fa-solid fa-users me-2"></i>Операторы</a></li>
                <li><a href="stats.php" class="nav-link text-white active bg-info mt-2"><i class="fa-solid fa-chart-column me-2"></i>Статистика</a></li>
            </ul>
        </div>

        <div class="col-md-10 p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h2>Сводный отчет по контакт-центру</h2>
                    <p class="text-muted mb-0">Данные за период: <span class="badge bg-info text-white fs-6"><?= date('d.m.Y', strtotime($date_start)) ?></span> — <span class="badge bg-info text-white fs-6"><?= date('d.m.Y', strtotime($date_end)) ?></span></p>
                </div>
            </div>

            <div class="card filter-panel p-3 mb-4 shadow-sm">
                <form method="GET" action="stats.php" id="filterForm" class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label small fw-bold text-muted"><i class="fa-regular fa-calendar-list me-1"></i>Выберите период</label>
                        <select name="period" id="periodSelect" class="form-select form-select-sm">
                            <option value="today" <?= $period == 'today' ? 'selected' : '' ?>>Сегодня</option>
                            <option value="week" <?= $period == 'week' ? 'selected' : '' ?>>Эта неделя</option>
                            <option value="month" <?= $period == 'month' ? 'selected' : '' ?>>Этот месяц</option>
                            <option value="last_month" <?= $period == 'last_month' ? 'selected' : '' ?>>Прошлый месяц</option>
                            <option value="custom" <?= $period == 'custom' ? 'selected' : '' ?>>Произвольный диапазон...</option>
                        </select>
                    </div>
                    
                    <div class="col-md-3 date-inputs" id="startDateBlock">
                        <label class="form-label small fw-bold text-muted">Дата начала</label>
                        <input type="date" name="date_start" id="date_start" class="form-control form-control-sm" value="<?= $date_start ?>">
                    </div>
                    
                    <div class="col-md-3 date-inputs" id="endDateBlock">
                        <label class="form-label small fw-bold text-muted">Дата окончания</label>
                        <input type="date" name="date_end" id="date_end" class="form-control form-control-sm" value="<?= $date_end ?>">
                    </div>
                    
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-info btn-sm text-white w-100 fw-bold"><i class="fa-solid fa-filter me-1"></i> Сформировать отчет</button>
                    </div>
                </form>
            </div>

            <div class="card p-4 mb-4">
                <h5 class="table-title mb-3"><i class="fa-solid fa-users-viewfinder text-info me-2"></i> 1. Статистика по очередям (Всего / Успех / Потери)</h5>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Номер очереди</th>
                                <th>Описание</th>
                                <th>Всего вызовов</th>
                                <th class="text-success">Принято (Ответ)</th>
                                <th class="text-danger">Пропущено / Сброшено</th>
                                <th>Уровень обслуживания (SL)</th>
                                <th>Ср. время на линии</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($queue_stats as $q_num => $q_data): 
                                $sl_percent = $q_data['entered'] > 0 ? round(($q_data['answered'] / $q_data['entered']) * 100) : 0;
                                $avg_hold = $q_data['entered'] > 0 ? round($q_data['total_hold_time'] / $q_data['entered']) : 0;
                            ?>
                                <tr>
                                    <td>
                                        <a href="call_details.php?type=queue&val=<?= urlencode($q_num) ?><?= $url_params ?>" class="text-decoration-none">
                                            <span class="badge bg-secondary p-2 fs-6" style="cursor: pointer;"><i class="fa-solid fa-magnifying-glass-chart me-1"></i> Queue <?= htmlspecialchars($q_num) ?></span>
                                        </a>
                                    </td>
                                    <td><strong class="text-dark"><?= htmlspecialchars($q_data['descr'] ?: 'Без описания') ?></strong></td>
                                    <td><span class="fw-bold fs-6"><?= $q_data['entered'] ?></span></td>
                                    <td><span class="badge bg-success px-3 py-2"><?= $q_data['answered'] ?></span></td>
                                    <td><span class="badge bg-danger px-3 py-2"><?= $q_data['abandoned'] ?></span></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="progress w-100 me-2" style="height: 8px;">
                                                <div class="progress-bar bg-success" style="width: <?= $sl_percent ?>%"></div>
                                            </div>
                                            <small class="fw-bold"><?= $sl_percent ?>%</small>
                                        </div>
                                    </td>
                                    <td><span class="text-muted fw-bold"><i class="fa-regular fa-clock me-1"></i> <?= $avg_hold ?> сек.</span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card p-4">
                <h5 class="table-title mb-3"><i class="fa-solid fa-user-gear text-info me-2"></i> 2. Персональная эффективность операторов</h5>
                <div class="table-responsive">
                    <table class="table table-hover align-middle border">
                        <thead class="table-light">
                            <tr>
                                <th>Оператор</th>
                                <th class="text-success"><i class="fa-solid fa-phone me-1"></i> Принято вызовов</th>
                                <th class="text-info"><i class="fa-solid fa-bolt me-1"></i> Эффективные (&gt;10 сек)</th>
                                <th class="text-danger"><i class="fa-solid fa-phone-slash me-1"></i> Пропущено (Игнор)</th>
                                <th>Успешность ответов (KPI)</th>
                                <th>Общее время разговоров за период</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($grouped_agents)): ?>
                                <tr><td colspan="6" class="text-center text-muted py-3">Операторы за указанный период времени еще не принимали звонки.</td></tr>
                            <?php else: ?>
                                <?php foreach ($grouped_agents as $hundred => $agents_in_group): ?>
                                    <tr class="group-header-row">
                                        <td colspan="6" class="py-2 px-3">
                                            <i class="fa-solid fa-folder-open text-warning me-2"></i> 
                                            <?= is_numeric($hundred) ? "Группа операторов {$hundred}-х номеров" : $hundred ?> 
                                            <span class="badge bg-secondary ms-2 fw-normal fs-7"><?= count($agents_in_group) ?> аг.</span>
                                        </td>
                                    </tr>

                                    <?php foreach ($agents_in_group as $agent_num => $data): 
                                        $total_attempts = $data['answered'] + $data['missed'];
                                        $kpi_percent = $total_attempts > 0 ? round(($data['answered'] / $total_attempts) * 100) : 0;
                                        $kpi_class = $kpi_percent >= 80 ? 'bg-success' : ($kpi_percent >= 50 ? 'bg-warning text-dark' : 'bg-danger');
                                    ?>
                                        <tr>
                                            <td style="padding-left: 25px;">
                                                <div class="d-flex align-items-center">
                                                    <div class="rounded-circle bg-light p-2 text-muted me-2" style="width: 35px; height: 35px; display: flex; align-items: center; justify-content: center;">
                                                        <i class="fa-solid fa-user-tie"></i>
                                                    </div>
                                                    <a href="call_details.php?type=agent&val=<?= urlencode($agent_num) ?><?= $url_params ?>" class="text-decoration-none text-dark fw-bold">
                                                        <i class="fa-solid fa-magnifying-glass-chart text-info me-1"></i> Внутренний <?= htmlspecialchars($agent_num) ?>
                                                    </a>
                                                </div>
                                            </td>
                                            <td><span class="fs-5 fw-bold text-success"><?= $data['answered'] ?></span></td>
                                            <td><span class="fs-5 fw-bold text-info"><?= $data['effective'] ?></span></td>
                                            <td><span class="fs-5 fw-bold text-danger"><?= $data['missed'] ?></span></td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="progress w-100 me-2" style="height: 8px;">
                                                        <div class="progress-bar bg-info" style="width: <?= $kpi_percent ?>%"></div>
                                                    </div>
                                                    <span class="badge <?= $kpi_class ?>"><?= $kpi_percent ?>%</span>
                                                </div>
                                            </td>
                                            <td><span class="fw-bold text-secondary"><i class="fa-regular fa-hourglass-half me-1"></i> <?= formatTime($data['total_talk_time']) ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const periodSelect = document.getElementById('periodSelect');
    const startInput = document.getElementById('date_start');
    const endInput = document.getElementById('date_end');

    function toggleDateInputs() {
        if (periodSelect.value !== 'custom') {
            startInput.readOnly = true;
            endInput.readOnly = true;
            startInput.classList.add('bg-light');
            endInput.classList.add('bg-light');
        } else {
            startInput.readOnly = false;
            endInput.readOnly = false;
            startInput.classList.remove('bg-light');
            endInput.classList.remove('bg-light');
        }
    }

    periodSelect.addEventListener('change', function() {
        toggleDateInputs();
        if (periodSelect.value !== 'custom') {
            document.getElementById('filterForm').submit();
        }
    });

    toggleDateInputs();
});
</script>
</body>
</html>

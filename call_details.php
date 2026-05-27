<?php
// call_details.php — Универсальная детализация с поддержкой переданных диапазонов дат
require_once __DIR__ . '/config.php';

$type = isset($_GET['type']) ? $_GET['type'] : ''; 
$val  = isset($_GET['val'])  ? trim($_GET['val']) : '';  

if (empty($type) || empty($val)) {
    die("Ошибка: Неверные параметры вызова детализации.");
}

// Принимаем параметры периода, присланные со страницы stats.php
$period     = isset($_GET['period']) ? $_GET['period'] : 'today';
$date_start = isset($_GET['date_start']) ? $_GET['date_start'] : date('Y-m-d');
$date_end   = isset($_GET['date_end']) ? $_GET['date_end'] : date('Y-m-d');

// Автоматически вычисляем интервалы, чтобы они строго совпадали со stats.php
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
$sql_end   = $date_end . ' 23:59:59';

// Генерируем суффикс для ссылок пагинации внутри страницы
$url_params = "&type=" . urlencode($type) . "&val=" . urlencode($val) . "&period=" . urlencode($period) . "&date_start=" . urlencode($date_start) . "&date_end=" . urlencode($date_end);

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
if (!in_array($limit, [50, 100, 200])) {
    $limit = 50;
}
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

try {
    $pdo_cdr = db_pdo('cdr');

    if ($type === 'queue') {
        $title_text = "Детализация вызовов по очереди $val";
        
        $count_query = "SELECT COUNT(*) FROM cdr WHERE calldate BETWEEN :sql_start AND :sql_end AND lastapp = 'Queue' AND (lastdata LIKE :q_exact OR lastdata LIKE :q_param)";
        $stmt_count = $pdo_cdr->prepare($count_query);
        $stmt_count->execute(['sql_start' => $sql_start, 'sql_end' => $sql_end, 'q_exact' => $val, 'q_param' => $val . ',%']);
        $total_rows = $stmt_count->fetchColumn();

        $data_query = "
            SELECT calldate, src, dst, duration, billsec, disposition, dstchannel,
                   (duration - billsec) as hold_time
            FROM cdr 
            WHERE calldate BETWEEN :sql_start AND :sql_end 
              AND lastapp = 'Queue' 
              AND (lastdata LIKE :q_exact OR lastdata LIKE :q_param)
            ORDER BY calldate DESC
            LIMIT :limit OFFSET :offset
        ";
        $stmt_data = $pdo_cdr->prepare($data_query);
        $stmt_data->bindValue(':sql_start', $sql_start, PDO::PARAM_STR);
        $stmt_data->bindValue(':sql_end', $sql_end, PDO::PARAM_STR);
        $stmt_data->bindValue(':q_exact', $val, PDO::PARAM_STR);
        $stmt_data->bindValue(':q_param', $val . ',%', PDO::PARAM_STR);
        $stmt_data->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt_data->bindValue(':offset', $offset, PDO::PARAM_INT);
        
    } elseif ($type === 'agent') {
        $title_text = "Детализация работы оператора $val";
        
        $agent_like1 = "%/" . $val . "@%"; 
        $agent_like2 = "%/" . $val . "-%"; 
        $agent_like3 = "%/" . $val;        

        $count_query = "
            SELECT COUNT(*) 
            FROM cdr 
            WHERE calldate BETWEEN :sql_start AND :sql_end 
              AND lastapp = 'Queue' 
              AND (dstchannel LIKE :al1 OR dstchannel LIKE :al2 OR dstchannel = :al3)
        ";
        $stmt_count = $pdo_cdr->prepare($count_query);
        $stmt_count->execute(['sql_start' => $sql_start, 'sql_end' => $sql_end, 'al1' => $agent_like1, 'al2' => $agent_like2, 'al3' => $agent_like3]);
        $total_rows = $stmt_count->fetchColumn();

        $data_query = "
            SELECT a.calldate, a.src, a.dst, a.duration, a.billsec, a.disposition, a.dstchannel,
                   COALESCE((
                       SELECT (c.duration - c.billsec) 
                       FROM cdr c 
                       WHERE c.linkedid = a.linkedid 
                         AND c.lastapp = 'Queue' 
                         AND c.dstchannel NOT LIKE '%/%' 
                       LIMIT 1
                   ), (a.duration - a.billsec)) as hold_time
            FROM cdr a
            WHERE a.calldate BETWEEN :sql_start AND :sql_end 
              AND a.lastapp = 'Queue' 
              AND (a.dstchannel LIKE :al1 OR a.dstchannel LIKE :al2 OR a.dstchannel = :al3)
            ORDER BY a.calldate DESC
            LIMIT :limit OFFSET :offset
        ";
        $stmt_data = $pdo_cdr->prepare($data_query);
        $stmt_data->bindValue(':sql_start', $sql_start, PDO::PARAM_STR);
        $stmt_data->bindValue(':sql_end', $sql_end, PDO::PARAM_STR);
        $stmt_data->bindValue(':al1', $agent_like1, PDO::PARAM_STR);
        $stmt_data->bindValue(':al2', $agent_like2, PDO::PARAM_STR);
        $stmt_data->bindValue(':al3', $agent_like3, PDO::PARAM_STR);
        $stmt_data->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt_data->bindValue(':offset', $offset, PDO::PARAM_INT);
    }

    $stmt_data->execute();
    $calls = $stmt_data->fetchAll(PDO::FETCH_ASSOC);
    
    $total_pages = ceil($total_rows / $limit);

} catch (PDOException $e) {
    die("Ошибка базы данных: " . $e->getMessage());
}

function formatTimeDuration($seconds) {
    $min = floor($seconds / 60);
    $sec = $seconds % 60;
    return $min > 0 ? "{$min} мин {$sec} сек" : "{$sec} сек";
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title><?= $title_text ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; font-family: 'Segoe UI', sans-serif; }
        .sidebar { background: #212529; min-height: 100vh; color: white; }
        .card { border: none; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
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
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <a href="stats.php" class="btn btn-outline-secondary btn-sm mb-2"><i class="fa-solid fa-arrow-left me-1"></i> Назад к статистике</a>
                    <h2><i class="fa-solid fa-list-check text-info me-2"></i><?= htmlspecialchars($title_text) ?></h2>
                    <p class="text-muted mb-0">Всего записей за сегодня: <strong><?= $total_rows ?></strong></p>
                </div>
                
                <div class="d-flex align-items-center bg-white p-2 rounded shadow-sm border">
                    <span class="me-2 text-muted small fw-bold">Показывать по:</span>
                    <div class="btn-group btn-group-sm">
                        <a href="?type=<?= $type ?>&val=<?= $val ?>&limit=50" class="btn btn-<?= $limit==50?'info text-white':'outline-secondary' ?>">50</a>
                        <a href="?type=<?= $type ?>&val=<?= $val ?>&limit=100" class="btn btn-<?= $limit==100?'info text-white':'outline-secondary' ?>">100</a>
                        <a href="?type=<?= $type ?>&val=<?= $val ?>&limit=200" class="btn btn-<?= $limit==200?'info text-white':'outline-secondary' ?>">200</a>
                    </div>
                </div>
            </div>

            <div class="card p-4">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Время вызова</th>
                                <th>Кто звонил (Клиент)</th>
                                <th>Назначение / Очередь</th>
                                <th>Канал оператора</th>
                                <th>Статус</th>
                                <th>Ожидание (До ответа)</th>
                                <th>Длительность разговора</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($calls)): ?>
                                <tr><td colspan="7" class="text-center text-muted py-4">Звонков по данному критерию не найдено.</td></tr>
                            <?php else: ?>
                                <?php foreach ($calls as $call): 
                                    $h_time = (int)$call['hold_time'];
                                    if ($h_time < 0) $h_time = 0;
                                    
                                    if ($call['disposition'] === 'ANSWERED') {
                                        $badge = '<span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2"><i class="fa-solid fa-circle-check me-1"></i>Отвечен</span>';
                                    } else {
                                        $badge = '<span class="badge bg-danger-subtle text-danger border border-danger-subtle px-3 py-2"><i class="fa-solid fa-circle-xmark me-1"></i>Пропущен</span>';
                                    }
                                ?>
                                    <tr>
                                        <td><span class="fw-bold text-dark"><?= date('H:i:s', strtotime($call['calldate'])) ?></span> <small class="text-muted ms-1"><?= date('d.m', strtotime($call['calldate'])) ?></small></td>
                                        <td><strong class="text-primary"><i class="fa-solid fa-phone-flip me-1 text-muted small"></i><?= htmlspecialchars($call['src']) ?></strong></td>
                                        <td><span class="badge bg-secondary"><?= htmlspecialchars($call['dst']) ?></span></td>
                                        <td><small class="text-muted fw-bold"><?= htmlspecialchars($call['dstchannel'] ?: '—') ?></small></td>
                                        <td><?= $badge ?></td>
                                        <td><span class="text-secondary fw-bold"><?= $h_time ?> сек.</span></td>
                                        <td>
                                            <?php if ($call['disposition'] === 'ANSWERED'): ?>
                                                <span class="fw-bold <?= (int)$call['billsec'] > 10 ? 'text-success' : 'text-warning' ?>">
                                                    <i class="fa-regular fa-clock me-1"></i><?= formatTimeDuration($call['billsec']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
			<?php if ($total_pages > 1): ?>
                    <nav class="mt-4">
                        <ul class="pagination pagination-sm justify-content-center">
                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="?page=<?= $page - 1 ?><?= $url_params ?>&limit=<?= $limit ?>"><i class="fa-solid fa-chevron-left"></i></a>
                            </li>

                            <?php 
                            $start_p = max(1, $page - 3);
                            $end_p = min($total_pages, $page + 3);
                            for ($i = $start_p; $i <= $end_p; $i++): 
                            ?>
                                <li class="page-item <?= $page == $i ? 'active' : '' ?>">
                                    <a class="page-link <?= $page == $i ? 'bg-info border-info text-white' : '' ?>" href="?page=<?= $i ?><?= $url_params ?>&limit=<?= $limit ?>"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>

                            <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                <a class="page-link" href="?page=<?= $page + 1 ?><?= $url_params ?>&limit=<?= $limit ?>"><i class="fa-solid fa-chevron-right"></i></a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>
</body>
</html>

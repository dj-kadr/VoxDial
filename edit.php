<?php
// edit.php — Страница редактирования параметров кампании с поддержкой Планировщика и выбором транка
require_once __DIR__ . '/config.php';

$campaign_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$asterisk_trunks = [];

try {
    $pdo = db_pdo('dialer');
    $pdo_asterisk = db_pdo('asterisk'); // Подключаемся к базе FreePBX для транков
    
    // Выгребаем активные транки из Asterisk (как на странице создания)
    $stmt_trunks = $pdo_asterisk->query("SELECT trunkid, tech, name FROM trunks WHERE disabled = 'off' ORDER BY name ASC");
    while ($row = $stmt_trunks->fetch(PDO::FETCH_ASSOC)) {
        $asterisk_trunks[] = $row;
    }
} catch (PDOException $e) {
    die("Ошибка подключения к БД: " . $e->getMessage());
}

// Получаем текущие данные кампании
$stmt = $pdo->prepare("SELECT * FROM campaigns WHERE id = ?");
$stmt->execute([$campaign_id]);
$campaign = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$campaign) {
    die("Кампания не найдена!");
}

$dest_type = isset($campaign['destination_type']) ? $campaign['destination_type'] : 'queue';
$dest_val = !empty($campaign['destination_value']) ? $campaign['destination_value'] : $campaign['queue_num'];

// Настройки планировщика
$start_immediately = isset($campaign['start_immediately']) ? (int)$campaign['start_immediately'] : 1;
$scheduled_start_time = !empty($campaign['scheduled_start_time']) ? substr($campaign['scheduled_start_time'], 0, 5) : '';
$scheduled_pause_time = !empty($campaign['scheduled_pause_time']) ? substr($campaign['scheduled_pause_time'], 0, 5) : '';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Редактировать кампанию #<?= $campaign['id'] ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; font-family: 'Segoe UI', system-ui, sans-serif; }
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
                <li><a href="index.php" class="nav-link text-white active bg-info"><i class="fa-solid fa-chart-pie me-2"></i>Кампании</a></li>
                <li><a href="create.php" class="nav-link text-white mt-2"><i class="fa-solid fa-plus me-2"></i>Создать обзвон</a></li>
                <li><a href="agents.php" class="nav-link text-white mt-2"><i class="fa-solid fa-users me-2"></i>Операторы</a></li>
                <li><a href="stats.php" class="nav-link text-white mt-2"><i class="fa-solid fa-chart-column me-2"></i>Статистика</a></li>
                <li><a href="blacklist.php" class="nav-link text-white mt-2"><i class="fa-solid fa-user-slash me-2"></i>Стоп-лист</a></li>
            </ul>
        </div>

        <div class="col-md-10 p-4">
            <div class="mb-4">
                <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i> Назад к списку</a>
                <h2 class="mt-2">Редактирование параметров кампании</h2>
                <p class="text-muted">Кампания: <strong><?= htmlspecialchars($campaign['name']) ?></strong> (<?= $dest_type === 'ivr' ? '🤖 IVR: ' : '🎧 Очередь: ' ?><?= htmlspecialchars($dest_val) ?>)</p>
            </div>

            <div class="card p-4 col-lg-8 mb-5">
                <form action="update_campaign.php" method="POST">
                    <input type="hidden" name="id" value="<?= $campaign['id'] ?>">

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label text-muted">Название кампании</label>
                            <input type="text" class="form-color form-control bg-light" value="<?= htmlspecialchars($campaign['name']) ?>" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted">Направление вызовов</label>
                            <input type="text" class="form-control bg-light" value="<?= $dest_type === 'ivr' ? 'IVR Меню (ID: '.$dest_val.')' : 'Очередь FreePBX ('.$dest_val.')' ?>" readonly>
                        </div>
                    </div>

                    <hr class="my-4">

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-bold text-primary"><i class="fa-solid fa-sim-card me-1"></i>Исходящий номер / Транк</label>
                            <select name="trunk_id" class="form-select border-primary" required>
                                <?php foreach ($asterisk_trunks as $trunk): ?>
                                    <option value="<?= htmlspecialchars($trunk['trunkid']) ?>" <?= (int)$campaign['trunk_id'] === (int)$trunk['trunkid'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($trunk['name']) ?> (<?= htmlspecialchars($trunk['tech']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Через этот транк провайдера полетят звонки.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold text-dark">Лимит каналов (макс. одновременных)</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fa-solid fa-bars-staggered text-muted"></i></span>
                                <input type="number" name="channel_limit" class="form-control" value="<?= (int)$campaign['channel_limit'] ?>" min="1" max="100" required>
                            </div>
                            <div class="form-text">Максимум одновременных звонков в линии.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold text-dark">Время ожидания ответа (сек)</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fa-solid fa-clock text-muted"></i></span>
                                <input type="number" name="ring_time" class="form-control" value="<?= (int)$campaign['ring_time'] ?>" min="5" max="120" required>
                            </div>
                            <div class="form-text">Сколько секунд Астериск будет вызывать абонента (Ringtime).</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold text-dark">Успешный разговор от (сек)</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fa-solid fa-hourglass-half text-muted"></i></span>
                                <input type="number" name="min_success_duration" class="form-control" value="<?= (int)$campaign['min_success_duration'] ?>" min="0" max="300" required>
                            </div>
                            <div class="form-text">Минимальное время ответа для зачисления "Успешно".</div>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label fw-bold text-dark">Количество попыток</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fa-solid fa-rotate-left text-muted"></i></span>
                                <input type="number" name="max_retries" class="form-control" value="<?= (int)$campaign['max_retries'] ?>" min="1" max="10" required>
                            </div>
                            <div class="form-text">Max Retries.</div>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label fw-bold text-dark">Пауза попыток (сек)</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fa-solid fa-circle-pause text-muted"></i></span>
                                <input type="number" name="retry_time" class="form-control" value="<?= (int)$campaign['retry_time'] ?>" min="5" max="3600" required>
                            </div>
                            <div class="form-text">Интервал перезвона.</div>
                        </div>
                    </div>

                    <hr class="my-4">

                    <div class="card p-3 bg-light border-0">
                        <h5 class="fw-bold mb-3 text-secondary" style="font-size: 1.05rem;"><i class="fa-solid fa-calendar-clock text-info me-2"></i>Планировщик автозапуска и паузы</h5>
                        
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="start_immediately" id="startImmediately" value="1" <?= $start_immediately === 1 ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold text-dark" for="startImmediately">Игнорировать расписание (Управление вручную / Немедленно)</label>
                        </div>

                        <div class="row g-3 <?= $start_immediately === 1 ? 'd-none' : '' ?>" id="schedulerFields">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted">Время автоматического запуска (каждый день)</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fa-solid fa-play text-success"></i></span>
                                    <input type="time" name="scheduled_start_time" class="form-control" value="<?= htmlspecialchars($scheduled_start_time) ?>">
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted">Время автоматической паузы (каждый день)</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fa-solid fa-pause text-warning"></i></span>
                                    <input type="time" name="scheduled_pause_time" class="form-control" value="<?= htmlspecialchars($scheduled_pause_time) ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 pt-2 d-flex justify-content-end">
                        <a href="index.php" class="btn btn-light me-2 fw-bold">Отмена</a>
                        <button type="submit" class="btn btn-info text-white fw-bold">
                            <i class="fa-solid fa-floppy-disk me-1"></i> Сохранить изменения
                        </button>
                    </div>

                </form>
            </div>
        </div>
    </div>
</div>

<script>
    document.getElementById('startImmediately').addEventListener('change', function() {
        const schedulerFields = document.getElementById('schedulerFields');
        if (this.checked) {
            schedulerFields.classList.add('d-none');
        } else {
            schedulerFields.classList.remove('d-none');
        }
    });
</script>
</body>
</html>

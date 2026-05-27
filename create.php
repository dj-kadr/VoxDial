<?php
// Доступы к базам данных
require_once __DIR__ . '/config.php';

$asterisk_queues = [];
$asterisk_trunks = [];
$asterisk_ivrs = []; // Массив для IVR

try {
    // Подключаемся напрямую к MySQL FreePBX
    $db = db_pdo('asterisk');
    
    // 1. Выбираем очереди
    $stmt_queues = $db->query("SELECT extension, descr FROM queues_config ORDER BY extension ASC");
    while ($row = $stmt_queues->fetch(PDO::FETCH_ASSOC)) {
        $asterisk_queues[] = [$row['extension'], $row['descr']];
    }

    // 2. Выбираем активные транки
    $stmt_trunks = $db->query("SELECT trunkid, tech, name FROM trunks WHERE disabled = 'off' ORDER BY name ASC");
    while ($row = $stmt_trunks->fetch(PDO::FETCH_ASSOC)) {
        $asterisk_trunks[] = $row;
    }

    // 3. АВТОМАТИЧЕСКИ ВЫГРЕБАЕМ IVR МЕНЮ ДЛЯ НОВОГО ФУНКЦИОНАЛА
    $stmt_ivrs = $db->query("SELECT id, name FROM ivr_details ORDER BY name ASC");
    while ($row = $stmt_ivrs->fetch(PDO::FETCH_ASSOC)) {
        $asterisk_ivrs[] = $row;
    }

} catch (PDOException $e) {
    error_log("Ошибка подключения к FreePBX DB: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Создать кампанию обзвона</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; font-family: 'Segoe UI', system-ui, sans-serif; }
        .sidebar { background: #212529; min-height: 100vh; color: white; }
        .card { border: none; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .file-drop-area { border: 2px dashed #ced4da; padding: 2rem; text-align: center; border-radius: 10px; background-color: #fff; cursor: pointer; transition: 0.3s; }
        .file-drop-area:hover { border-color: #0dcaf0; background-color: #f1fbfc; }
    </style>
</head>
<body>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-2 sidebar p-3 d-none d-md-block">
            <h4 class="text-center mb-4"><i class="fa-solid fa-phone-volume me-2 text-info"></i>VoxDial</h4>
            <hr>
            <ul class="nav nav-pills flex-column mb-auto">
                <li class="nav-item"><a href="index.php" class="nav-link text-white"><i class="fa-solid fa-chart-pie me-2"></i>Кампании</a></li>
                <li><a href="create.php" class="nav-link text-white active bg-info mt-2"><i class="fa-solid fa-plus me-2"></i>Создать обзвон</a></li>
                <li><a href="agents.php" class="nav-link text-white mt-2"><i class="fa-solid fa-users me-2"></i>Операторы</a></li>
                <li><a href="stats.php" class="nav-link text-white mt-2"><i class="fa-solid fa-chart-column me-2"></i>Статистика</a></li>
            </ul>
        </div>

        <div class="col-md-8 p-4 mx-auto">
            <div class="mb-4">
                <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-arrow-left me-1"></i> Назад</a>
                <h2 class="mt-2">Импорт базы и создание кампании</h2>
            </div>

            <div class="card p-4">
                <form action="upload.php" method="POST" enctype="multipart/form-data">
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Название кампании</label>
                        <input type="text" name="campaign_name" class="form-control form-control-lg" placeholder="Например: Обзвон холодной базы (Май)" required>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Лимит каналов (макс.)</label>
                            <input type="number" name="channel_limit" class="form-control" value="5" min="1" max="100" required>
                            <small class="text-muted">Сколько линий займет этот обзвон</small>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">Время ожидания ответа (сек)</label>
                            <input type="number" name="ring_time" class="form-control" value="30" min="5" max="120" required>
                            <small class="text-muted">Сколько секунд ждем поднятия трубки</small>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-bold">Успешный разговор от (сек)</label>
                            <input type="number" name="min_success_duration" class="form-control" value="10" min="0" max="300" required>
                            <small class="text-muted">Длительность для засчитывания KPI</small>
                        </div>
                    </div>

                    <div class="row mb-4">
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Количество попыток (Max Retries)</label>
                            <input type="number" name="max_retries" class="form-control" value="1" min="1" max="10" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Пауза между попытками (сек)</label>
                            <input type="number" name="retry_time" class="form-control" value="10" min="5" max="3600" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold text-primary">Куда слать звонок?</label>
                            <select name="destination_type" id="destination_type" class="form-select" onchange="toggleDestFields()" required>
                                <option value="queue" selected>В Очередь (на операторов)</option>
                                <option value="ivr">На IVR (голосовой робот)</option>
                            </select>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6" id="queue_selector_container">
                            <label class="form-label fw-bold text-success"><i class="fa-solid fa-headset me-1"></i>Очередь Asterisk (Queue)</label>
                            <select name="queue_num" id="queue_num" class="form-select">
                                <option value="" disabled selected>Выберите активную очередь...</option>
                                <?php foreach ($asterisk_queues as $queue): ?>
                                    <option value="<?= htmlspecialchars($queue[0]) ?>">
                                        Очередь <?= htmlspecialchars($queue[0]) ?> (<?= htmlspecialchars($queue[1]) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6 d-none" id="ivr_selector_container">
                            <label class="form-label fw-bold text-info"><i class="fa-solid fa-robot me-1"></i>Голосовое меню IVR</label>
                            <select name="ivr_id" id="ivr_id" class="form-select">
                                <option value="" disabled selected>Выберите IVR меню...</option>
                                <?php foreach ($asterisk_ivrs as $ivr): ?>
                                    <option value="<?= htmlspecialchars($ivr['id']) ?>">
                                        IVR: <?= htmlspecialchars($ivr['name']) ?> (ID: <?= htmlspecialchars($ivr['id']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Исходящий номер / Транк</label>
                            <select name="trunk_id" class="form-select" required>
                                <option value="" disabled selected>Выберите транк для обзвона...</option>
                                <?php foreach ($asterisk_trunks as $trunk): ?>
                                    <option value="<?= htmlspecialchars($trunk['trunkid']) ?>">
                                        <?= htmlspecialchars($trunk['name']) ?> (<?= htmlspecialchars($trunk['tech']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-bold">Файл с номерами телефонов</label>
                        <div class="file-drop-area" id="drop-area">
                            <i class="fa-solid fa-file-excel text-muted fs-1 mb-2"></i>
                            <h5>Перетащите файл сюда или нажмите для выбора</h5>
                            <span class="text-muted small">Поддерживаются форматы .CSV и .XLSX</span>
                            <input type="file" name="file" id="file-input" class="d-none" accept=".csv,.xlsx" required>
                            <div id="file-name" class="mt-2 text-info fw-bold"></div>
                        </div>
                    </div>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-info btn-lg text-white fw-bold">
                            <i class="fa-solid fa-cloud-arrow-up me-2"></i> Создать и запустить импорт
                        </button>
                    </div>

                </form>
            </div>
        </div>
    </div>
</div>

<script>
    // Родной скрипт загрузки файлов
    const dropArea = document.getElementById('drop-area');
    const fileInput = document.getElementById('file-input');
    const fileNameDiv = document.getElementById('file-name');

    dropArea.addEventListener('click', () => fileInput.click());
    fileInput.addEventListener('change', function() {
        if (this.files.length > 0) {
            fileNameDiv.textContent = "Выбран файл: " + this.files[0].name;
        }
    });

    // Динамическое переключение Черг и IVR
    function toggleDestFields() {
        const destType = document.getElementById('destination_type').value;
        const queueContainer = document.getElementById('queue_selector_container');
        const ivrContainer = document.getElementById('ivr_selector_container');
        const queueSelect = document.getElementById('queue_num');
        const ivrSelect = document.getElementById('ivr_id');

        if (destType === 'queue') {
            queueContainer.classList.remove('d-none');
            ivrContainer.classList.add('d-none');
            queueSelect.setAttribute('required', 'required');
            ivrSelect.removeAttribute('required');
            ivrSelect.value = "";
        } else {
            queueContainer.classList.add('d-none');
            ivrContainer.classList.remove('d-none');
            ivrSelect.setAttribute('required', 'required');
            queueSelect.removeAttribute('required');
            queueSelect.value = "";
        }
    }
    
    // Запускаем проверку при старте, чтобы проставить требуемые валидации
    toggleDestFields();
</script>
</body>
</html>

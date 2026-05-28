<?php
// blacklist.php — Управление стоп-листом с поддержкой массового импорта из Excel (.xlsx) и CSV
require_once __DIR__ . '/SimpleXLSX.php'; // Подключаем твою библиотеку парсинга XLSX
use Shuchkin\SimpleXLSX;

date_default_timezone_set('Europe/Kyiv');

$db_host = 'localhost';
$db_name = 'dialer'; 
$db_user = 'root';      
$db_pass = 'IT-Premium';        

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Ошибка подключения к БД: " . $e->getMessage());
}

$message = '';

// =========================================================================
// 1. ОБРАБОТКА ПОСТ-ЗАПРОСОВ (ДОБАВЛЕНИЕ ОДИНОЧНОЕ ИЛИ ИМПОРТ ФАЙЛА)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    
    // ВАРИАНТ А: Загрузка через ФАЙЛ (Excel / CSV)
    if (isset($_FILES['file']) && !empty($_FILES['file']['tmp_name'])) {
        $file = $_FILES['file']['tmp_name'];
        $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        $file_description = !empty($_POST['description']) ? trim($_POST['description']) : 'Массовый импорт из файла';

        $inserted_count = 0;
        $duplicate_count = 0;

        try {
            $pdo->beginTransaction(); // Запускаем транзакцию для высокой скорости вставки
            $insert_stmt = $pdo->prepare("INSERT INTO blacklist (phone, description) VALUES (?, ?)");

            if ($ext === 'xlsx') {
                // Обработка XLSX (как в твоем upload.php)
                if ($xlsx = SimpleXLSX::parse($file)) {
                    foreach ($xlsx->rows() as $row) {
                        if (!isset($row[0]) || empty($row[0])) continue;
                        
                        $phone = preg_replace('/[^0-9]/', '', $row[0]);
                        $desc = isset($row[1]) && !empty(trim($row[1])) ? trim($row[1]) : $file_description;
                        
                        if (!empty($phone)) {
                            try {
                                $insert_stmt->execute([$phone, $desc]);
                                $inserted_count++;
                            } catch (PDOException $ex) {
                                if ($ex->getCode() == 23000) $duplicate_count++; // Дубликат
                                else throw $ex;
                            }
                        }
                    }
                }
            } elseif ($ext === 'csv') {
                // Обработка CSV (как в твоем upload.php)
                if (($handle = fopen($file, "r")) !== FALSE) {
                    while (($data = fgetcsv($handle, 1000, ";")) !== FALSE) {
                        if (!isset($data[0]) || empty($data[0])) continue;
                        
                        $phone = preg_replace('/[^0-9]/', '', $data[0]);
                        $desc = isset($data[1]) && !empty(trim($data[1])) ? trim($data[1]) : $file_description;
                        
                        if (!empty($phone)) {
                            try {
                                $insert_stmt->execute([$phone, $desc]);
                                $inserted_count++;
                            } catch (PDOException $ex) {
                                if ($ex->getCode() == 23000) $duplicate_count++;
                                else throw $ex;
                            }
                        }
                    }
                    fclose($handle);
                }
            }

            $pdo->commit(); // Фиксируем изменения в базе
            
            $message = '<div class="alert alert-success">Импорт завершен! Успешно добавлено номеров: <strong>' . $inserted_count . '</strong>.';
            if ($duplicate_count > 0) {
                $message .= ' Пропущено дубликатов (уже были в списке): <strong>' . $duplicate_count . '</strong>.';
            }
            $message .= '</div>';

        } catch (Exception $e) {
            $pdo->rollBack();
            $message = '<div class="alert alert-danger">Ошибка при парсинге файла: ' . $e->getMessage() . '</div>';
        }

    } 
    // ВАРИАНТ Б: Ручное добавление одного номера
    else {
        $phone = preg_replace('/[^0-9]/', '', $_POST['phone']);
        $description = !empty($_POST['description']) ? trim($_POST['description']) : 'Добавлен вручную';

        if (!empty($phone)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO blacklist (phone, description) VALUES (?, ?)");
                $stmt->execute([$phone, $description]);
                $message = '<div class="alert alert-success">Номер <strong>'.$phone.'</strong> успешно добавлен в стоп-лист!</div>';
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $message = '<div class="alert alert-warning">Этот номер уже есть в стоп-листе!</div>';
                } else {
                    $message = '<div class="alert alert-danger">Ошибка: ' . $e->getMessage() . '</div>';
                }
            }
        }
    }
}

// =========================================================================
// 2. УДАЛЕНИЕ НОМЕРА ИЗ СТОП-ЛИСТА
// =========================================================================
if (isset($_GET['act']) && $_GET['act'] === 'delete' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $pdo->prepare("DELETE FROM blacklist WHERE id = ?");
    $stmt->execute([$id]);
    header("Location: blacklist.php");
    exit;
}

// =========================================================================
// 3. ЗАГРУЗКА ДАННЫХ ДЛЯ ОТОБРАЖЕНИЯ
// =========================================================================
$blacklist = $pdo->query("SELECT * FROM blacklist ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Стоп-лист — VoxDial</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; font-family: 'Segoe UI', system-ui, sans-serif; }
        .sidebar { background: #212529; min-height: 100vh; color: white; }
        .card { border: none; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .file-drop-area { border: 2px dashed #ced4da; padding: 1.5rem; text-align: center; border-radius: 10px; background-color: #fff; cursor: pointer; transition: 0.3s; }
        .file-drop-area:hover { border-color: #dc3545; background-color: #fff5f5; }
    </style>
</head>
<body>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-2 d-none d-md-block sidebar p-3">
            <h4 class="text-center mb-4"><i class="fa-solid fa-phone-volume me-2 text-info"></i>VoxDial</h4>
            <hr>
            <ul class="nav nav-pills flex-column mb-auto">
                <li><a href="index.php" class="nav-link text-white"><i class="fa-solid fa-chart-pie me-2"></i>Кампании</a></li>
                <li><a href="create.php" class="nav-link text-white mt-2"><i class="fa-solid fa-plus me-2"></i>Создать обзвон</a></li>
                <li><a href="agents.php" class="nav-link text-white mt-2"><i class="fa-solid fa-users me-2"></i>Операторы</a></li>
                <li><a href="stats.php" class="nav-link text-white mt-2"><i class="fa-solid fa-chart-column me-2"></i>Статистика</a></li>
                <li><a href="blacklist.php" class="nav-link text-white active bg-info mt-2"><i class="fa-solid fa-user-slash me-2"></i>Стоп-лист</a></li>
            </ul>
        </div>

        <div class="col-md-10 p-4">
            <div class="mb-4">
                <h2><i class="fa-solid fa-user-slash text-danger me-2"></i>Управление Стоп-листом</h2>
                <p class="text-muted">Номера из этого списка будут автоматически пропускаться фоновым роботом во всех кампаниях.</p>
            </div>

            <?= $message ?>

            <div class="row g-4">
                <div class="col-lg-4">
                    <div class="card p-4">
                        <h5 class="fw-bold mb-3">Добавить в Стоп-лист</h5>
                        <form action="blacklist.php" method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="add">
                            
                            <ul class="nav nav-tabs mb-3" id="importTab" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active py-1 small fw-bold" id="manual-tab" data-bs-toggle="tab" data-bs-target="#manual-panel" type="button" role="tab">Вручную</button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link py-1 small fw-bold text-danger" id="file-tab" data-bs-toggle="tab" data-bs-target="#file-panel" type="button" role="tab">Импорт файла</button>
                                </li>
                            </ul>

                            <div class="tab-content" id="importTabContent">
                                <div class="tab-pane fade show active" id="manual-panel" role="tabpanel">
                                    <div class="mb-3">
                                        <label class="form-label small fw-bold">Номер телефона</label>
                                        <input type="text" name="phone" id="manual_phone" class="form-control" placeholder="Например: 0964469648">
                                    </div>
                                </div>

                                <div class="tab-pane fade" id="file-panel" role="tabpanel">
                                    <div class="mb-3">
                                        <label class="form-label small fw-bold">Выберите файл базы</label>
                                        <div class="file-drop-area" id="drop-area">
                                            <i class="fa-solid fa-file-csv text-danger fs-2 mb-2"></i>
                                            <h6>Нажмите для выбора файла абонентов</h6>
                                            <span class="text-muted small text-center d-block">Поддерживаются .XLSX и .CSV</span>
                                            <input type="file" name="file" id="file-input" class="d-none" accept=".csv,.xlsx">
                                            <div id="file-name" class="mt-2 text-danger fw-bold small"></div>
                                        </div>
                                        <div class="form-text mt-1 text-muted">Робот прочитает номера из <strong>первой колонки</strong> файла. Вторая колонка (если есть) станет примечанием.</div>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-bold">Примечание / Причина блокировки</label>
                                <textarea name="description" class="form-control" rows="2" placeholder="Например: VIP / Просил больше не звонить"></textarea>
                            </div>

                            <button type="submit" class="btn btn-danger w-100 fw-bold py-2" id="submit-btn">
                                <i class="fa-solid fa-user-plus me-1"></i> Выполнить добавление
                            </button>
                        </form>
                    </div>
                </div>

                <div class="col-lg-8">
                    <div class="card p-4">
                        <h5 class="fw-bold mb-3">Заблокированные номера в базе (Всего: <?= count($blacklist) ?>)</h5>
                        <div class="table-responsive" style="max-height: 550px; overflow-y: auto;">
                            <table class="table table-hover align-middle">
                                <thead class="table-light sticky-top">
                                    <tr>
                                        <th>Телефон</th>
                                        <th>Примечание / Причина</th>
                                        <th>Дата блокировки</th>
                                        <th style="width: 10%; text-align: center;">Действие</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($blacklist)): ?>
                                        <tr><td colspan="4" class="text-center text-muted py-4">Стоп-лист пуст. Система будет осуществлять вызовы по всем номерам.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($blacklist as $item): ?>
                                            <tr>
                                                <td><strong class="text-dark"><i class="fa-solid fa-phone text-muted me-2"></i><?= htmlspecialchars($item['phone']) ?></strong></td>
                                                <td><span class="text-secondary"><?= htmlspecialchars($item['description'] ?: '—') ?></span></td>
                                                <td><small class="text-muted"><?= date('d.m.Y H:i', strtotime($item['created_at'])) ?></small></td>
                                                <td style="text-align: center;">
                                                    <a href="blacklist.php?act=delete&id=<?= $item['id'] ?>" class="btn btn-sm btn-outline-success" onclick="return confirm('Удалить номер из стоп-листа и разрешить роботу вызовы на него?')" title="Разблокировать номер">
                                                        <i class="fa-solid fa-unlock"></i>
                                                    </a>
                                                </td>
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
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const dropArea = document.getElementById('drop-area');
    const fileInput = document.getElementById('file-input');
    const fileNameDiv = document.getElementById('file-name');
    const manualPhoneInput = document.getElementById('manual_phone');

    // Клик по зоне открывает выбор файла
    dropArea.addEventListener('click', () => fileInput.click());
    
    fileInput.addEventListener('change', function() {
        if (this.files.length > 0) {
            fileNameDiv.textContent = "Выбран файл: " + this.files[0].name;
            manualPhoneInput.removeAttribute('required'); // Снимаем обязательность с ручного поля
            manualPhoneInput.value = ""; // Очищаем ручной ввод
        }
    });

    // Настройка валидации перед отправкой: либо номер, либо файл должен быть выбран
    document.getElementById('submit-btn').addEventListener('click', function(e) {
        const activeTab = document.querySelector('.nav-link.active').id;
        if (activeTab === 'manual-tab' && manualPhoneInput.value.trim() === '') {
            manualPhoneInput.setAttribute('required', 'required');
        } else if (activeTab === 'file-tab' && fileInput.files.length === 0) {
            alert('Пожалуйста, выберите файл для импорта абонентов!');
            e.preventDefault();
        }
    });

    // Очистка при переключении вкладок
    document.getElementById('manual-tab').addEventListener('click', () => {
        fileInput.value = "";
        fileNameDiv.textContent = "";
    });
    document.getElementById('file-tab').addEventListener('click', () => {
        manualPhoneInput.value = "";
    });
</script>
</body>
</html>

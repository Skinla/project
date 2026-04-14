<?php
// add-to-exceptions.php
// Веб-интерфейс для добавления доменов в исключения

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/error_handler.php';

header('Content-Type: text/html; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

$config = require __DIR__ . '/config.php';
$message = '';

// Обработка POST запроса
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $domain = trim($_POST['domain'] ?? '');
    $reason = trim($_POST['reason'] ?? 'Добавлено в исключения через веб-интерфейс');
    $normalizer = trim($_POST['normalizer'] ?? 'generic_normalizer.php');
    
    if ($domain) {
        $errorHandler = new ErrorHandler($config);
        
        if ($errorHandler->addToExceptions($domain, $reason, $normalizer)) {
            $message = "Домен '$domain' успешно добавлен в исключения";
        } else {
            $message = "Ошибка при добавлении домена '$domain' в исключения";
        }
    } else {
        $message = "Ошибка: не указан домен";
    }
}

// Получаем параметры из URL
$file = $_GET['file'] ?? '';
$type = $_GET['type'] ?? '';

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Добавление домена в исключения</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .container { max-width: 600px; margin: 0 auto; }
        .message { padding: 10px; margin: 10px 0; border-radius: 5px; }
        .success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .form-group { margin: 15px 0; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input, select, textarea { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; }
        textarea { height: 80px; resize: vertical; }
        .btn { padding: 10px 20px; background-color: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; }
        .btn:hover { background-color: #0056b3; }
        .btn-secondary { background-color: #6c757d; }
        .btn-secondary:hover { background-color: #545b62; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Добавление домена в исключения</h1>
        
        <?php if ($message): ?>
            <div class="message <?= strpos($message, 'Ошибка') === 0 ? 'error' : 'success' ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>
        
        <form method="post">
            <div class="form-group">
                <label for="domain">Домен:</label>
                <input type="text" id="domain" name="domain" value="<?= htmlspecialchars($file ? str_replace('raw_', '', str_replace('.json', '', $file)) : '') ?>" required>
            </div>
            
            <div class="form-group">
                <label for="reason">Причина исключения:</label>
                <textarea id="reason" name="reason" placeholder="Опишите причину добавления в исключения"><?= htmlspecialchars($reason) ?></textarea>
            </div>
            
            <div class="form-group">
                <label for="normalizer">Нормализатор:</label>
                <select id="normalizer" name="normalizer">
                    <option value="generic_normalizer.php" <?= $type === 'generic' ? 'selected' : '' ?>>Generic</option>
                    <option value="tilda_normalizer.php" <?= $type === 'tilda' ? 'selected' : '' ?>>Tilda</option>
                    <option value="calltouch_normalizer.php" <?= $type === 'calltouch' ? 'selected' : '' ?>>CallTouch</option>
                    <option value="koltach_normalizer.php" <?= $type === 'koltach' ? 'selected' : '' ?>>Koltaсh</option>
                </select>
            </div>
            
            <button type="submit" class="btn">Добавить в исключения</button>
            <a href="retry-raw-errors.php" class="btn btn-secondary">Назад к обработке ошибок</a>
        </form>
        
        <div style="margin-top: 30px;">
            <h3>Что такое исключения:</h3>
            <p>Исключения - это домены, которые не нужно обрабатывать системой. Они логируются в файл exceptions.log для дальнейшего анализа.</p>
        </div>
    </div>
</body>
</html>

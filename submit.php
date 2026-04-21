<?php
/**
 * Правовая фирма «ВЕД» — Обработчик формы заявки
 *
 * Принимает POST-запрос, читает categories.conf,
 * отправляет письмо ответственному специалисту.
 *
 * Требования: PHP 7.4+, расширение zip (для вложений), SMTP через mail()
 * Для SMTP Яндекс 360 — подключить PHPMailer (инструкция в конце файла).
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

// ================================================================
// НАСТРОЙКИ — заполнить перед деплоем
// ================================================================

// Путь к categories.conf (рекомендуется вынести ВЫШЕ web-root).
// Пример для выноса: '/home/user/categories.conf'
define('CATEGORIES_FILE', __DIR__ . '/categories.conf');

// E-mail отправителя (должен совпадать с доменом хостинга или SMTP-ящиком)
define('MAIL_FROM',      'no-reply@fved.ru');
define('MAIL_FROM_NAME', 'Правовая фирма «ВЕД» — форма заявки');

// Копия всех заявок (оставить пустым '', чтобы отключить)
define('MAIL_BCC', 'archive@fved.ru');

// Папка для временного хранения загруженных файлов (вне web-root)
define('UPLOAD_DIR', __DIR__ . '/uploads/');

// Максимальный размер одного файла (байт) — 20 МБ
define('MAX_FILE_SIZE', 20 * 1024 * 1024);

// Максимальное число файлов
define('MAX_FILES', 10);

// Допустимые MIME-типы (проверяется на сервере)
define('ALLOWED_MIME', [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'image/jpeg',
    'image/png',
    'image/heic',
    'image/heif',
]);

// ================================================================
// HELPERS
// ================================================================

function jsonError(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonOk(array $data = []): void {
    echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ================================================================
// ВАЛИДАЦИЯ ЗАПРОСА
// ================================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Метод не поддерживается', 405);
}

// Базовая защита от CSRF через проверку Origin/Referer
$origin  = $_SERVER['HTTP_ORIGIN']  ?? '';
$referer = $_SERVER['HTTP_REFERER'] ?? '';
$host    = $_SERVER['HTTP_HOST']    ?? '';
if ($origin && parse_url($origin, PHP_URL_HOST) !== $host) {
    // Разрешить пустой origin (прямой запрос) — только для localhost/dev
    if ($origin !== '') {
        jsonError('Forbidden', 403);
    }
}

// ================================================================
// ЧТЕНИЕ КАТЕГОРИЙ
// ================================================================

/**
 * Парсит categories.conf и возвращает массив:
 * [ ['id'=>1, 'name'=>'...', 'email'=>'...'], ... ]
 */
function loadCategories(): array {
    if (!file_exists(CATEGORIES_FILE)) {
        jsonError('Конфигурационный файл категорий не найден', 500);
    }
    $lines = file(CATEGORIES_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $cats  = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $parts = array_map('trim', explode('|', $line));
        if (count($parts) < 3) continue;
        [$id, $name, $email] = $parts;
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
        $cats[(int)$id] = ['id' => (int)$id, 'name' => $name, 'email' => $email];
    }
    return $cats;
}

// ================================================================
// ПАРСИНГ ВХОДНЫХ ДАННЫХ
// ================================================================

$raw = json_decode(file_get_contents('php://input'), true);

// Поддержка как JSON (multipart для файлов обрабатывается отдельно),
// так и application/x-www-form-urlencoded
if ($raw === null) {
    $raw = $_POST;
}

$categoryId     = (int)($raw['category_id']    ?? 0);
$description    = trim($raw['description']     ?? '');
$address        = trim($raw['address']         ?? '');   // только для категорий с недвижимостью
$clientName     = trim($raw['name']            ?? '');
$clientPhone    = trim($raw['phone']           ?? '');
$clientEmail    = trim($raw['email']           ?? '');
$contactMethod  = trim($raw['contact_method']  ?? 'phone');
$consentAd      = !empty($raw['consent_ad']);
$utm            = (array)($raw['utm']          ?? []);

// Обязательные поля
if (!$clientName)  jsonError('Не указано имя');
if (!$clientPhone) jsonError('Не указан телефон');
if (!preg_match('/[\d]{7,}/', preg_replace('/\D/', '', $clientPhone))) {
    jsonError('Некорректный номер телефона');
}
if (!$categoryId) jsonError('Не выбрана категория');

// ================================================================
// МАРШРУТИЗАЦИЯ
// ================================================================

$categories = loadCategories();
if (!isset($categories[$categoryId])) {
    jsonError('Неизвестная категория');
}
$category   = $categories[$categoryId];
$recipientEmail = $category['email'];
$categoryName   = $category['name'];

// ================================================================
// ОБРАБОТКА ФАЙЛОВ
// ================================================================

$attachments = [];

if (!empty($_FILES)) {
    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0750, true);
    }

    $fileFields = $_FILES['files'] ?? [];

    // Нормализуем структуру $_FILES при multiple upload
    if (isset($fileFields['name']) && is_array($fileFields['name'])) {
        $fileCount = count($fileFields['name']);
        if ($fileCount > MAX_FILES) {
            jsonError('Превышено максимальное количество файлов (' . MAX_FILES . ')');
        }
        for ($i = 0; $i < $fileCount; $i++) {
            if ($fileFields['error'][$i] !== UPLOAD_ERR_OK) continue;
            if ($fileFields['size'][$i]  > MAX_FILE_SIZE) {
                jsonError("Файл «{$fileFields['name'][$i]}» превышает 20 МБ");
            }
            // Проверка MIME на сервере
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($fileFields['tmp_name'][$i]);
            if (!in_array($mime, ALLOWED_MIME, true)) {
                jsonError("Недопустимый тип файла: {$fileFields['name'][$i]}");
            }
            // Безопасное имя файла
            $safeName = preg_replace('/[^a-zа-яё0-9._\-]/ui', '_', $fileFields['name'][$i]);
            $dest     = UPLOAD_DIR . uniqid('', true) . '_' . $safeName;
            if (move_uploaded_file($fileFields['tmp_name'][$i], $dest)) {
                $attachments[] = [
                    'path' => $dest,
                    'name' => $fileFields['name'][$i],
                    'size' => $fileFields['size'][$i],
                ];
            }
        }
    }
}

// ================================================================
// ФОРМИРОВАНИЕ ПИСЬМА
// ================================================================

$now       = new DateTimeImmutable('now', new DateTimeZone('Asia/Omsk'));
$dateStr   = $now->format('d.m.Y H:i') . ' (Омск)';
$requestId = strtoupper(substr(md5(uniqid('', true)), 0, 8));

$contactMethodMap = [
    'phone'    => 'Звонок',
    'whatsapp' => 'WhatsApp',
    'telegram' => 'Telegram',
    'email'    => 'E-mail',
];
$contactMethodStr = $contactMethodMap[$contactMethod] ?? $contactMethod;

// UTM-метки
$utmLines = '';
foreach ($utm as $key => $val) {
    if ($val) $utmLines .= "  {$key}: " . h((string)$val) . "\n";
}

// Список вложений в тексте письма
$attachmentList = '';
if ($attachments) {
    $attachmentList = "\n════════════════════════════════════\nПРИКРЕПЛЁННЫЕ ДОКУМЕНТЫ\n════════════════════════════════════\n";
    foreach ($attachments as $i => $att) {
        $sizeFmt = $att['size'] < 1024 * 1024
            ? round($att['size'] / 1024) . ' КБ'
            : round($att['size'] / 1024 / 1024, 1) . ' МБ';
        $attachmentList .= ($i + 1) . ". {$att['name']} — {$sizeFmt}\n";
    }
} else {
    $attachmentList = "\n════════════════════════════════════\nДокументы не прикреплены\n════════════════════════════════════\n";
}

$addressLine = $address     ? "\nАдрес объекта:          {$address}" : '';
$descLine    = $description ? "\nОписание ситуации:\n{$description}" : "\nОписание ситуации:      не указано";

$consentAdStr = $consentAd ? 'Да' : 'Нет';

$body = <<<TEXT
ЗАЯВКА #{$requestId}  от {$dateStr}
Категория дела: {$categoryName}
Источник: {$utmLines}
════════════════════════════════════
ДАННЫЕ КЛИЕНТА
════════════════════════════════════
Имя:                    {$clientName}
Телефон:                {$clientPhone}
E-mail:                 {$clientEmail}
Предпочт. способ связи: {$contactMethodStr}{$addressLine}{$descLine}
════════════════════════════════════
Согласие на рекламу:    {$consentAdStr}
{$attachmentList}
════════════════════════════════════
Правовая фирма «ВЕД»  |  fved.ru
TEXT;

$subject = "Новая заявка — {$categoryName} — {$clientName} — {$now->format('d.m.Y')}";

// ================================================================
// ОТПРАВКА ПИСЬМА (mail() / SMTP через PHPMailer)
// ================================================================

/**
 * sendEmail — обёртка над mail() с поддержкой вложений (MIME multipart).
 * Для Яндекс 360 SMTP замените тело функции на PHPMailer (см. комментарий ниже).
 */
function sendEmail(
    string $to,
    string $subject,
    string $body,
    array  $attachments = [],
    string $bcc         = ''
): bool {
    $boundary = '=_' . md5(uniqid('', true));

    $headers  = "From: " . MAIL_FROM_NAME . " <" . MAIL_FROM . ">\r\n";
    $headers .= "Reply-To: " . MAIL_FROM . "\r\n";
    if ($bcc) $headers .= "Bcc: {$bcc}\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";
    $headers .= "X-Mailer: VED-FormProcessor/1.0\r\n";

    $message  = "--{$boundary}\r\n";
    $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $message .= chunk_split(base64_encode($body)) . "\r\n";

    foreach ($attachments as $att) {
        if (!file_exists($att['path'])) continue;
        $data     = file_get_contents($att['path']);
        $safeName = mb_encode_mimeheader($att['name'], 'UTF-8', 'B');
        $message .= "--{$boundary}\r\n";
        $message .= "Content-Type: application/octet-stream; name=\"{$safeName}\"\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n";
        $message .= "Content-Disposition: attachment; filename=\"{$safeName}\"\r\n\r\n";
        $message .= chunk_split(base64_encode($data)) . "\r\n";
    }

    $message .= "--{$boundary}--";

    return mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $message, $headers);
}

// Отправка ответственному
$sent = sendEmail($recipientEmail, $subject, $body, $attachments, MAIL_BCC);

// Подтверждение клиенту (если указал e-mail)
if ($clientEmail && filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) {
    $clientBody = "Здравствуйте, {$clientName}!\n\n"
        . "Ваша заявка #{$requestId} принята.\n"
        . "Категория: {$categoryName}\n\n"
        . "Специалист свяжется с вами в течение рабочего дня.\n\n"
        . "С уважением,\nПравовая фирма «ВЕД»\n+7 (3812) 22-000-9  |  info@fved.ru";
    sendEmail($clientEmail, "Заявка #{$requestId} принята — Правовая фирма «ВЕД»", $clientBody);
}

// ================================================================
// ОЧИСТКА ВРЕМЕННЫХ ФАЙЛОВ (после отправки)
// ================================================================

foreach ($attachments as $att) {
    if (file_exists($att['path'])) {
        @unlink($att['path']);
    }
}

// ================================================================
// ОТВЕТ
// ================================================================

if ($sent) {
    jsonOk(['request_id' => $requestId]);
} else {
    jsonError('Ошибка отправки письма. Пожалуйста, позвоните нам напрямую.', 500);
}

/*
 * ================================================================
 * КАК ПОДКЛЮЧИТЬ SMTP (Яндекс 360 / любой SMTP)
 * ================================================================
 *
 * 1. Установить PHPMailer:
 *    composer require phpmailer/phpmailer
 *    или скачать: https://github.com/PHPMailer/PHPMailer
 *
 * 2. Заменить функцию sendEmail() на:
 *
 *   use PHPMailer\PHPMailer\PHPMailer;
 *   use PHPMailer\PHPMailer\SMTP;
 *
 *   function sendEmail(string $to, string $subject, string $body, array $attachments = [], string $bcc = ''): bool {
 *       $mail = new PHPMailer(true);
 *       $mail->isSMTP();
 *       $mail->Host       = 'smtp.yandex.ru';
 *       $mail->SMTPAuth   = true;
 *       $mail->Username   = 'no-reply@fved.ru';     // SMTP-логин
 *       $mail->Password   = 'ВАШ_ПАРОЛЬ_ПРИЛОЖЕНИЯ'; // Пароль приложения из Яндекс 360
 *       $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
 *       $mail->Port       = 465;
 *       $mail->CharSet    = 'UTF-8';
 *       $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
 *       $mail->addAddress($to);
 *       if ($bcc) $mail->addBCC($bcc);
 *       $mail->Subject = $subject;
 *       $mail->Body    = $body;
 *       foreach ($attachments as $att) {
 *           $mail->addAttachment($att['path'], $att['name']);
 *       }
 *       return $mail->send();
 *   }
 */

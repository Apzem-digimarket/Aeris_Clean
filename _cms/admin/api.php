<?php
/**
 * JSON API used by the admin panel. Every request must be a POST from a logged-in
 * session and carry the CSRF token in the X-CSRF-Token header.
 */
require __DIR__ . '/../includes/bootstrap.php';

send_admin_headers();
header('Content-Type: application/json; charset=utf-8');
start_admin_session();

function respond(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(405, ['error' => 'Method not allowed.']);
}
if (!is_logged_in()) {
    respond(401, ['error' => 'Your session has ended. Please log in again.']);
}
if (!check_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    respond(403, ['error' => 'Security token expired. Reload the page and try again.']);
}

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'load':
            respond(200, ['content' => load_content()]);

        case 'save':
            $body = json_decode((string) file_get_contents('php://input'), true);
            if (!is_array($body) || !is_array($body['content'] ?? null)) {
                respond(400, ['error' => 'Invalid data.']);
            }
            $content = sanitize_content($body['content'], true);
            save_content($content);
            cleanup_uploads($content);
            respond(200, ['content' => $content]);

        case 'upload':
            $limit = upload_limit_bytes();
            $tooLarge = 'Image is too large. Maximum size on this server is ' . format_bytes($limit) . '.';
            $file = $_FILES['image'] ?? null;
            if (!$file && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $limit) {
                respond(400, ['error' => $tooLarge]);   // PHP discarded an over-size request
            }
            if (!$file || !is_array($file) || is_array($file['error'])) {
                respond(400, ['error' => 'No image received.']);
            }
            if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE || $file['size'] > $limit) {
                respond(400, ['error' => $tooLarge]);
            }
            if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
                respond(400, ['error' => 'Upload failed. Please try again.']);
            }
            $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
            $info = @getimagesize($file['tmp_name']);
            $mime = $info['mime'] ?? '';
            if (!isset($types[$mime])) {
                respond(400, ['error' => 'Please upload a JPG, PNG, WEBP or GIF image.']);
            }
            $base = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', pathinfo((string) $file['name'], PATHINFO_FILENAME)));
            $base = trim(substr($base, 0, 40), '-') ?: 'image';
            $name = $base . '-' . bin2hex(random_bytes(4)) . '.' . $types[$mime];
            if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0755, true)) {
                respond(500, ['error' => 'Cannot create the uploads folder.']);
            }
            if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . '/' . $name)) {
                respond(500, ['error' => 'Cannot save the image. Check that the uploads folder is writable.']);
            }
            @chmod(UPLOAD_DIR . '/' . $name, 0644);
            respond(200, ['path' => 'assets/products/uploads/' . $name, 'width' => $info[0], 'height' => $info[1]]);

        case 'account':
            $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
            $account = admin_account();
            $current = (string) ($body['currentPassword'] ?? '');
            $email = trim((string) ($body['email'] ?? ''));
            $new = (string) ($body['newPassword'] ?? '');
            if (!password_verify($current, $account['hash'])) {
                respond(400, ['error' => 'Current password is incorrect.']);
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                respond(400, ['error' => 'Enter a valid email address.']);
            }
            if ($new !== '' && strlen($new) < 10) {
                respond(400, ['error' => 'New password must be at least 10 characters.']);
            }
            save_admin_account($email, $new !== '' ? $new : $current);
            $_SESSION['admin_key'] = substr(admin_account()['hash'], -16);   // stay logged in here
            respond(200, ['email' => $email]);

        case 'publish':
            if (!can_publish()) {
                respond(403, ['error' => 'Publishing is only available in the admin on your Mac (http://127.0.0.1:8772/admin/).']);
            }
            if ((int) getenv('PHP_CLI_SERVER_WORKERS') < 2) {
                respond(400, ['error' => 'Restart the admin with workers: PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8772 _cms/router.php — or run _cms/publish.sh in Terminal.']);
            }
            @set_time_limit(300);
            $output = [];
            $exit = 0;
            exec('/bin/bash ' . escapeshellarg(CMS_DIR . '/publish.sh') . ' 2>&1', $output, $exit);
            $log = implode("\n", $output);
            if ($exit !== 0) {
                $errors = preg_grep('/^ERROR:|fatal:|error:/i', $output);
                respond(500, ['error' => 'Publishing failed. ' . ($errors ? trim(end($errors)) : 'See the details below.'), 'log' => $log]);
            }
            respond(200, ['log' => $log, 'changed' => strpos($log, 'No changes') === false] + publish_status());

        default:
            respond(404, ['error' => 'Unknown action.']);
    }
} catch (InvalidArgumentException $ex) {
    respond(400, ['error' => $ex->getMessage()]);
} catch (Throwable $ex) {
    error_log('[admin api] ' . $ex->getMessage());
    respond(500, ['error' => $ex instanceof RuntimeException ? $ex->getMessage() : 'Server error. Please try again.']);
}

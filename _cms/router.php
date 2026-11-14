<?php
/**
 * Local admin + preview for the Aeris Clean website (run from the "aeris sense" folder):
 *   PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8772 _cms/router.php
 *
 *   http://127.0.0.1:8772/         live preview, including unpublished changes
 *   http://127.0.0.1:8772/admin/   admin panel
 * The public website (GitHub Pages) only ever receives the built index.html and images.
 */
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$root = dirname(__DIR__);
$cms = __DIR__;

header('X-Content-Type-Options: nosniff');

function deny(): bool
{
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';
    return true;
}

if (strpos($path, '..') !== false || preg_match('~(^|/)(\.|_cms(/|$))~', $path) || preg_match('~\.(md|log|sh|zip|gs)$~i', $path)) {
    return deny();
}

// Live preview of the page, built from the current content (published or not).
if ($path === '/' || $path === '/index.html') {
    require $cms . '/includes/bootstrap.php';
    require $cms . '/includes/render.php';
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo render_page(load_content());
    return true;
}

// Admin panel (files live in _cms/admin).
if ($path === '/admin') {
    header('Location: /admin/', true, 301);
    return true;
}
if ($path === '/admin/' || $path === '/admin/index.php') {
    require $cms . '/admin/index.php';
    return true;
}
if ($path === '/admin/api.php') {
    require $cms . '/admin/api.php';
    return true;
}
$types = ['admin.js' => 'application/javascript', 'admin.css' => 'text/css', 'emblem.webp' => 'image/webp'];
if (preg_match('~^/admin/([a-z.]+)$~', $path, $m) && isset($types[$m[1]])) {
    header('Content-Type: ' . $types[$m[1]]);
    header('Cache-Control: no-cache');
    readfile($cms . '/admin/' . $m[1]);
    return true;
}

// Everything else (images, CNAME…) is served as a normal file.
$file = realpath($root . $path);
if ($file !== false && strpos($file, $root) === 0 && is_file($file)) {
    return false;
}
http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Not found';
return true;

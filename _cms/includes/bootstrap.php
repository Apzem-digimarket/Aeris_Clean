<?php
/**
 * Shared helpers for the public site and the admin panel.
 * Requires PHP 7.4 or newer.
 */

define('CMS_DIR', dirname(__DIR__));                 // aeris sense/_cms
define('ROOT_DIR', dirname(CMS_DIR));                // aeris sense (the published website)
define('DATA_DIR', CMS_DIR . '/data');
define('UPLOAD_DIR', ROOT_DIR . '/assets/products/uploads');
// Content is split into three files so the online editor (admin/config.yml, Sveltia CMS) can manage
// products and "We specialize in" cards as separate entries. The Mac admin reads and writes the same files.
define('PRODUCTS_FILE', DATA_DIR . '/products.json');   // [ product, … ]
define('RANGE_FILE', DATA_DIR . '/range.json');         // [ "We specialize in" card, … ]
define('SECTIONS_FILE', DATA_DIR . '/sections.json');   // { products: {…headings}, range: {…headings} }
define('CONTENT_FILES', [PRODUCTS_FILE, RANGE_FILE, SECTIONS_FILE]);
define('TEMPLATE_FILE', CMS_DIR . '/template.html');
define('OUTPUT_FILE', ROOT_DIR . '/index.html');
define('AUTH_FILE', DATA_DIR . '/auth.php');
define('ATTEMPTS_FILE', DATA_DIR . '/login-attempts.php');

define('MAX_UPLOAD_BYTES', 5 * 1024 * 1024);   // 5 MB per image
define('SESSION_IDLE_SECONDS', 2 * 60 * 60);    // log out after 2 hours idle
define('LOGIN_MAX_ATTEMPTS', 5);                // failed logins allowed…
define('LOGIN_LOCK_SECONDS', 15 * 60);          // …before a 15 minute lock

// Images a product may use: the bundled product photos or admin uploads.
define('IMAGE_PATH_PATTERN', '~^assets/products/(uploads/)?[A-Za-z0-9._-]+\.(jpe?g|png|webp|gif)$~i');

// Icons available for "We specialize in" cards (symbols defined in the page's SVG sprite).
const RANGE_ICONS = ['i-touch', 'i-room', 'i-cabin', 'i-fan', 'i-filter', 'i-wind', 'i-particles', 'i-shield', 'i-gear', 'i-bolt', 'i-temp', 'i-db', 'i-wifi', 'i-lungs', 'i-heart'];

// Production error handling: never show PHP errors to visitors; log them privately instead.
// (data/ is blocked from the web, and .log files are blocked by .htaccess as well.)
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
if (is_dir(DATA_DIR) && is_writable(DATA_DIR)) {
    ini_set('error_log', DATA_DIR . '/php-errors.log');
}
date_default_timezone_set('Asia/Kolkata');

// Fallbacks in case the host's PHP lacks the mbstring extension.
if (!function_exists('mb_substr')) {
    function mb_substr($s, $start, $length = null, $enc = null) {
        preg_match_all('/./us', (string) $s, $m);
        return implode('', array_slice($m[0], $start, $length));
    }
}
if (!function_exists('mb_strtolower')) {
    function mb_strtolower($s, $enc = null) { return strtolower((string) $s); }
}

/** Largest image upload this server accepts, in bytes (the smaller of our limit and PHP's limits). */
function upload_limit_bytes(): int
{
    $toBytes = function ($v): int {
        $v = trim((string) $v);
        if ($v === '' || $v === '-1' || $v === '0') {
            return PHP_INT_MAX;
        }
        $n = (int) $v;
        switch (strtolower(substr($v, -1))) {
            case 'g': $n *= 1024;
            // no break
            case 'm': $n *= 1024;
            // no break
            case 'k': $n *= 1024;
        }
        return $n;
    };
    // Leave room in post_max_size for the rest of the request.
    $post = $toBytes(ini_get('post_max_size'));
    return (int) min(MAX_UPLOAD_BYTES, $toBytes(ini_get('upload_max_filesize')), $post === PHP_INT_MAX ? PHP_INT_MAX : $post - 64 * 1024);
}

function format_bytes(int $bytes): string
{
    return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : round($bytes / 1024) . ' KB';
}

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* ---------- File storage ---------- */

function write_file_atomic(string $path, string $data): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        throw new RuntimeException('Cannot create folder ' . basename($dir) . '.');
    }
    $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, $data, LOCK_EX) === false || !rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Cannot write ' . basename($path) . '. Check that the data folder is writable (permissions 755).');
    }
}

/** Private data is stored inside a .php file that exits immediately if requested over the web. */
const PRIVATE_HEADER = "<?php http_response_code(404); exit; ?>\n";

function read_private(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $raw = (string) file_get_contents($path);
    if (strpos($raw, PRIVATE_HEADER) === 0) {
        $raw = substr($raw, strlen(PRIVATE_HEADER));
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function write_private(string $path, array $data): void
{
    write_file_atomic($path, PRIVATE_HEADER . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/* ---------- Site content ---------- */

function default_content(): array
{
    return [
        'section' => ['eyebrow' => 'Our products', 'title' => '', 'lede' => '', 'showCards' => true],
        'products' => [],
        'range' => ['eyebrow' => 'We specialize in', 'title' => '', 'lede' => '', 'items' => []],
    ];
}

function read_json_file(string $path, bool $throw)
{
    if (!is_file($path)) {
        if ($throw) {
            throw new RuntimeException('Missing content file: ' . basename($path));
        }
        return null;
    }
    $data = json_decode((string) file_get_contents($path), true);
    if ($data === null && $throw) {
        throw new RuntimeException(basename($path) . ' is not valid JSON: ' . json_last_error_msg());
    }
    return $data;
}

/**
 * Loads the content from the three files.
 * $throw = true (the website build): any problem stops the build, so a broken edit can never
 * publish an empty or half-built page. $throw = false (admin / preview): falls back to defaults.
 */
function load_content(bool $throw = false): array
{
    $products = read_json_file(PRODUCTS_FILE, $throw);
    $range = read_json_file(RANGE_FILE, $throw);
    $sections = read_json_file(SECTIONS_FILE, $throw);
    if (!is_array($products) || !is_array($range) || !is_array($sections)) {
        if ($throw) {
            throw new RuntimeException('Content files must hold a list (products, range) and an object (sections).');
        }
        return default_content();
    }
    $sec = is_array($sections['products'] ?? null) ? $sections['products'] : [];
    $rng = is_array($sections['range'] ?? null) ? $sections['range'] : [];
    $data = ['section' => $sec, 'products' => $products, 'range' => ['items' => $range] + $rng];
    try {
        return sanitize_content($data, false);
    } catch (InvalidArgumentException $ex) {
        if ($throw) {
            throw $ex;
        }
        return default_content();
    }
}

function save_content(array $content): void
{
    $json = function ($value): string {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    };
    $headings = $content['range'];
    unset($headings['items']);
    write_file_atomic(PRODUCTS_FILE, $json($content['products']));
    write_file_atomic(RANGE_FILE, $json($content['range']['items']));
    write_file_atomic(SECTIONS_FILE, $json(['products' => $content['section'], 'range' => $headings]));
}

/** Time of the most recent content change (for the "unpublished changes" notice). */
function content_mtime(): int
{
    clearstatcache();
    $t = 0;
    foreach (CONTENT_FILES as $f) {
        $t = max($t, is_file($f) ? (int) filemtime($f) : 0);
    }
    return $t;
}

/* ---------- Validation ---------- */
function clean_text($value, int $max, bool $multiline = false): string
{
    $text = is_scalar($value) ? (string) $value : '';
    $text = $multiline ? str_replace("\r\n", "\n", $text) : preg_replace('/\s+/u', ' ', $text);
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $text);
    $text = trim((string) $text);
    return mb_substr($text, 0, $max, 'UTF-8');
}

function clean_bool($value): bool
{
    return $value === true || $value === 1 || $value === '1' || $value === 'true';
}

function clean_image($value, bool $mustExist): string
{
    // The online editor stores site-root paths ("/assets/products/x.webp"); the page uses relative ones.
    $path = ltrim(clean_text($value, 200), '/');
    if ($path === '') {
        return '';
    }
    if (!preg_match(IMAGE_PATH_PATTERN, $path)) {
        throw new InvalidArgumentException('Invalid image path: ' . $path);
    }
    if ($mustExist && !is_file(ROOT_DIR . '/' . $path)) {
        throw new InvalidArgumentException('Image file not found: ' . $path);
    }
    return $path;
}

function clean_link($value): string
{
    $link = clean_text($value, 500);
    if ($link === '') {
        return '';
    }
    // Allow web, email and phone links, or a relative path on this site. Blocks javascript: and similar.
    if (preg_match('~^(https?://|mailto:|tel:)~i', $link) || !preg_match('~^[^/?#]*:~', $link)) {
        return $link;
    }
    throw new InvalidArgumentException('Links must start with https://, http://, mailto: or tel:');
}

function clean_id($value, string $prefix): string
{
    $id = is_string($value) ? strtolower($value) : '';
    if (!preg_match('/^[a-z0-9_-]{1,40}$/', $id)) {
        $id = $prefix . bin2hex(random_bytes(5));
    }
    return $id;
}

/** Links for buttons: web/email/phone links, a section on this page (#contact), or a site path. */
function clean_button_link($value): string
{
    $link = clean_text($value, 300);
    return ($link === '' || $link[0] === '#') ? $link : clean_link($link);
}

/** Custom drawing (SVG/HTML) shown instead of a photo. Scripts and event handlers are not allowed. */
function clean_custom_media($value): string
{
    $html = is_string($value) ? trim(str_replace("\r\n", "\n", $value)) : '';
    if ($html === '') {
        return '';
    }
    if (strlen($html) > 60000) {
        throw new InvalidArgumentException('The custom drawing is too large (max 60 KB).');
    }
    if (preg_match('~<\s*(script|iframe|object|embed|link|meta|style)\b|\son[a-z]+\s*=|javascript:~i', $html)) {
        throw new InvalidArgumentException('The custom drawing may not contain scripts, frames or event handlers.');
    }
    return $html;
}

function clean_list($rows, int $maxRows, callable $clean): array
{
    $out = [];
    foreach (array_slice(is_array($rows) ? $rows : [], 0, $maxRows) as $row) {
        $row = $clean($row);
        if ($row !== null) {
            $out[] = $row;
        }
    }
    return $out;
}

/** Element ids already used by other parts of the page, which product anchors must not reuse. */
function reserved_ids(): array
{
    static $ids = null;
    if ($ids === null) {
        preg_match_all('/\sid="([^"]+)"/', is_file(TEMPLATE_FILE) ? (string) file_get_contents(TEMPLATE_FILE) : '', $m);
        $ids = array_fill_keys($m[1], true);
    }
    return $ids;
}

/**
 * Validate and normalise the full content document.
 * @param bool $strict  true when saving from the admin (also checks that image files exist)
 */
function sanitize_content(array $in, bool $strict = true): array
{
    $sec = is_array($in['section'] ?? null) ? $in['section'] : [];
    $out = ['section' => [
        'eyebrow' => clean_text($sec['eyebrow'] ?? '', 80),
        'title' => clean_text($sec['title'] ?? '', 200),
        'lede' => clean_text($sec['lede'] ?? '', 600),
        'showCards' => clean_bool($sec['showCards'] ?? true),
    ], 'products' => [], 'range' => []];

    $ids = [];
    $slugs = reserved_ids();
    foreach (array_slice(is_array($in['products'] ?? null) ? $in['products'] : [], 0, 100) as $p) {
        if (!is_array($p)) {
            continue;
        }
        $title = clean_text($p['title'] ?? '', 120);
        if ($title === '') {
            throw new InvalidArgumentException('Every product needs a title.');
        }
        $id = clean_id($p['id'] ?? '', 'p');
        if (isset($ids[$id])) {
            $id = clean_id('', 'p');
        }
        $ids[$id] = true;
        $accent = clean_text($p['titleAccent'] ?? '', 60);
        $kicker = clean_text($p['kicker'] ?? '', 80);

        // Anchor on the page (#aeris-sense): must be unique and not clash with other sections.
        $slug = slugify(clean_text($p['slug'] ?? '', 80)) ?: slugify(trim($title . ' ' . $accent)) ?: $id;
        $base = $slug;
        for ($n = 2; isset($slugs[$slug]); $n++) {
            $slug = $base . '-' . $n;
        }
        $slugs[$slug] = true;

        $quoteName = clean_text($p['quoteName'] ?? '', 160);
        $out['products'][] = [
            'id' => $id,
            'slug' => $slug,
            'visible' => clean_bool($p['visible'] ?? true),
            'kicker' => $kicker,
            'title' => $title,
            'titleAccent' => $accent,
            'quoteName' => $quoteName !== '' ? $quoteName : trim($title . ' ' . $accent) . ($kicker !== '' ? ' - ' . $kicker : ''),
            'subtitle' => clean_text($p['subtitle'] ?? '', 300),
            'description' => clean_text($p['description'] ?? '', 2000),
            'mediaLabel' => clean_text($p['mediaLabel'] ?? '', 60),
            'image' => clean_image($p['image'] ?? '', $strict),
            'alt' => clean_text($p['alt'] ?? '', 200),
            'customMedia' => clean_custom_media($p['customMedia'] ?? ''),
            'cardImage' => clean_image($p['cardImage'] ?? '', $strict),
            'taglineBold' => clean_text($p['taglineBold'] ?? '', 60),
            'taglineText' => clean_text($p['taglineText'] ?? '', 80),
            'features' => clean_list($p['features'] ?? [], 20, function ($t) {
                $t = clean_text($t, 200);
                return $t === '' ? null : $t;
            }),
            'highlights' => clean_list($p['highlights'] ?? [], 12, function ($r) {
                $r = ['title' => clean_text($r['title'] ?? '', 80), 'text' => clean_text($r['text'] ?? '', 200)];
                return ($r['title'] === '' && $r['text'] === '') ? null : $r;
            }),
            'specsTitle' => clean_text($p['specsTitle'] ?? '', 80),
            'specs' => clean_list($p['specs'] ?? [], 40, function ($r) {
                $r = ['label' => clean_text($r['label'] ?? '', 80), 'value' => clean_text($r['value'] ?? '', 300)];
                return ($r['label'] === '' && $r['value'] === '') ? null : $r;
            }),
            'applicationsTitle' => clean_text($p['applicationsTitle'] ?? '', 80),
            'applications' => clean_list($p['applications'] ?? [], 12, function ($r) use ($strict) {
                $r = ['image' => clean_image($r['image'] ?? '', $strict), 'alt' => clean_text($r['alt'] ?? '', 200),
                      'title' => clean_text($r['title'] ?? '', 80), 'text' => clean_text($r['text'] ?? '', 200)];
                return ($r['image'] === '' && $r['title'] === '' && $r['text'] === '') ? null : $r;
            }),
            'secondaryLabel' => clean_text($p['secondaryLabel'] ?? '', 60),
            'secondaryLink' => clean_button_link($p['secondaryLink'] ?? ''),
        ];
    }

    $r = is_array($in['range'] ?? null) ? $in['range'] : [];
    $items = [];
    $itemIds = [];
    foreach (array_slice(is_array($r['items'] ?? null) ? $r['items'] : [], 0, 30) as $it) {
        if (!is_array($it)) {
            continue;
        }
        $title = clean_text($it['title'] ?? '', 80);
        if ($title === '') {
            throw new InvalidArgumentException('Every "We specialize in" card needs a title.');
        }
        $id = clean_id($it['id'] ?? '', 'r');
        if (isset($itemIds[$id])) {
            $id = clean_id('', 'r');
        }
        $itemIds[$id] = true;
        $icon = in_array($it['icon'] ?? '', RANGE_ICONS, true) ? $it['icon'] : '';
        $link = is_string($it['link'] ?? null) && isset($ids[$it['link']]) ? $it['link'] : '';
        $items[] = [
            'id' => $id,
            'visible' => clean_bool($it['visible'] ?? true),
            'title' => $title,
            'text' => clean_text($it['text'] ?? '', 200),
            'icon' => $icon,
            'image' => clean_image($it['image'] ?? '', $strict),
            'link' => $link,
            'inQuoteForm' => clean_bool($it['inQuoteForm'] ?? true),
        ];
    }
    $out['range'] = [
        'eyebrow' => clean_text($r['eyebrow'] ?? '', 80),
        'title' => clean_text($r['title'] ?? '', 200),
        'lede' => clean_text($r['lede'] ?? '', 600),
        'items' => $items,
    ];
    return $out;
}
function slugify(string $text): string
{
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if ($converted !== false) {
            $text = $converted;
        }
    }
    $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-');
    return substr($slug, 0, 80);
}

/** Remove uploaded images no longer used anywhere (older than 1 hour, so in-progress edits are safe). */
function cleanup_uploads(array $content): void
{
    $used = [];
    foreach ($content['products'] as $p) {
        $used[$p['image']] = true;
        $used[$p['cardImage']] = true;
        foreach ($p['applications'] as $a) {
            $used[$a['image']] = true;
        }
    }
    foreach ($content['range']['items'] as $it) {
        $used[$it['image']] = true;
    }
    foreach (glob(UPLOAD_DIR . '/*') ?: [] as $file) {
        if (!preg_match('/\.(jpe?g|png|webp|gif)$/i', $file)) {
            continue;
        }
        if (!isset($used['assets/products/uploads/' . basename($file)]) && filemtime($file) < time() - 3600) {
            @unlink($file);
        }
    }
}
/* ---------- Sessions & authentication ---------- */

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

function start_admin_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('aeris_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();

    if (!empty($_SESSION['admin']) && (time() - ($_SESSION['last_seen'] ?? 0)) > SESSION_IDLE_SECONDS) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['last_seen'] = time();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}

function csrf_token(): string
{
    return $_SESSION['csrf'] ?? '';
}

function check_csrf(?string $token): bool
{
    return is_string($token) && csrf_token() !== '' && hash_equals(csrf_token(), $token);
}

function admin_account(): ?array
{
    $auth = read_private(AUTH_FILE);
    return ($auth && !empty($auth['email']) && !empty($auth['hash'])) ? $auth : null;
}

function save_admin_account(string $email, string $password): void
{
    write_private(AUTH_FILE, [
        'email' => $email,
        'hash' => password_hash($password, PASSWORD_DEFAULT),
        'updated' => date('c'),
    ]);
}

function is_logged_in(): bool
{
    $account = admin_account();
    return $account && !empty($_SESSION['admin'])
        && hash_equals((string) ($_SESSION['admin_key'] ?? ''), substr($account['hash'], -16));
}

/** The visitor's IP address. Behind a tunnel/proxy on this same machine, use the forwarded address. */
function client_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (in_array($ip, ['127.0.0.1', '::1'], true)) {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR'] as $header) {
            $forwarded = trim(explode(',', (string) ($_SERVER[$header] ?? ''))[0]);
            if (filter_var($forwarded, FILTER_VALIDATE_IP)) {
                return $forwarded;
            }
        }
    }
    return $ip !== '' ? $ip : 'unknown';
}

/**
 * Publishing to GitHub Pages is only allowed from the admin running on this Mac
 * (PHP's built-in server), never on a web host. Through the tunnel only when the server was
 * started by live-preview/start.sh (ADMIN_REMOTE_PUBLISH=1); login + CSRF are still required.
 */
function can_publish(): bool
{
    return PHP_SAPI === 'cli-server'
        && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
        && ((empty($_SERVER['HTTP_CF_CONNECTING_IP']) && empty($_SERVER['HTTP_X_FORWARDED_FOR']))
            || getenv('ADMIN_REMOTE_PUBLISH') === '1')   // set by live-preview/start.sh for the tunnel
        && is_file(CMS_DIR . '/publish.sh')
        && function_exists('exec');
}

/** When the site was last published, and whether content changed since then. */
function publish_status(): array
{
    $stamp = DATA_DIR . '/last-publish.txt';
    $last = is_file($stamp) ? trim((string) file_get_contents($stamp)) : '';
    clearstatcache();
    $pending = !is_file($stamp) || content_mtime() > filemtime($stamp);
    return ['lastPublish' => $last, 'unpublished' => $pending];
}

function client_key(): string
{
    return hash('sha256', client_ip());
}

function login_locked_for(): int
{
    $attempts = read_private(ATTEMPTS_FILE) ?? [];
    $entry = $attempts[client_key()] ?? null;
    if (!$entry || $entry['count'] < LOGIN_MAX_ATTEMPTS) {
        return 0;
    }
    return max(0, ($entry['time'] + LOGIN_LOCK_SECONDS) - time());
}

function record_login_failure(): void
{
    $attempts = read_private(ATTEMPTS_FILE) ?? [];
    $now = time();
    foreach ($attempts as $key => $entry) {   // forget old entries
        if ($entry['time'] + LOGIN_LOCK_SECONDS < $now) {
            unset($attempts[$key]);
        }
    }
    $key = client_key();
    $attempts[$key] = ['count' => ($attempts[$key]['count'] ?? 0) + 1, 'time' => $now];
    write_private(ATTEMPTS_FILE, $attempts);
}

function clear_login_failures(): void
{
    $attempts = read_private(ATTEMPTS_FILE) ?? [];
    unset($attempts[client_key()]);
    write_private(ATTEMPTS_FILE, $attempts);
}

function attempt_login(string $email, string $password): bool
{
    $account = admin_account();
    $ok = $account
        && hash_equals(strtolower($account['email']), strtolower($email))
        && password_verify($password, $account['hash']);
    if (!$ok) {
        return false;
    }
    if (password_needs_rehash($account['hash'], PASSWORD_DEFAULT)) {
        save_admin_account($account['email'], $password);
        $account = admin_account();
    }
    session_regenerate_id(true);
    $_SESSION['admin'] = true;
    $_SESSION['admin_key'] = substr($account['hash'], -16);   // a password change logs out other sessions
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return true;
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function send_admin_headers(): void
{
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');
}

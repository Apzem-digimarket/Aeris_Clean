<?php
require __DIR__ . '/../includes/bootstrap.php';

/** The page's SVG icon sprite, so the admin can preview card icons. */
function icon_sprite(): string
{
    $t = is_file(TEMPLATE_FILE) ? (string) file_get_contents(TEMPLATE_FILE) : '';
    $i = strpos($t, '<symbol');
    if ($i === false) {
        return '';
    }
    $start = strrpos(substr($t, 0, $i), '<svg');
    $end = strpos($t, '</svg>', strrpos($t, '</symbol>')) + 6;
    return substr($t, $start, $end - $start);
}

send_admin_headers();

// Always use the /admin/ URL (with trailing slash) so relative links to admin.css and api.php work.
$selfPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/admin/', PHP_URL_PATH);
if (substr($selfPath, -1) !== '/' && substr($selfPath, -9) !== 'index.php') {
    header('Location: ' . $selfPath . '/', true, 301);
    exit;
}
$selfPath = preg_replace('~index\.php$~', '', $selfPath);

start_admin_session();

$account = admin_account();
$error = '';
$notice = '';
$action = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_csrf($_POST['csrf'] ?? null)) {
        $error = 'Your form expired. Please try again.';
    } elseif ($action === 'setup' && !$account) {
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid email address.';
        } elseif (strlen($password) < 10) {
            $error = 'Password must be at least 10 characters.';
        } elseif ($password !== ($_POST['confirm'] ?? '')) {
            $error = 'The two passwords do not match.';
        } else {
            try {
                save_admin_account($email, $password);
                attempt_login($email, $password);
                header('Location: ' . $selfPath);
                exit;
            } catch (RuntimeException $ex) {
                $error = $ex->getMessage();
            }
        }
    } elseif ($action === 'login' && $account) {
        $wait = login_locked_for();
        if ($wait > 0) {
            $error = 'Too many failed attempts. Try again in ' . ceil($wait / 60) . ' minute(s).';
        } elseif (attempt_login(trim((string) ($_POST['email'] ?? '')), (string) ($_POST['password'] ?? ''))) {
            clear_login_failures();
            header('Location: ' . $selfPath);
            exit;
        } else {
            record_login_failure();
            usleep(600000);
            $error = 'Incorrect email or password.';
        }
    } elseif ($action === 'logout') {
        logout();
        header('Location: ' . $selfPath . '?logged_out=1');
        exit;
    }
}
if (isset($_GET['logged_out'])) {
    $notice = 'You have been logged out.';
}

$loggedIn = is_logged_in();
$asset = function (string $file): string {
    return e($file . '?v=' . @filemtime(__DIR__ . '/' . $file));
};
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <title><?= $loggedIn ? 'Admin panel' : ($account ? 'Admin login' : 'Admin setup') ?> · Aeris Clean</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@600;700;800&family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
  <link rel="icon" href="emblem.webp" type="image/webp">
  <link rel="stylesheet" href="<?= $asset('admin.css') ?>">
</head>
<body class="<?= $loggedIn ? 'is-app' : 'is-auth' ?>">
<?php if (!$loggedIn): ?>
  <main class="auth">
    <form class="auth-card" method="post" autocomplete="on">
      <span class="aeris-brand"><img src="emblem.webp" alt="" width="40" height="33"><span>AERIS <b>CLEAN</b></span></span>
      <?php if (!$account): ?>
        <h1>Create your admin account</h1>
        <p class="auth-lead">First-time setup. This email and password will be used to log in to the admin panel.</p>
      <?php else: ?>
        <h1>Admin login</h1>
        <p class="auth-lead">Sign in to manage the products on aerisclean.in.</p>
      <?php endif; ?>
      <?php if ($error): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
      <?php if ($notice): ?><div class="alert alert-ok" role="status"><?= e($notice) ?></div><?php endif; ?>
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="<?= $account ? 'login' : 'setup' ?>">
      <label class="field"><span>Email</span>
        <input type="email" name="email" required autocomplete="username" value="<?= e($_POST['email'] ?? '') ?>" autofocus>
      </label>
      <label class="field"><span>Password</span>
        <input type="password" name="password" required <?= $account ? 'autocomplete="current-password"' : 'autocomplete="new-password" minlength="10"' ?>>
      </label>
      <?php if (!$account): ?>
        <label class="field"><span>Confirm password</span>
          <input type="password" name="confirm" required minlength="10" autocomplete="new-password">
        </label>
        <p class="hint">At least 10 characters.</p>
      <?php endif; ?>
      <button class="btn btn-primary btn-block" type="submit"><?= $account ? 'Log in' : 'Create account & continue' ?></button>
      <a class="auth-back" href="../">← Back to website</a>
    </form>
  </main>
<?php else: ?>
  <header class="topbar">
    <div class="topbar-inner">
      <a class="brand" href="./"><span class="aeris-brand"><img src="emblem.webp" alt="" width="40" height="33"><span>AERIS <b>CLEAN</b></span></span><span class="brand-tag">Admin</span></a>
      <nav class="tabs" id="tabs" aria-label="Admin sections">
        <a href="#products" data-tab="products">Products</a>
        <a href="#range" data-tab="range">We specialize in</a>
        <a href="#section" data-tab="section">Section text</a>
        <a href="#account" data-tab="account">Account</a>
      </nav>
      <div class="topbar-actions">
        <a class="btn btn-ghost" href="../#products" target="_blank" rel="noopener">Preview site ↗</a>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <button class="btn btn-ghost" name="action" value="logout">Log out</button>
        </form>
      </div>
    </div>
  </header>
  <?= icon_sprite() ?>
  <main class="app" id="app" aria-live="polite"></main>
  <div class="toast" id="toast" role="status" aria-live="polite"></div>
  <script type="application/json" id="initial-data"><?= json_encode(
      [
          'content' => load_content(),
          'email' => admin_account()['email'],
          'icons' => RANGE_ICONS,
          'reservedIds' => array_keys(reserved_ids()),
          'siteUrl' => 'https://www.aerisclean.in/',
          'uploadLimit' => upload_limit_bytes(),
          'uploadLimitText' => format_bytes(upload_limit_bytes()),
          'canPublish' => can_publish(),
          'publish' => publish_status(),
      ],
      JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
  ) ?></script>
  <script src="<?= $asset('admin.js') ?>"></script>
<?php endif; ?>
</body>
</html>

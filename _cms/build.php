<?php
/** Rebuilds index.html from the template and the content files in _cms/data:  php _cms/build.php
 *  Used by the Mac admin (publish.sh) and by the GitHub Action that deploys the website. */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/render.php';
try {
    $bytes = build_site(true);
    echo 'Built index.html (' . format_bytes($bytes) . ")\n";
} catch (Throwable $ex) {
    fwrite(STDERR, 'ERROR: ' . $ex->getMessage() . "\n");
    exit(1);
}

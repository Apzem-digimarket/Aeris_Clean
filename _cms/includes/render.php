<?php
/**
 * Builds the public page from _cms/template.html + the content files in _cms/data (products, range, sections).
 * The template has three slots: <!--CMS:PRODUCTS-->, <!--CMS:QUOTE_OPTIONS-->, <!--CMS:FOOTER_LINKS-->.
 */

function image_tag(string $path, string $alt, string $extra = ''): string
{
    $size = '';
    $info = $path !== '' ? @getimagesize(ROOT_DIR . '/' . $path) : false;
    if ($info) {
        $size = ' width="' . (int) $info[0] . '" height="' . (int) $info[1] . '"';
    }
    return '<img src="' . e($path) . '" alt="' . e($alt) . '"' . $size . $extra . '>';
}

function visible_products(array $c): array
{
    return array_values(array_filter($c['products'], function ($p) { return $p['visible']; }));
}

function render_product(array $p, bool $flip): string
{
    $o = [];
    $o[] = '    <!-- ' . e(strtoupper(trim($p['title'] . ' ' . $p['titleAccent']))) . ' -->';
    $o[] = '    <article class="product' . ($flip ? ' flip' : '') . '" id="' . e($p['slug']) . '">';
    $o[] = '      <div class="p-media">';
    if ($p['mediaLabel'] !== '') {
        $o[] = '        <p class="eyebrow" style="align-self:flex-start">' . e($p['mediaLabel']) . '</p>';
    }
    if ($p['customMedia'] !== '') {
        $o[] = '        ' . $p['customMedia'];   // admin-provided drawing (validated: no scripts/handlers)
    } elseif ($p['image'] !== '') {
        $o[] = '        <div class="shot" style="max-width:360px">';
        $o[] = '          ' . image_tag($p['image'], $p['alt'] !== '' ? $p['alt'] : trim($p['title'] . ' ' . $p['titleAccent']));
        $o[] = '        </div>';
    }
    if ($p['taglineBold'] !== '' || $p['taglineText'] !== '') {
        $tag = $p['taglineBold'] !== '' ? '<b>' . e($p['taglineBold']) . '</b>' : '';
        $tag .= ($p['taglineBold'] !== '' && $p['taglineText'] !== '' ? ' · ' : '') . e($p['taglineText']);
        $o[] = '';
        $o[] = '        <p class="tagline">' . $tag . '</p>';
    }
    $o[] = '      </div>';
    $o[] = '      <div class="p-copy">';
    if ($p['kicker'] !== '') {
        $o[] = '        <span class="p-kicker">' . e($p['kicker']) . '</span>';
    }
    $o[] = '        <h3 class="p-title">' . e($p['title']) . ($p['titleAccent'] !== '' ? ' <span class="alt">' . e($p['titleAccent']) . '</span>' : '') . '</h3>';
    if ($p['subtitle'] !== '') {
        $o[] = '        <p class="p-sub">' . e($p['subtitle']) . '</p>';
    }
    if ($p['description'] !== '') {
        $o[] = '        <p class="p-desc">' . e($p['description']) . '</p>';
    }
    if ($p['features']) {
        $o[] = '';
        $o[] = '        <ul class="ticks" aria-label="Key features">';
        foreach ($p['features'] as $f) {
            $o[] = '          <li><svg><use href="#i-check"/></svg>' . e($f) . '</li>';
        }
        $o[] = '        </ul>';
    }
    if ($p['highlights']) {
        $o[] = '';
        $o[] = '        <div class="hl">';
        foreach ($p['highlights'] as $h) {
            $o[] = '          <div><b>' . e($h['title']) . '</b><span>' . e($h['text']) . '</span></div>';
        }
        $o[] = '        </div>';
    }
    if ($p['specs']) {
        $o[] = '';
        $o[] = '        <details class="spec" open>';
        $o[] = '          <summary><span class="sum-l"><svg><use href="#i-doc"/></svg>' . e($p['specsTitle'] !== '' ? $p['specsTitle'] : 'Specifications') . '</span><svg class="chev"><use href="#i-chev"/></svg></summary>';
        $o[] = '          <div class="tbl">';
        $o[] = '            <table>';
        foreach ($p['specs'] as $s) {
            $o[] = '              <tr><th>' . e($s['label']) . '</th><td>' . e($s['value']) . '</td></tr>';
        }
        $o[] = '            </table>';
        $o[] = '          </div>';
        $o[] = '        </details>';
    }
    if ($p['applications']) {
        $o[] = '';
        $o[] = '        <p class="eyebrow" style="margin-top:30px">' . e($p['applicationsTitle'] !== '' ? $p['applicationsTitle'] : 'Typical Applications') . '</p>';
        $o[] = '        <div class="apps4">';
        foreach ($p['applications'] as $a) {
            $img = $a['image'] !== '' ? image_tag($a['image'], $a['alt'] !== '' ? $a['alt'] : $a['title'], ' loading="lazy"') : '';
            $o[] = '          <figure>' . $img . '<figcaption><b>' . e($a['title']) . '</b>' . e($a['text']) . '</figcaption></figure>';
        }
        $o[] = '        </div>';
    }
    $o[] = '';
    $o[] = '        <div class="p-actions">';
    $o[] = '          <a href="#contact" class="btn btn--primary" data-product="' . e($p['quoteName']) . '">Request a quote <svg><use href="#i-arrow"/></svg></a>';
    if ($p['secondaryLabel'] !== '' && $p['secondaryLink'] !== '') {
        $o[] = '          <a href="' . e($p['secondaryLink']) . '" class="btn btn--ghost">' . e($p['secondaryLabel']) . '</a>';
    }
    $o[] = '        </div>';
    $o[] = '      </div>';
    $o[] = '    </article>';
    return implode("\n", $o);
}

/** Row of product cards at the top of the section; each card jumps to the product's full details. */
function render_product_cards(array $products): string
{
    $o = [];
    $o[] = '    <!-- PRODUCT CARDS -->';
    $o[] = '    <div class="pcards" role="list">';
    foreach ($products as $p) {
        $name = trim($p['title'] . ' ' . $p['titleAccent']);
        $pic = $p['cardImage'] !== '' ? $p['cardImage'] : $p['image'];
        $media = $pic !== ''
            ? '<div class="pcard-img">' . image_tag($pic, '', ' loading="lazy"') . '</div>'
            : '<div class="pcard-img pcard-icon"><svg><use href="#i-particles"/></svg></div>';
        $o[] = '      <a class="pcard" role="listitem" href="#' . e($p['slug']) . '">' . $media
            . ($p['kicker'] !== '' ? '<span class="p-kicker">' . e($p['kicker']) . '</span>' : '')
            . '<h3 class="pcard-title">' . e($p['title']) . ($p['titleAccent'] !== '' ? ' <span class="alt">' . e($p['titleAccent']) . '</span>' : '') . '</h3>'
            . ($p['subtitle'] !== '' ? '<p>' . e($p['subtitle']) . '</p>' : '')
            . '<span class="pcard-more" aria-hidden="true">View details <svg><use href="#i-arrow"/></svg></span>'
            . '<span class="sr-only"> – view details of ' . e($name) . '</span></a>';
    }
    $o[] = '    </div>';
    return implode("\n", $o);
}

function render_products_section(array $c): string
{
    $s = $c['section'];
    $o = [];
    $o[] = '<section class="band" id="products">';
    $o[] = '  <div class="wrap">';
    $o[] = '    <div class="section-head">';
    $o[] = '      <p class="eyebrow">' . e($s['eyebrow']) . '</p>';
    $o[] = '      <h2 class="h2">' . e($s['title']) . '</h2>';
    if ($s['lede'] !== '') {
        $o[] = '      <p class="lede">' . e($s['lede']) . '</p>';
    }
    $o[] = '    </div>';
    $visible = visible_products($c);
    if ($s['showCards'] && $visible) {
        $o[] = '';
        $o[] = render_product_cards($visible);
    }
    foreach ($visible as $i => $p) {
        $o[] = '';
        $o[] = render_product($p, $i % 2 === 1);
    }
    $r = $c['range'];
    $items = array_values(array_filter($r['items'], function ($it) { return $it['visible']; }));
    if ($items) {
        $o[] = '';
        $o[] = '    <!-- RANGE -->';
        $o[] = '    <div class="range">';
        $o[] = '      <div class="section-head">';
        $o[] = '        <p class="eyebrow">' . e($r['eyebrow']) . '</p>';
        $o[] = '        <h2 class="h2">' . e($r['title']) . '</h2>';
        if ($r['lede'] !== '') {
            $o[] = '        <p class="lede">' . e($r['lede']) . '</p>';
        }
        $o[] = '      </div>';
        $o[] = '      <div class="range-grid">';
        foreach ($items as $it) {
            $pic = $it['image'] !== '' ? '<img src="' . e($it['image']) . '" alt="">'
                : '<svg><use href="#' . e($it['icon'] !== '' ? $it['icon'] : 'i-fan') . '"/></svg>';
            $o[] = '        <div class="range-card"><div class="pic">' . $pic . '</div><h4>' . e($it['title']) . '</h4><p>' . e($it['text']) . '</p></div>';
        }
        $o[] = '      </div>';
        $o[] = '    </div>';
    }
    $o[] = '  </div>';
    $o[] = '</section>';
    return implode("\n", $o);
}

/** Options for the quote form's "Product of interest" list. */
function render_quote_options(array $c): string
{
    $names = [];
    foreach (visible_products($c) as $p) {
        $names[] = $p['quoteName'];
    }
    foreach ($c['range']['items'] as $it) {
        if ($it['visible'] && $it['inQuoteForm']) {
            $names[] = $it['title'];
        }
    }
    $names = array_values(array_unique($names));
    $o = '';
    foreach ($names as $n) {
        $o .= "\n                <option>" . e($n) . '</option>';
    }
    return $o . "\n              ";
}

/** Footer list of product categories; each links to its product, or to the products section. */
function render_footer_links(array $c): string
{
    $slugById = [];
    foreach (visible_products($c) as $p) {
        $slugById[$p['id']] = $p['slug'];
    }
    $li = '';
    foreach ($c['range']['items'] as $it) {
        if (!$it['visible']) {
            continue;
        }
        $href = isset($slugById[$it['link']]) ? $slugById[$it['link']] : 'products';
        $li .= '<li><a href="#' . e($href) . '">' . e($it['title']) . '</a></li>';
    }
    return '<ul>' . $li . '</ul>';
}

function render_page(array $c): string
{
    $tpl = (string) file_get_contents(TEMPLATE_FILE);
    foreach (['<!--CMS:PRODUCTS-->', '<!--CMS:QUOTE_OPTIONS-->', '<!--CMS:FOOTER_LINKS-->'] as $slot) {
        if (substr_count($tpl, $slot) !== 1) {
            throw new RuntimeException('Template slot missing or duplicated: ' . $slot);
        }
    }
    return strtr($tpl, [
        '<!--CMS:PRODUCTS-->' => render_products_section($c),
        '<!--CMS:QUOTE_OPTIONS-->' => render_quote_options($c),
        '<!--CMS:FOOTER_LINKS-->' => render_footer_links($c),
    ]);
}

/** Writes the finished page to index.html. Returns its size in bytes. */
function build_site(bool $strict = false): int
{
    $html = render_page(load_content($strict));
    write_file_atomic(OUTPUT_FILE, $html);
    return strlen($html);
}

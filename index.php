<?php
date_default_timezone_set('Europe/Helsinki');
define('CACHE', sys_get_temp_dir() . '/lunchy');
define('TTL', 7200);

$today_dow = (int)date('N');
$offset    = ($today_dow < 5 && isset($_GET['d'])) ? 1 : 0; // only allow +1 on Mon–Thu
$target_ts = mktime(0, 0, 0, (int)date('n'), (int)date('j') + $offset, (int)date('Y'));
$dow       = (int)date('N', $target_ts);
$day_s     = ['','ma','ti','ke','to','pe','la','su'][$dow];
$day_l     = ['','maanantai','tiistai','keskiviikko','torstai','perjantai','lauantai','sunnuntai'][$dow];
$weekday   = ['','Maanantai','Tiistai','Keskiviikko','Torstai','Perjantai','Lauantai','Sunnuntai'][$dow];
$date_str  = date('j.n.Y', $target_ts);
$is_wd     = $dow >= 1 && $dow <= 5;
$refresh   = isset($_GET['r']);
$show_nav  = $today_dow >= 1 && $today_dow <= 4; // Mon–Thu only

if (isset($_GET['city']) && in_array($_GET['city'], ['turku', 'helsinki'], true)) {
    setcookie('lunchy_city', $_GET['city'], time() + 365 * 24 * 3600, '/');
    header('Location: ' . ($offset ? '?d=1' : '?'));
    exit;
}
$city = $_COOKIE['lunchy_city'] ?? 'turku';

function fetch(string $url, bool $force = false, bool $ssl_verify = true): ?string {
    @mkdir(CACHE, 0755, true);
    $f = CACHE . '/' . md5($url);
    if (!$force && is_file($f) && (time() - filemtime($f)) < TTL)
        return file_get_contents($f) ?: null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Linux) AppleWebKit/537.36 Chrome/125.0 Safari/537.36',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => $ssl_verify,
    ]);
    $html = curl_exec($ch);
    curl_close($ch);
    if ($html && strlen($html) > 500) { @file_put_contents($f, $html); return $html; }
    return is_file($f) ? (file_get_contents($f) ?: null) : null;
}

// Squarespace menu-block parser (Blanko, Nera)
function parse_menu_block(string $html, string $day): array {
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    $xp  = new DOMXPath($dom);
    $els = $xp->query('//*[contains(@class,"menu-item")]');
    $out = []; $active = false;
    foreach ($els as $el) {
        $tn = $xp->query('.//*[contains(@class,"menu-item-title")]', $el);
        $dn = $xp->query('.//*[contains(@class,"menu-item-description")]', $el);
        if (!$tn->length) continue;
        $t = trim(preg_replace('/\s+/', ' ', $tn->item(0)->textContent));
        if (mb_strlen($t) <= 2 && preg_match('/^[a-zA-Z]+$/', $t)) {
            $active = mb_strtolower($t) === $day;
            continue;
        }
        if ($active) {
            $d = $dn->length ? trim(preg_replace('/\s+/', ' ', $dn->item(0)->textContent)) : '';
            $out[] = ['title' => $t, 'desc' => $d];
        }
    }
    return $out;
}

// Tinta parser (sqs-html-content paragraph blocks)
function parse_tinta(string $html, string $day): array {
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    $xp = new DOMXPath($dom);
    $blocks = $xp->query('//*[contains(@class,"sqs-html-content")]');
    foreach ($blocks as $blk) {
        $bt = $blk->textContent;
        if (!preg_match('/viikko|\bma\b|\bti\b|\bke\b|\bto\b|\bpe\b/iu', $bt) || strlen($bt) < 80) continue;
        $paras = $xp->query('.//p', $blk);
        $out = []; $active = false; $cur = null;
        foreach ($paras as $p) {
            $t = html_entity_decode($p->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $t = preg_replace('/\xc2\xa0|\x{00A0}/u', '', $t);
            $t = trim(preg_replace('/\s+/', ' ', $t));
            if (!$t) continue;
            if (mb_strlen($t) === 2 && preg_match('/^(ma|ti|ke|to|pe|la|su)$/iu', $t)) {
                if ($active && $cur) { $out[] = ['title' => $cur, 'desc' => '']; $cur = null; }
                $active = mb_strtolower($t) === $day;
                continue;
            }
            if (!$active) continue;
            $has_price = preg_match('/\d{2},\d{2}/', $t) || preg_match('/\d+\s*[€e]/u', $t);
            if ($has_price) {
                if ($cur) $out[] = ['title' => $cur, 'desc' => ''];
                $cur = $t;
            } elseif ($cur && strlen($t) > 2) {
                $out[] = ['title' => $cur, 'desc' => $t];
                $cur = null;
            }
        }
        if ($cur) $out[] = ['title' => $cur, 'desc' => ''];
        return $out;
    }
    return [];
}

// Strip scripts/styles and return cleaned HTML, cached per page
function strip_page(string $html): string {
    return preg_replace('/<(script|style)[^>]*>.*?<\/\1>/is', '', $html) ?? $html;
}

// Generic day-section parser for sites using {prefix}-{day} element IDs (Fontana, Tårget)
function parse_day_id(string $html, string $prefix, string $day_s, string $day_l): array {
    $clean = strip_page($html);
    $pos = strpos($clean, 'id="' . $prefix . '-' . $day_s . '"');
    if ($pos === false) return [];
    $chunk = substr($clean, strpos($clean, '>', $pos) + 1, 6000);
    $next = strpos($chunk, '"' . $prefix . '-');
    if ($next !== false) $chunk = substr($chunk, 0, $next);
    $lines = html_to_lines($chunk);
    $has_d = false;
    $dl = lines_for_day($lines, $day_l, $has_d);
    return lines_to_items($has_d ? $dl : $lines);
}

// di Trevi: combines trevi-viikon weekly items with today's ditrevi-{day} section
function parse_ditrevi(string $html, string $day_s, string $day_l): array {
    $clean = strip_page($html);
    $pos = strpos($clean, 'id="trevi-viikon"');
    if ($pos === false) return [];
    $chunk = substr($clean, strpos($clean, '>', $pos) + 1, 15000);
    $lines = html_to_lines($chunk);
    $has_d = false;
    $dl = lines_for_day($lines, $day_l, $has_d);
    return lines_to_items($has_d ? $dl : $lines);
}

// Agnes: weekly menu from the text-editor widget, stops before set-menu section
function parse_agnes(string $html): array {
    $clean = strip_page($html);
    $pos = 0;
    while (($wpos = strpos($clean, 'data-widget_type="text-editor.default"', $pos)) !== false) {
        // Skip past the closing > of the opening div tag
        $tag_end = strpos($clean, '>', $wpos);
        if ($tag_end === false) break;
        $chunk = substr($clean, $tag_end + 1, 4000);
        if (preg_match('/\d+\s*€/', $chunk)) {
            $stop = strpos($chunk, 'LOUNASMENU');
            if ($stop !== false) $chunk = substr($chunk, 0, $stop);
            return lines_to_items(html_to_lines($chunk));
        }
        $pos = $wpos + 1;
    }
    return [];
}

// Nobi: nobi-viikon section — weekly items (pre-day) + today's day-specific item
function parse_nobi(string $html, string $day_l): array {
    $clean = strip_page($html);
    $pos = strpos($clean, 'id="nobi-viikon"');
    if ($pos === false) return [];
    $chunk = substr($clean, strpos($clean, '>', $pos) + 1, 8000);
    $lines = html_to_lines($chunk);
    $has_d = false;
    $dl = lines_for_day($lines, $day_l, $has_d);
    return lines_to_items($has_d ? $dl : $lines);
}

function html_to_lines(string $html): array {
    $t = preg_replace('/<(h[1-6]|p|li|br|\/p|\/h[1-6])[^>]*>/i', "\n", $html);
    $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = preg_replace('/\xc2\xa0|\x{00A0}/u', ' ', $t);
    $t = preg_replace('/[^\S\n]+/', ' ', $t);
    return array_values(array_filter(array_map('trim', explode("\n", $t))));
}

function lines_for_day(array $lines, string $day_l, bool &$has_days): array {
    $all = ['maanantai','tiistai','keskiviikko','torstai','perjantai','lauantai'];
    $has_days = false;
    foreach ($lines as $l) {
        $ll = mb_strtolower($l);
        foreach ($all as $d)
            if ($ll === $d || str_starts_with($ll, $d . ' ') || str_starts_with($ll, $d . '.')) { $has_days = true; break 2; }
    }
    if (!$has_days) return $lines;

    $pre = []; $out = []; $active = false; $seen_day = false;
    foreach ($lines as $l) {
        $ll = mb_strtolower($l);
        $matched = null; $rest = '';
        foreach ($all as $d) {
            if ($ll === $d)                       { $matched = $d; $rest = '';                              break; }
            if (str_starts_with($ll, $d . ' '))  { $matched = $d; $rest = trim(substr($l, mb_strlen($d))); break; }
            if (str_starts_with($ll, $d . '.'))  { $matched = $d; $rest = trim(substr($l, mb_strlen($d))); break; }
        }
        if ($matched !== null) {
            $seen_day = true;
            $active   = ($matched === mb_strtolower($day_l));
            if ($active && $rest) $out[] = $rest;
            continue;
        }
        if (!$seen_day) $pre[] = $l;
        elseif ($active) $out[] = $l;
    }
    return array_merge($pre, $out);
}

function lines_to_items(array $lines): array {
    $skip = '/^(viikko|viikon (lounas|alkuruoka|p)|tarjolla|lounas ?pöytä|take away|menu du jour|päivän lounas|koko viikon lista|lounasmenu|business lunch|etkö löytänyt|tutustu|turun lounaat|\*{3}|[LGVM]+ *= |lounaspöytään|talon (leip|jälki)|\bklo \d|\d+ ruokalajia|www\.|lounas tarjolla|lounaalla|lounas tarjoillaan|lounas sisältää|huom|nobi business|mystery of|à la carte|a la carte|lounasaikaan)/iu';
    $out = []; $cur = null;
    foreach ($lines as $l) {
        if (strlen($l) < 3 || mb_stripos($l, 'salaattipöytä') !== false || preg_match($skip, $l)) continue;
        // \d{2},\d{2} = Finnish price (14,90); \d+\s*€ = with euro sign; \s\d{1,3}$ = bare number at end
        $has_price = preg_match('/\d{2},\d{2}/', $l) || preg_match('/\d+\s*€/u', $l) || preg_match('/\s\d{1,3}$/', $l);
        if ($has_price) {
            if ($cur !== null) $out[] = ['title' => $cur, 'desc' => ''];
            $cur = $l;
        } elseif ($cur !== null && strlen($l) > 3) {
            if (str_starts_with($l, '(') && str_ends_with(rtrim($l), ')')) {
                $cur .= ' ' . $l; // parenthetical allergy note: merge into title
            } else {
                $out[] = ['title' => $cur, 'desc' => $l];
                $cur = null;
            }
        } elseif (strlen($l) > 3 && str_ends_with($l, ':')) {
            if ($cur !== null) $out[] = ['title' => $cur, 'desc' => ''];
            $cur = $l;
        } else {
            if ($cur !== null) $out[] = ['title' => $cur, 'desc' => ''];
            $cur = $l;
        }
    }
    if ($cur !== null) $out[] = ['title' => $cur, 'desc' => ''];
    return $out;
}

// Roots Kitchen parser — lunch-row divs, today marked with id="tanaan"
function parse_roots(string $html): array {
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    $xp = new DOMXPath($dom);
    $today = $xp->query('//*[@id="tanaan"]');
    if (!$today->length) return [];

    // Extract per-category prices from h4 headers — !isset() stops at first match per category
    $cat_prices = [];
    foreach ($xp->query('//h4') as $h4) {
        $t = $h4->textContent;
        if (!isset($cat_prices['lämmin'])       && preg_match('/\blämmin ruoka\s+(\d+[,.]\d+|\d+)\s*€/iu', $t, $m))  $cat_prices['lämmin'] = $m[1] . ' €';
        if (!isset($cat_prices['salaatti'])      && preg_match('/\bsalaatti\s+(\d+[,.]\d+|\d+)\s*€/iu', $t, $m))      $cat_prices['salaatti'] = $m[1] . ' €';
        if (!isset($cat_prices['viikon keitto']) && preg_match('/\bkeitto\s+(\d+[,.]\d+|\d+)\s*€/iu', $t, $m))         $cat_prices['viikon keitto'] = $m[1] . ' €';
        if (!isset($cat_prices['perjantaiburger'])&& preg_match('/\bburger\s+(\d+[,.]\d+|\d+)\s*€/iu', $t, $m))        $cat_prices['perjantaiburger'] = $m[1] . ' €';
    }

    $items = [];
    $cat_words = ['lämmin','lisäke','salaatti','keitto','viikon keitto','keittopäivä','jälkiruoka','kylmä','kasvis','perjantaiburger'];
    $pairs = [];
    foreach ($xp->query('.//*[@dir="auto"]', $today->item(0)) as $el) {
        $t = trim(preg_replace('/\s+/', ' ', $el->textContent));
        if (!$t) continue;
        $colon = strpos($t, ':');
        if ($colon !== false) {
            $before = trim(substr($t, 0, $colon));
            $after  = trim(substr($t, $colon + 1));
            $cat_lc = mb_strtolower($before);
            if (in_array($cat_lc, $cat_words, true) && $after)
                $pairs[] = ['cat' => $cat_lc, 'dish' => $after];
            else
                $pairs[] = ['cat' => '', 'dish' => $t];
        } else {
            $pairs[] = ['cat' => '', 'dish' => $t];
        }
    }
    // Pass 1: assemble titles and descs without prices so continuation checks work
    foreach ($pairs as $p) {
        $last = count($items) - 1;
        if ($p['cat'] === 'lisäke' && $last >= 0) {
            // Append side to previous item's desc
            $items[$last]['desc'] .= ($items[$last]['desc'] ? ', ' : '') . $p['dish'];
        } elseif ($p['cat'] === '' && $last >= 0 && str_ends_with(rtrim($items[$last]['desc']), ',')) {
            // Continuation of split dish text
            $items[$last]['desc'] .= ' ' . $p['dish'];
        } elseif ($p['cat'] !== '') {
            // Category item: bold label+price as title, dish as desc
            $price = $cat_prices[$p['cat']] ?? '';
            $label = mb_strtoupper(mb_substr($p['cat'], 0, 1, 'UTF-8')) . mb_substr($p['cat'], 1, null, 'UTF-8');
            $items[] = ['title' => $label . ($price ? ' ' . $price : ''), 'desc' => $p['dish']];
        } else {
            $items[] = ['title' => $p['dish'], 'desc' => ''];
        }
    }
    return $items;
}

// Brahen Kellari: custom CMS, day sections by id="{day_l}" with .nimi.fi dish divs
function parse_brahenkellari(string $html, string $day_l): array {
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    $xp = new DOMXPath($dom);
    $day_div = $xp->query('//*[@id="' . mb_strtolower($day_l) . '"]');
    if (!$day_div->length) return [];
    $items = [];
    foreach ($xp->query('.//*[contains(@class,"nimi")]', $day_div->item(0)) as $el) {
        $title = html_entity_decode(trim(preg_replace('/\s+/', ' ', $el->textContent)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = trim(preg_replace('/[\xc2\xa0\x{00A0}]/u', ' ', $title));
        $title = trim(preg_replace('/\s+/', ' ', $title));
        if (strlen($title) > 3 && preg_match('/\d+[,.]\d+\s*€/', $title))
            $items[] = ['title' => $title, 'desc' => ''];
    }
    return $items;
}

// Koulu: Elementor text-editor post listing, day headers include date (e.g. "Keskiviikko 24.6.")
function parse_koulu(string $html, string $day_s, string $day_l): array {
    $clean = strip_page($html);
    $all_days = ['maanantai','tiistai','keskiviikko','torstai','perjantai'];
    $pos = 0;
    while (($wpos = strpos($clean, 'data-widget_type="text-editor.default"', $pos)) !== false) {
        $tag_end = strpos($clean, '>', $wpos);
        if ($tag_end === false) break;
        $chunk = substr($clean, $tag_end + 1, 8000);
        if (!preg_match('/maanantai|tiistai|keskiviikko|torstai|perjantai/i', $chunk)) {
            $pos = $wpos + 1; continue;
        }
        $lines = html_to_lines($chunk);
        // Only collect lines belonging to today — skip boilerplate $pre and date rests
        $out = []; $active = false;
        foreach ($lines as $l) {
            $ll = mb_strtolower($l);
            $is_day = false;
            foreach ($all_days as $d) {
                if ($ll === $d || str_starts_with($ll, $d . ' ') || str_starts_with($ll, $d . '.')) {
                    $is_day = true; $active = ($d === mb_strtolower($day_l)); break;
                }
            }
            if ($is_day) continue;
            if ($active) {
                if (preg_match('/^(lounaan voit|voit hakea|meiltä saat|alkuperämaat|pidätämme|tervetuloa|olemme auki|keittiömme|kysy lisää|panimoravintola)/iu', $l)) break;
                $out[] = $l;
            }
        }
        if (!$out) { $pos = $wpos + 1; continue; }
        $skip = '/^(keittolounas|noutopöytä|koko lounas|molemmat|lounasbuffet|lounaalla|klo |arkisin|€|www\.|^\d+\.\d+\.)/iu';
        $items = [];
        foreach ($out as $l) {
            if (strlen($l) < 4 || mb_stripos($l, 'salaattipöytä') !== false || preg_match($skip, $l)) continue;
            $items[] = ['title' => $l, 'desc' => ''];
        }
        return $items;
    }
    return [];
}

// Lounastaja JSON parser — used for Roslund (Teurastamon Portti)
function parse_lounastaja_json(string $json, int $dow): array {
    $data = json_decode($json, true);
    if (!$data || empty($data['success']) || !isset($data['data']['week']['days'])) return [];
    foreach ($data['data']['week']['days'] as $day) {
        if (($day['dayNumber'] ?? 0) !== $dow) continue;
        if (!empty($day['isClosed'])) return [];
        $items = [];
        foreach ($day['lunches'] ?? [] as $lunch) {
            $title = trim($lunch['title']['fi'] ?? '');
            if (!$title) continue;
            $allergens = implode(', ', array_map(fn($a) => $a['abbreviation']['fi'], $lunch['allergens'] ?? []));
            if ($allergens) $title .= ' (' . $allergens . ')';
            $price = $lunch['normalPrice']['price'] ?? '';
            $unit  = $lunch['normalPrice']['unit']['fi'] ?? '€';
            if ($price) $title .= ' ' . $price . ' ' . $unit;
            $desc = trim($lunch['description']['fi'] ?? '');
            $items[] = ['title' => $title, 'desc' => $desc];
        }
        return $items;
    }
    return [];
}

// Lime Leaf parser — WordPress, days grouped in <strong> headings
function parse_lime_leaf(string $html, string $day_l): array {
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    $xp = new DOMXPath($dom);
    $day_lc = mb_strtolower($day_l);
    $paras = [];
    foreach ($xp->query('//div[contains(@class,"entry-content")]//p') as $p) $paras[] = $p;
    $found = null;
    for ($i = 0; $i < count($paras); $i++) {
        $strongs = $xp->query('.//strong', $paras[$i]);
        if (!$strongs->length) continue;
        if (str_contains(mb_strtolower($strongs->item(0)->textContent), $day_lc)) { $found = $i; break; }
    }
    if ($found === null) return [];
    $items = [];
    for ($i = $found + 1; $i < count($paras); $i++) {
        $p = $paras[$i];
        $strongs = $xp->query('.//strong', $p);
        if ($strongs->length && preg_match('/maanantai|tiistai|keskiviikko|torstai|perjantai|lauantai|sunnuntai/iu', $strongs->item(0)->textContent)) break;
        $lines = html_to_lines($dom->saveHTML($p));
        foreach ($lines as $l) {
            if (strlen($l) < 4) continue;
            if (mb_stripos($l, 'salaattipöytä') !== false) continue;
            if (preg_match('/^(jäätelö|kahvi|tee\b)/iu', $l)) continue;
            $items[] = ['title' => $l, 'desc' => ''];
        }
    }
    return $items;
}

// --- Build cards ---
$cards = [];

if ($city === 'turku') {
    if ($is_wd && ($html = fetch('https://www.ravintolaagnes.fi/lounas/', $refresh))) {
        $items = parse_agnes($html);
        if ($items) $cards[] = ['name' => 'Agnes', 'url' => 'https://www.ravintolaagnes.fi/lounas/', 'items' => $items, 'hours' => '11–14'];
    }
    if ($is_wd && ($html = fetch('https://www.ravintolanobi.fi/lounas/', $refresh))) {
        $items = parse_nobi($html, $day_l);
        if ($items) $cards[] = ['name' => 'Nobi', 'url' => 'https://www.ravintolanobi.fi/lounas/', 'items' => $items, 'hours' => '11–14'];
    }
    if ($is_wd && ($html = fetch('https://fontana.fi/lunch/', $refresh))) {
        $items = parse_day_id($html, 'fontana', $day_s, $day_l);
        if ($items) $cards[] = ['name' => 'Fontana', 'url' => 'https://fontana.fi/lunch/', 'items' => $items, 'hours' => '11–14'];
    }
    if ($is_wd && ($html = fetch('https://www.matbar.fi/lounas/', $refresh))) {
        $items = parse_day_id($html, 'matbar', $day_s, $day_l);
        $tprice = preg_match('/Lounaspöydän hinta (\d+[,.]\d+|\d+)\s*€/iu', $html, $m) ? $m[1] . ' €' : '';
        if ($items) $cards[] = ['name' => 'Tårget', 'url' => 'https://www.matbar.fi/lounas/', 'items' => $items, 'hours' => '11–15', 'price' => $tprice];
    }
    if ($is_wd && ($html = fetch('https://ditrevi.fi/lounas/', $refresh))) {
        $items = parse_ditrevi($html, $day_s, $day_l);
        if ($items) $cards[] = ['name' => 'di Trevi', 'url' => 'https://ditrevi.fi/lounas/', 'items' => $items, 'hours' => '11–14'];
    }
    if ($is_wd && ($html = fetch('https://www.brahenkellari.fi/fi/menu/lounas/lounaslista', $refresh, false))) {
        $items = parse_brahenkellari($html, $day_l);
        if ($items) $cards[] = ['name' => 'Brahen Kellari', 'url' => 'https://www.brahenkellari.fi/fi/menu/lounas/lounaslista', 'items' => $items, 'hours' => '11–14'];
    }
    if ($is_wd && ($bl = fetch('https://blanko.net/lounas', $refresh))) {
        $items = parse_menu_block($bl, $day_s);
        if ($items) $cards[] = ['name' => 'Blanko', 'url' => 'https://blanko.net/lounas', 'items' => $items, 'hours' => '11–15'];
    }
    if ($is_wd && ($ne = fetch('https://www.nera.fi/lounas', $refresh))) {
        $items = parse_menu_block($ne, $day_s);
        if ($items) $cards[] = ['name' => 'Nerå', 'url' => 'https://www.nera.fi/lounas', 'items' => $items, 'hours' => '11–14:30'];
    }
    if ($is_wd && ($ti = fetch('https://www.tinta.fi/lounas', $refresh))) {
        $items = parse_tinta($ti, $day_s);
        if ($items) $cards[] = ['name' => 'Tintå', 'url' => 'https://www.tinta.fi/lounas', 'items' => $items, 'hours' => '11–15'];
    }
    if ($is_wd && ($ro = fetch('https://rootskitchen.fi/kasvisruokalounas-turku/', $refresh))) {
        $items = parse_roots($ro);
        if ($items) $cards[] = ['name' => 'Roots', 'url' => 'https://rootskitchen.fi/kasvisruokalounas-turku/', 'items' => $items, 'hours' => '11–14'];
    }
    if ($is_wd && ($html = fetch('https://www.panimoravintolakoulu.fi/lounas/', $refresh))) {
        $items = parse_koulu($html, $day_s, $day_l);
        $kprice = preg_match('/KOKO NOUTOPÖYTÄ (\d+[,.]\d+|\d+)\s*€/iu', $html, $m) ? $m[1] . ' €' : '';
        if ($items) $cards[] = ['name' => 'Koulu', 'url' => 'https://www.panimoravintolakoulu.fi/lounas/', 'items' => $items, 'hours' => '11–14', 'price' => $kprice];
    }
} else {
    if ($is_wd && ($json = fetch('https://lounastaja.app/api/v1/widget/6caa04b4-245e-483a-a36d-55f7b2a2ddd1/ufCi2XUGEyE20Owmd4nt', $refresh))) {
        $items = parse_lounastaja_json($json, $dow);
        if ($items) $cards[] = ['name' => 'Roslund', 'url' => 'https://roslund.fi/pages/ravintola', 'items' => $items, 'hours' => '11–15'];
    }
    if ($is_wd && ($html = fetch('https://limerestaurants.fi/lime-leaf-lounas/', $refresh))) {
        $items = parse_lime_leaf($html, $day_l);
        $llprice = preg_match('/Arkisin[^€<]*?(\d+[,.]\d+)\s*€/iu', strip_tags($html), $m) ? $m[1] . ' €' : '';
        if ($items) $cards[] = ['name' => 'Lime Leaf', 'url' => 'https://limerestaurants.fi/lime-leaf-lounas/', 'items' => $items, 'hours' => '11–15', 'price' => $llprice];
    }
}

usort($cards, fn($a, $b) => strcasecmp($a['name'], $b['name']));
$fetched_at = date('H:i');
?><!DOCTYPE html>
<html lang="fi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Lounaat &mdash; <?= htmlspecialchars($weekday) ?> <?= $date_str ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#fff;color:#111;font-family:ui-monospace,'Cascadia Code','Courier New',monospace;font-size:14px;line-height:1.65;max-width:800px;margin:0 auto;padding:20px 16px 60px}
.page-header{display:flex;align-items:baseline;justify-content:space-between;border-bottom:2px solid #111;padding-bottom:8px;margin-bottom:28px}
h1{font-size:15px;font-weight:bold;text-transform:uppercase;letter-spacing:.08em}
.city-toggle{font-size:12px;white-space:nowrap;padding-left:16px}
.city-toggle a{color:#111;text-decoration:none;border-bottom:1px solid #111}
.city-toggle a:hover{color:#555;border-color:#555}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:24px}
.card{border-left:3px solid #111;padding-left:12px}
.card-name{font-size:12px;font-weight:bold;text-transform:uppercase;letter-spacing:.1em;margin-bottom:8px}
.card-name a{color:#111;text-decoration:none}
.card-name a:hover{text-decoration:underline}
.weekly-tag{font-weight:normal;text-transform:none;letter-spacing:0;color:#888;margin-left:4px}
.card-price{font-weight:bold;font-size:13px;margin-bottom:6px}
.dish{margin-bottom:6px}
.dish-title{font-weight:bold}
.dish-desc{color:#555;font-size:13px}
.no-lunch{color:#666;font-style:italic}
footer{margin-top:40px;padding-top:10px;border-top:1px solid #ddd;font-size:11px;color:#aaa}
footer a{color:#aaa}
.day-nav{margin-bottom:24px;font-size:12px}
.day-nav a{color:#111;text-decoration:none;border-bottom:1px solid #111}
.day-nav a:hover{color:#555;border-color:#555}
@media(max-width:500px){body{font-size:13px}.grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<?php
$other_city  = $city === 'turku' ? 'helsinki' : 'turku';
$toggle_label = $city === 'turku' ? 'Helsinki' : 'Turku';
$toggle_url   = '?city=' . $other_city . ($offset ? '&d=1' : '');
?>
<div class="page-header">
  <h1>Lounaat &mdash; <?= htmlspecialchars($weekday) ?> <?= $date_str ?></h1>
  <div class="city-toggle"><a href="<?= $toggle_url ?>"><?= $toggle_label ?> &rarr;</a></div>
</div>

<?php if ($show_nav): ?>
<div class="day-nav">
  <?php if ($offset === 0): ?>
    <a href="?d=1">Huominen &rarr;</a>
  <?php else: ?>
    <a href="?">Tänään</a>
  <?php endif ?>
</div>
<?php endif ?>

<?php if (!$is_wd): ?>
<p class="no-lunch">Viikonloppu &mdash; useimmilla ravintoloilla ei lounasta.</p>
<?php elseif (empty($cards)): ?>
<p class="no-lunch">Ei lounastietoja saatavilla tänään.</p>
<?php else: ?>
<div class="grid">
<?php foreach ($cards as $c): ?>
<div class="card">
  <div class="card-name">
    <a href="<?= htmlspecialchars($c['url']) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($c['name']) ?></a><?php if (!empty($c['hours'])): ?><span class="weekly-tag"><?= htmlspecialchars($c['hours']) ?></span><?php endif ?>
  </div>
  <?php if (!empty($c['price'])): ?><div class="card-price"><?= htmlspecialchars($c['price']) ?></div><?php endif ?>
  <?php foreach ($c['items'] as $it): ?>
  <div class="dish">
    <div class="dish-title"><?= htmlspecialchars($it['title']) ?></div>
    <?php if ($it['desc']): ?><div class="dish-desc"><?= htmlspecialchars($it['desc']) ?></div><?php endif ?>
  </div>
  <?php endforeach ?>
</div>
<?php endforeach ?>
</div>
<?php endif ?>

<footer>
  Haettu <?= $fetched_at ?> &nbsp;&middot;&nbsp; <a href="?r=1">Päivitä</a>
</footer>
</body>
</html>

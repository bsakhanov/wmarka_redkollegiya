<?php

/** PHP-сторона сверки казахского слоя: вход и выход — как у kk_reference.py. */

require __DIR__ . '/../bootstrap.php';

use Webmarka\Component\Redkollegiya\Administrator\Engine\Kk\KkLint;
use Webmarka\Component\Redkollegiya\Administrator\Engine\Kk\KkParity;
use Webmarka\Component\Redkollegiya\Administrator\Engine\Kk\KkRestore;

$in  = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$gl  = !empty($in['glossary']) ? json_decode(file_get_contents($in['glossary']), true) : [];
$out = [];

if ($in['mode'] === 'lint') {
    foreach ($in['texts'] as $t) {
        $out[] = array_map(static fn ($s) => [$s['s'], $s['e'], $s['edit'], $s['rule'], $s['severity'], $s['action']], (new KkLint())->spans($t, $gl));
    }
} elseif ($in['mode'] === 'restore') {
    $r   = KkRestore::shared(__DIR__ . '/../../component/administrator/data');
    $glw = [];

    foreach ($gl['entries'] ?? [] as $e) {
        if (!empty($e['kk']) && preg_match_all('/[' . Webmarka\Component\Redkollegiya\Administrator\Engine\Kk\KkLint::CYRL . ']+/u', $e['kk'], $m)) {
            foreach ($m[0] as $w) {
                $glw[] = mb_strtolower($w);
            }
        }
    }

    foreach ($in['texts'] as $t) {
        $out[] = array_map(static fn ($s) => [$s['s'], $s['e'], $s['edit']], $r->spans($t, array_values(array_unique($glw))));
    }
} elseif ($in['mode'] === 'numbers') {
    foreach ($in['texts'] as $t) {
        $out[] = [KkParity::numbers($t), KkParity::latin($t), KkParity::latin($t, true)];
    }
} else {
    foreach ($in['pairs'] as [$ru, $kk]) {
        $out[] = array_map(static fn ($f) => [$f['rule']], KkParity::compare(KkParity::paragraphs($ru), KkParity::paragraphs($kk), $gl));
    }
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

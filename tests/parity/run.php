<?php

/**
 * PHP-сторона сверки: читает JSON {"engine": "milchin"|"kk", "flags": {...}, "texts": [...]} из stdin,
 * пишет JSON-список [{"text": ..., "stats": {...}}] — то же, что parity/reference.py для milchin.py и kk_typo.py.
 */

require __DIR__ . '/../bootstrap.php';

use Webmarka\Component\Redkollegiya\Administrator\Engine\Counter;
use Webmarka\Component\Redkollegiya\Administrator\Engine\KkTypo;
use Webmarka\Component\Redkollegiya\Administrator\Engine\Milchin;

$in  = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$out = [];

foreach ($in['texts'] as $text) {
    $c = new Counter();
    $t = $in['engine'] === 'kk' ? (new KkTypo())->fix($text, $in['flags'], $c) : (new Milchin())->proofread($text, $in['flags'], $c);
    $out[] = ['text' => $t, 'stats' => (object) $c->all()];
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

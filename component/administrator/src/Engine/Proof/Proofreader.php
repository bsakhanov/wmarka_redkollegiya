<?php

/**
 * @package     Webmarka.Redkollegiya
 * @subpackage  com_wmarka_redkollegiya
 * @copyright   (C) 2026 Beibit Sakhanov
 * @license     GNU General Public License version 3 or later; see LICENSE
 */

namespace Webmarka\Component\Redkollegiya\Administrator\Engine\Proof;

use Webmarka\Component\Redkollegiya\Administrator\Engine\Engine;
use Webmarka\Component\Redkollegiya\Administrator\Engine\Html;

\defined('_JEXEC') or die;

/**
 * Вычитка: находки вместо правок. Текст не меняется, пока редактор не примет находку; принятые правки вносятся
 * по месту и дословной цитате (как apply.py Редколлегии): если на месте уже другой текст, правка ищется по цитате,
 * а если цитата встречается в абзаце не один раз — не вносится, решает редактор.
 *
 *   $p = new Proofreader(['genre' => 'news']);
 *   $found = $p->check($html, 'ru-RU');                 // [['id' => 'b2-17', 'block' => 2, 'offset' => 17, 'quote' => …, 'edit' => …], …]
 *   ['text' => $html2, 'applied' => [...], 'skipped' => [...]] = $p->apply($html, $accepted, 'ru-RU');
 */
final class Proofreader
{
    public const DEFAULTS = [
        'genre'     => 'other',   // news — слова-маркеры нейрослопа не проверяются (в новостях это норма жанра)
        'slop'      => true,      // следы чат-ботов, фразы и (не в новостях) слова-маркеры
        'kanc'      => true,      // канцелярит: проход А методологии и Чуковский
        'rozental'  => true,      // закрытые списки Розенталя
        'yo'        => true,      // «ё» в двояких словах (если «ё» на сайте восстанавливается)
        'rhythm'    => true,      // однообразный ритм абзаца
        'cluster'   => 3,         // столько маркеров в абзаце — уже стая
    ];

    private array $o;

    public function __construct(array $options = [])
    {
        $this->o = array_replace(self::DEFAULTS, array_intersect_key($options, self::DEFAULTS));
    }

    /** Находки по тексту (разметка или строка). */
    public function check(string $input, string $lang = '', bool $html = true): array
    {
        $lang = Engine::language($lang, $input);

        if ($lang === '') {
            return [];
        }

        $found = [];
        $block = 0;
        $fn    = function (string $text) use (&$found, &$block, $lang): string {
            array_push($found, ...$this->block($text, $block, $lang));
            $block++;

            return $text;
        };

        $html ? (new Html())->process($input, $fn) : self::plain($input, $fn);

        return $found;
    }

    /**
     * Внести принятые правки.
     *
     * @param  array<int, array{block: int, offset: int, quote: string, edit: string, id?: string}>  $accepted
     * @return array{text: string, applied: string[], skipped: string[]}
     */
    public function apply(string $input, array $accepted, string $lang = '', bool $html = true): array
    {
        $byBlock = [];

        foreach ($accepted as $a) {
            if (isset($a['block'], $a['quote'], $a['edit']) && \is_string($a['quote']) && \is_string($a['edit']) && $a['quote'] !== '') {
                $byBlock[(int) $a['block']][] = $a;
            }
        }

        $applied = [];
        $skipped = [];
        $block   = 0;
        $fn      = function (string $text) use (&$block, $byBlock, &$applied, &$skipped): string {
            $list = $byBlock[$block] ?? [];
            $block++;

            if (!$list) {
                return $text;
            }

            // с конца абзаца: правка не сдвигает места ещё не внесённых
            usort($list, static fn ($x, $y) => ((int) ($y['offset'] ?? 0)) <=> ((int) ($x['offset'] ?? 0)));
            $taken = [];

            foreach ($list as $a) {
                $id  = (string) ($a['id'] ?? '');
                $q   = $a['quote'];
                $len = mb_strlen($q);
                $at  = isset($a['offset']) && mb_substr($text, (int) $a['offset'], $len) === $q ? (int) $a['offset'] : null;

                if ($at === null) {
                    // текст изменился после проверки: ищем цитату, но только единственную
                    $first = mb_strpos($text, $q);
                    $at    = $first !== false && mb_strpos($text, $q, $first + 1) === false ? $first : null;
                }

                if ($at === null || self::overlaps($taken, $at, $at + $len)) {
                    $skipped[] = $id;
                    continue;
                }

                $text      = mb_substr($text, 0, $at) . $a['edit'] . mb_substr($text, $at + $len);
                $taken[]   = [$at, $at + mb_strlen($a['edit'])];
                $applied[] = $id;
            }

            return $text;
        };

        $out = $html ? (new Html())->process($input, $fn) : self::plain($input, $fn);

        return ['text' => $out, 'applied' => $applied, 'skipped' => array_merge($skipped, self::missing($byBlock, $block))];
    }

    // ------------------------------------------------------------------ абзац

    private function block(string $text, int $block, string $lang): array
    {
        $rules = [];

        if ($this->o['slop']) {
            $rules = array_merge($rules, Rules::artifacts());
        }

        if ($lang === 'ru') {
            if ($this->o['slop']) {
                $rules = array_merge($rules, Rules::phrases());

                if ($this->o['genre'] !== 'news') {
                    $rules = array_merge($rules, Rules::modal(), Rules::words());
                }
            }

            if ($this->o['kanc']) {
                $rules = array_merge($rules, Rules::kanc());
            }

            if ($this->o['rozental']) {
                $rules = array_merge($rules, Rules::rozental());
            }

            if ($this->o['yo']) {
                $rules = array_merge($rules, Rules::yo());
            }
        }

        $quotes = self::quoteSpans($text);
        $found  = [];

        foreach ($rules as [$code, $re, $edit, $layer, $weight, $flags]) {
            if (!preg_match_all($re, $text, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($mm as $m) {
                $quote = $m[0][0];

                if ($quote === '') {
                    continue;
                }

                $start = mb_strlen(substr($text, 0, $m[0][1]));
                $end   = $start + mb_strlen($quote);

                if (!\in_array('quotes', $flags, true) && self::inside($quotes, $start, $end)) {
                    continue;
                }

                if ($code === 'R-DOUBLE' && \in_array(mb_strtolower($m[1][0]), Rules::DOUBLE_OK, true)) {
                    continue;
                }

                $groups = array_map(static fn ($g) => \is_array($g) ? $g[0] : $g, $m);
                $new    = match (true) {
                    $edit === null      => null,
                    \is_callable($edit) => $edit($groups),
                    default             => preg_replace_callback('/\$(\d)/', static fn ($g) => $groups[(int) $g[1]] ?? '', $edit),
                };

                if ($new === $quote) {
                    continue;
                }

                $found[] = [
                    'id'       => 'b' . $block . '-' . $start . '-' . $code,
                    'block'    => $block,
                    'offset'   => $start,
                    'quote'    => $quote,
                    'edit'     => $new,
                    'rule'     => $code,
                    'layer'    => $layer,
                    'weight'   => $weight,
                    'auto'     => \in_array('auto', $flags, true) && $new !== null,
                    'cluster'  => \in_array('cluster', $flags, true),
                    'context'  => self::context($text, $start, $end),
                ];
            }
        }

        $found = self::dedupe($found);

        // стая: три и больше маркера в абзаце — Слопотрон велит смотреть абзац целиком, а не каждое совпадение
        $markers = array_values(array_filter($found, static fn ($f) => $f['cluster']));

        if (\count($markers) >= (int) $this->o['cluster']) {
            $found[] = [
                'id' => 'b' . $block . '-cluster', 'block' => $block, 'offset' => $markers[0]['offset'], 'quote' => $markers[0]['quote'], 'edit' => null,
                'rule' => 'S-CLUSTER', 'layer' => 'slop', 'weight' => 'warn', 'auto' => false, 'cluster' => false,
                'context' => self::context($text, 0, 0, 160), 'count' => \count($markers),
            ];
        }

        if ($this->o['rhythm'] && ($r = self::rhythm($text)) !== null) {
            $found[] = [
                'id' => 'b' . $block . '-rhythm', 'block' => $block, 'offset' => 0, 'quote' => mb_substr($text, 0, 60), 'edit' => null,
                'rule' => 'RH-MONO', 'layer' => 'chukovsky', 'weight' => 'info', 'auto' => false, 'cluster' => false,
                'context' => self::context($text, 0, 0, 160), 'count' => $r['sentences'], 'spread' => $r['spread'],
            ];
        }

        usort($found, static fn ($a, $b) => [$a['offset'], $a['rule']] <=> [$b['offset'], $b['rule']]);

        return $found;
    }

    /** Одно место — одна находка: при пересечении остаётся первая по месту, при общем начале — та, у которой есть правка. */
    private static function dedupe(array $found): array
    {
        usort($found, static fn ($a, $b) => [$a['offset'], $a['edit'] === null ? 1 : 0, -mb_strlen($a['quote'])]
            <=> [$b['offset'], $b['edit'] === null ? 1 : 0, -mb_strlen($b['quote'])]);
        $out  = [];
        $last = -1;

        foreach ($found as $f) {
            if ($f['offset'] < $last) {
                continue;
            }

            $out[] = $f;
            $last  = $f['offset'] + mb_strlen($f['quote']);
        }

        return $out;
    }

    /**
     * Однообразный ритм (Чуковский, Слопотрон): пять и больше предложений, разброс длины (в словах) меньше трёх.
     * Счёт, а не впечатление: на меньшем числе предложений разброс статистически пуст.
     */
    public static function rhythm(string $text): ?array
    {
        $sentences = preg_split('/(?<=[.!?…])[\x{20}\x{A0}]+(?=[«„"(\p{Lu}\d—–-])/u', trim($text)) ?: [];
        $lens      = array_map(static fn ($s) => preg_match_all('/[\p{L}\p{N}]+(?:[-’\'][\p{L}\p{N}]+)*/u', $s), $sentences);
        $lens      = array_values(array_filter($lens, static fn ($n) => $n > 0));
        $n         = \count($lens);

        if ($n < 5) {
            return null;
        }

        $mean   = array_sum($lens) / $n;
        $spread = sqrt(array_sum(array_map(static fn ($x) => ($x - $mean) ** 2, $lens)) / $n);

        return $spread < 3 ? ['sentences' => $n, 'spread' => round($spread, 1)] : null;
    }

    /** Участки внутри кавычек «…» (с вложенными „…“): слова говорящего правятся только при явной опечатке. */
    public static function quoteSpans(string $text): array
    {
        $spans = [];
        $stack = [];

        foreach (mb_str_split($text) as $i => $ch) {
            if ($ch === '«') {
                $stack[] = $i;
            } elseif ($ch === '»' && $stack) {
                $open = array_pop($stack);

                if (!$stack) {
                    $spans[] = [$open, $i + 1];
                }
            }
        }

        return $spans;
    }

    private static function inside(array $spans, int $s, int $e): bool
    {
        foreach ($spans as [$a, $b]) {
            if ($s >= $a && $e <= $b) {
                return true;
            }
        }

        return false;
    }

    private static function overlaps(array $taken, int $s, int $e): bool
    {
        foreach ($taken as [$a, $b]) {
            if ($s < $b && $a < $e) {
                return true;
            }
        }

        return false;
    }

    /** Отрывок вокруг находки: [до, цитата, после] — цитата выделяется на стороне интерфейса. */
    private static function context(string $text, int $s, int $e, int $around = 60): array
    {
        $from = max(0, $s - $around);
        $to   = min(mb_strlen($text), $e + $around);

        return [
            ($from > 0 ? '…' : '') . mb_substr($text, $from, $s - $from),
            mb_substr($text, $s, $e - $s),
            mb_substr($text, $e, $to - $e) . ($to < mb_strlen($text) ? '…' : ''),
        ];
    }

    /** Строка без разметки — как один абзац, с теми же краями, что у разметки: пробелы по краям в счёт мест не идут. */
    private static function plain(string $input, callable $fn): string
    {
        preg_match('/^([\s\x{A0}]*)(.*?)([\s\x{A0}]*)$/su', $input, $m);

        return $m[2] === '' ? $input : $m[1] . $fn($m[2]) . $m[3];
    }

    /** Правки для абзацев, которых в тексте уже нет. */
    private static function missing(array $byBlock, int $blocks): array
    {
        $ids = [];

        foreach ($byBlock as $b => $list) {
            if ($b >= $blocks) {
                foreach ($list as $a) {
                    $ids[] = (string) ($a['id'] ?? '');
                }
            }
        }

        return $ids;
    }
}

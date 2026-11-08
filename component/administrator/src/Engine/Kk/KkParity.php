<?php

/**
 * @package     Webmarka.Redkollegiya
 * @subpackage  com_wmarka_redkollegiya
 * @copyright   (C) 2026 Beibit Sakhanov
 * @license     GNU General Public License version 3 or later; see LICENSE
 *
 * Перенос kk_parity.py из kk-redkollegiya 0.13.1 (github.com/bsakhanov/kk-redkollegiya), слой Валиханова.
 * Совпадение с оригиналом — tests/parity/kk_compare.py.
 */

namespace Webmarka\Component\Redkollegiya\Administrator\Engine\Kk;

\defined('_JEXEC') or die;

/**
 * Сверка казахской версии с русским оригиналом по абзацам: числа (с «мың / млн / млрд», датами и месяцами), латинские
 * имена и сокращения, термины глоссария. Смысл и числа, записанные словами, — не здесь: это часть для модели.
 * PR01 — разное число абзацев (числа тогда сверяются по документу целиком); PR02 — значение изменено («15,3» → «15,8»);
 * PR03 — число потеряно; PR04 — лишнее число; PR05 — потеряно латинское имя; PR06 — нет термина из глоссария.
 */
final class KkParity
{
    private const CYR = 'А-Яа-яЁёӘәҒғҚқҢңӨөҰұҮүҺһІі';

    private const MONTHS = [['январ', 'қаңтар'], ['феврал', 'ақпан'], ['март', 'наурыз'], ['апрел', 'сәуір'], ['ма[йя]', 'мамыр'],
        ['июн', 'маусым'], ['июл', 'шілде'], ['август', 'тамыз'], ['сентябр', 'қыркүйек'], ['октябр', 'қазан'],
        ['ноябр', 'қараша'], ['декабр', 'желтоқсан']];

    private const LAT2CYR = ['A' => 'А', 'B' => 'В', 'C' => 'С', 'E' => 'Е', 'H' => 'Н', 'I' => 'І', 'K' => 'К', 'M' => 'М', 'O' => 'О', 'P' => 'Р',
        'T' => 'Т', 'X' => 'Х', 'Y' => 'У', 'a' => 'а', 'c' => 'с', 'e' => 'е', 'o' => 'о', 'p' => 'р', 'x' => 'х', 'y' => 'у', 'i' => 'і'];

    private const TWIN = ['А' => 'A', 'В' => 'B', 'С' => 'C', 'Е' => 'E', 'Н' => 'H', 'І' => 'I', 'К' => 'K', 'М' => 'M', 'О' => 'O', 'Р' => 'P',
        'Т' => 'T', 'Х' => 'X', 'У' => 'Y'];

    /**
     * @param  string[]  $ru  абзацы русского оригинала
     * @param  string[]  $kk  абзацы казахской версии
     * @return array<int, array{rule: string, para: int, lost?: string[], extra?: string[], latin?: string[], key?: string, ru?: string, kk?: string, counts?: int[]}>
     */
    public static function compare(array $ru, array $kk, array $gl = []): array
    {
        $ru  = array_values(array_filter(array_map([self::class, 'strip'], $ru), static fn ($p) => $p !== ''));
        $kk  = array_values(array_filter(array_map([self::class, 'strip'], $kk), static fn ($p) => $p !== ''));
        $out = [];

        if (\count($ru) !== \count($kk)) {
            $out[] = ['rule' => 'PR01', 'para' => 0, 'counts' => [\count($ru), \count($kk)]];
            $ru    = [implode("\n\n", $ru)];
            $kk    = [implode("\n\n", $kk)];
        }

        foreach ($ru as $i => $a) {
            $b     = $kk[$i] ?? '';
            $na    = self::counter(self::numbers($a));
            $nb    = self::counter(self::numbers($b));
            $lost  = self::minus($na, $nb);
            $extra = self::minus($nb, $na);

            if ($lost && \count($lost) === \count($extra)) {
                $out[] = ['rule' => 'PR02', 'para' => $i, 'lost' => $lost, 'extra' => $extra];
            } else {
                if ($lost) {
                    $out[] = ['rule' => 'PR03', 'para' => $i, 'lost' => $lost];
                }

                if ($extra) {
                    $out[] = ['rule' => 'PR04', 'para' => $i, 'extra' => $extra];
                }
            }

            foreach (self::terms($a, $b, $gl) as $t) {
                $out[] = ['rule' => 'PR06', 'para' => $i] + $t;
            }

            $lostL = self::minus(self::counter(self::latin($a)), self::counter(self::latin($b, true)));

            if ($lostL) {
                $out[] = ['rule' => 'PR05', 'para' => $i, 'latin' => $lostL];
            }
        }

        return $out;
    }

    /** Числа абзаца в одном виде: «1 234,5» → 1234.5, «1,5 млрд» → 1500000000, «12 желтоқсан» → 12 и m12. */
    public static function numbers(string $par): array
    {
        $par = self::prep($par);
        $res = [];

        $par = preg_replace_callback('/(?<![\d.])(\d{1,2})\.(\d{1,2})(?:\.(\d{2,4}))?(?![\d])/u', static function (array $m) use (&$res): string {
            $d  = (int) $m[1];
            $mo = (int) $m[2];

            if ($d >= 1 && $d <= 31 && $mo >= 1 && $mo <= 12) {
                $y = $m[3] ?? '';

                if ($y !== '' && \strlen($y) === 2) {
                    $y = '20' . $y;
                }

                array_push($res, (string) $d, 'm' . $mo, ...($y !== '' ? [$y] : []));

                return ' ';
            }

            return $m[0];
        }, $par) ?? $par;

        $scale = [['/^\s*(?:трлн\.?|триллион\w*)/iu', 1e12], ['/^\s*(?:млрд\.?|миллиард\w*)/iu', 1e9],
            ['/^\s*(?:млн\.?|миллион\w*)/iu', 1e6], ['/^\s*(?:тыс\.?|тысяч\w*|мың\w*)/iu', 1e3]];

        if (preg_match_all('/(?<![\w])\d[\d\x{A0}\x{202F}\x{2009}\x{2007} ]*(?:[.,]\d+)?(?![\w])|\d/u', $par, $mm, PREG_OFFSET_CAPTURE)) {
            foreach ($mm[0] as [$raw0, $o]) {
                $raw   = self::strip($raw0);
                $parts = preg_split('/[\x{A0}\x{202F}\x{2009}\x{2007} ]+/u', $raw) ?: [$raw];
                $vals  = self::grouped($parts) ? [self::norm($raw)] : array_map([self::class, 'norm'], $parts);
                $after = substr($par, $o + \strlen($raw0));

                if (\count($vals) === 1) {
                    $tail = preg_replace('/^-[а-яa-z]{1,3}(?=[\s.])/u', '', mb_substr($after, 0, 18)) ?? '';

                    foreach ($scale as [$rx, $k]) {
                        if (preg_match($rx, $tail)) {
                            if (is_numeric($vals[0])) {
                                $vals = [self::fmt((float) $vals[0] * $k)];
                            }

                            break;
                        }
                    }

                    $tail2 = preg_replace('/^[ \x{A0}\-–го]+/u', '', mb_substr($after, 0, 16)) ?? '';

                    foreach (self::MONTHS as $n => [$r, $k2]) {
                        if (preg_match('/^(?<![' . self::CYR . '])(?:' . $r . '\w*|' . $k2 . '\w*)/iu', $tail2)) {
                            $res[] = 'm' . ($n + 1);
                            break;
                        }
                    }
                }

                array_push($res, ...$vals);
            }
        }

        return array_values(array_filter($res, static fn ($x) => $x !== ''));
    }

    /** Латинские имена и сокращения; у казахской стороны и набранные кириллическими двойниками («ІРО» = IPO). */
    public static function latin(string $par, bool $twins = false): array
    {
        $toks = [];

        if (preg_match_all('/(?<![A-Za-z0-9])[A-Za-z][A-Za-z0-9.+&-]{1,}/u', $par, $m)) {
            foreach ($m[0] as $t) {
                $toks[] = rtrim(strtolower($t), '.');
            }
        }

        if ($twins && preg_match_all('/(?<![' . self::CYR . 'A-Za-z])[АВСЕНІКМОРТХУ]{2,}(?![' . self::CYR . 'A-Za-z])/u', $par, $m)) {
            foreach ($m[0] as $t) {
                $toks[] = strtolower(strtr($t, self::TWIN));
            }
        }

        return $toks;
    }

    private static function terms(string $a, string $b, array $gl): array
    {
        $out = [];
        $al  = mb_strtolower($a);
        $bl  = mb_strtolower($b);

        foreach ($gl['entries'] ?? [] as $e) {
            if (($e['status'] ?? null) !== 'usage' || empty($e['parity_ru']) || empty($e['kk_re']) || ($e['parity_check'] ?? null) === false) {
                continue;
            }

            if (preg_match('/' . str_replace('/', '\/', $e['parity_ru']) . '/u', $al)
                && !preg_match('/(?<![' . self::CYR . 'A-Za-z])(?:' . str_replace('/', '\/', $e['kk_re']) . ')/u', $bl)) {
                $out[] = ['key' => $e['key'], 'ru' => $e['ru'], 'kk' => $e['kk']];
            }
        }

        return $out;
    }

    private static function prep(string $par): string
    {
        $par = preg_replace('/\b(?:www\.|(?:bit\.ly|t\.me|goo\.gl|clck\.ru|youtu\.be)\/)\S*|\b[\w-]+(?:\.[\w-]+)*\.(?:kz|com|ru|org|net|me)\b\S*/iu', ' ', $par) ?? $par;

        return preg_replace_callback('/[' . self::CYR . 'A-Za-z]+/u', static function (array $m): string {
            $w = $m[0];

            if (preg_match('/[' . self::CYR . ']/u', $w) && preg_match('/[A-Za-z]/', $w)) {
                foreach (str_split(preg_replace('/[^A-Za-z]/', '', $w) ?? '') as $c) {
                    if (!isset(self::LAT2CYR[$c])) {
                        return $w;
                    }
                }

                return strtr($w, self::LAT2CYR);
            }

            return $w;
        }, $par) ?? $par;
    }

    /** Абзацы текста без разметки: блоки, разделённые пустой строкой. */
    public static function paragraphs(string $t): array
    {
        return array_values(array_filter(array_map([self::class, 'strip'], preg_split('/\n\s*\n/u', $t) ?: []), static fn ($p) => $p !== ''));
    }

    /** Как str.strip(): и неразрывные пробелы по краям. */
    public static function strip(string $s): string
    {
        return preg_replace('/^[\s\x{A0}\x{202F}\x{2000}-\x{200A}\x{3000}]+|[\s\x{A0}\x{202F}\x{2000}-\x{200A}\x{3000}]+$/u', '', $s) ?? $s;
    }

    private static function norm(string $s): string
    {
        return rtrim(str_replace(',', '.', str_replace(["\u{00A0}", "\u{202F}", "\u{2009}", "\u{2007}", ' '], '', $s)), '.');
    }

    /**
     * Одно число с разрядами («1 234,5»)? После первой группы — ровно по три цифры, дробь только у последней
     * (kk-redkollegiya 0.13.1: прежде «1 234,5» разваливалось на «1» и «234,5»).
     */
    private static function grouped(array $parts): bool
    {
        $last = \count($parts) - 1;

        for ($i = 1; $i <= $last; $i++) {
            if (!preg_match($i === $last ? '/^\d{3}(?:[.,]\d+)?$/u' : '/^\d{3}$/u', $parts[$i])) {
                return false;
            }
        }

        return true;
    }

    private static function fmt(float $x): string
    {
        if (abs($x - round($x)) < 1e-9) {
            return (string) (int) round($x);
        }

        // как repr(round(x, 6)) в Python: кратчайшая запись числа
        $r = round($x, 6);

        return rtrim(rtrim(sprintf('%.6F', $r), '0'), '.');
    }

    private static function counter(array $xs): array
    {
        $c = [];

        foreach ($xs as $x) {
            $c[$x] = ($c[$x] ?? 0) + 1;
        }

        return $c;
    }

    /** Разность мультимножеств, развёрнутая и отсортированная, как sorted((a - b).elements()). */
    private static function minus(array $a, array $b): array
    {
        $out = [];

        foreach ($a as $k => $n) {
            for ($i = 0; $i < $n - ($b[$k] ?? 0); $i++) {
                $out[] = (string) $k;
            }
        }

        sort($out, SORT_STRING);

        return $out;
    }
}

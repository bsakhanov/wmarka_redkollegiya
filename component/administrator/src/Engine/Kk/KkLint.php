<?php

/**
 * @package     Webmarka.Redkollegiya
 * @subpackage  com_wmarka_redkollegiya
 * @copyright   (C) 2026 Beibit Sakhanov
 * @license     GNU General Public License version 3 or later; see LICENSE
 *
 * Перенос детекторов kk_lint.py (профиль prose) из kk-redkollegiya (github.com/bsakhanov/kk-redkollegiya):
 * H01–H04 (Алтынсарин), T01–T02 (Шакарим), P01–P05, C01 (Алтынсарин, Байтурсынов).
 * Восстановление букв S01 не перенесено: ему нужна символьная модель, обученная на закрытом корпусе.
 * Совпадение с оригиналом по местам, правкам и кодам правил — tests/parity/kk_compare.py.
 */

namespace Webmarka\Component\Redkollegiya\Administrator\Engine\Kk;

\defined('_JEXEC') or die;

final class KkLint
{
    public const CYRL = 'А-Яа-яЁёӘәҒғҚқҢңӨөҰұҮүҺһІі';
    private const CTRL = '\x{00}-\x{08}\x{0B}\x{0E}-\x{1F}\x{200B}-\x{200D}\x{2060}\x{FEFF}';

    private const H02_MAP = ["\u{018F}" => 'Ә', "\u{0259}" => 'ә', 'ӓ' => 'ә', 'Ӓ' => 'Ә', 'ӧ' => 'ө', 'Ӧ' => 'Ө', 'ӱ' => 'ү', 'Ӱ' => 'Ү',
        'ӊ' => 'ң', 'Ӊ' => 'Ң', 'ҥ' => 'ң', 'Ҥ' => 'Ң', 'ɵ' => 'ө', 'Ɵ' => 'Ө', 'ҡ' => 'қ', 'Ҡ' => 'Қ'];

    private const LAT2CYR = ['A' => 'А', 'B' => 'В', 'C' => 'С', 'E' => 'Е', 'H' => 'Н', 'I' => 'І', 'K' => 'К', 'M' => 'М', 'O' => 'О', 'P' => 'Р',
        'T' => 'Т', 'X' => 'Х', 'Y' => 'У', 'a' => 'а', 'c' => 'с', 'e' => 'е', 'o' => 'о', 'p' => 'р', 'x' => 'х', 'y' => 'у', 'i' => 'і'];

    private const LOOK2LAT = ['І' => 'I', 'Х' => 'X', 'С' => 'C', 'М' => 'M', 'Т' => 'T'];

    private const ROMAN = '(?=[IVXLCDM])M{0,3}(?:CM|CD|D?C{0,3})(?:XC|XL|L?X{0,3})(?:IX|IV|V?I{0,3})';

    private const ABBR_OK = ['т.б', 'т.с.с', 'б.з.д', 'б.з', 'ж', 'жж', 'ғ', 'ғғ', 'м', 'көш', 'т', 'тел', 'мыс', 'кв', 'ш', 'обл', 'а', 'қ'];

    private const DERIV = ['лау', 'леу', 'дау', 'деу', 'тау', 'теу', 'лап', 'леп', 'дап', 'деп', 'тап', 'теп', 'ландыр', 'лендір',
        'дандыр', 'дендір', 'лық', 'лік', 'дық', 'дік', 'тық', 'тік', 'шы', 'ші', 'лы', 'лі'];

    private string $t = '';

    /** @var array<int, array{s: int, e: int, edit: ?string, rule: string, zone: string, severity: string, action: string, args: array}> */
    private array $out = [];

    /**
     * Находки по тексту (места — в знаках, не в байтах), упорядочены по месту.
     *
     * @param  array{entries?: array, abbreviations?: array}  $gl  глоссарий
     */
    public function spans(string $text, array $gl = []): array
    {
        $this->t   = $text;
        $this->out = [];

        $this->hygiene();
        $this->glossary($gl);
        $this->punct();
        $this->lowerAfterDot();
        $this->quotes();
        $this->numberAffix();
        $this->currency();

        usort($this->out, static fn ($a, $b) => [$a['s'], $a['e']] <=> [$b['s'], $b['e']]);

        return $this->out;
    }

    /** Гигиена для типографа: только надёжные правки H01–H03 и снятие невидимых знаков (H04). */
    public static function cleanse(string $text, ?callable $count = null): string
    {
        $lint = new self();
        $lint->t   = $text;
        $lint->out = [];
        $lint->hygiene();
        $fixes = array_values(array_filter($lint->out, static fn ($s) => $s['edit'] !== null && $s['action'] === 'apply'));
        usort($fixes, static fn ($a, $b) => $b['s'] <=> $a['s']);
        $last = PHP_INT_MAX;

        foreach ($fixes as $f) {
            if ($f['e'] > $last) {
                continue;
            }

            $text = mb_substr($text, 0, $f['s']) . $f['edit'] . mb_substr($text, $f['e']);
            $last = $f['s'];
            $count && $count(substr($f['rule'], 0, 3));
        }

        // H04: невидимые и управляющие знаки — kk_lint велит прогнать kk_clean, а он их убирает
        $n    = 0;
        $text = preg_replace('/[' . self::CTRL . ']/u', '', $text, -1, $n) ?? $text;

        if ($n && $count) {
            for ($i = 0; $i < $n; $i++) {
                $count('H04');
            }
        }

        return $text;
    }

    // ------------------------------------------------------------------ служебное

    private function add(int $sb, int $eb, ?string $edit, string $rule, string $zone, string $severity, string $action, array $args = []): void
    {
        $this->out[] = ['s' => $this->ch($sb), 'e' => $this->ch($eb), 'edit' => $edit, 'rule' => $rule, 'zone' => $zone,
            'severity' => $severity, 'action' => $action, 'args' => $args];
    }

    private function addChars(int $s, int $e, ?string $edit, string $rule, string $zone, string $severity, string $action, array $args = []): void
    {
        $this->out[] = ['s' => $s, 'e' => $e, 'edit' => $edit, 'rule' => $rule, 'zone' => $zone, 'severity' => $severity, 'action' => $action, 'args' => $args];
    }

    /** Место в знаках по месту в байтах. */
    private function ch(int $byte): int
    {
        return mb_strlen(substr($this->t, 0, $byte));
    }

    private function all(string $re, ?string $text = null): array
    {
        return preg_match_all($re, $text ?? $this->t, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) ? $mm : [];
    }

    private static function isUpper(string $w): bool
    {
        // как str.isupper(): есть хотя бы одна буква с регистром, и все такие буквы прописные
        return (bool) preg_match('/\p{Lu}/u', $w) && !preg_match('/\p{Ll}/u', $w);
    }

    private static function firstUpper(string $w): bool
    {
        return $w !== '' && (bool) preg_match('/^\p{Lu}/u', $w);
    }

    // ------------------------------------------------------------------ H01–H04 гигиена

    public static function isRomanOrIt(string $tok): bool
    {
        $up   = mb_strtoupper($tok);
        $norm = strtr($up, self::LOOK2LAT);

        if ($norm === 'IT') {
            return true;
        }

        $asciiOrLook = true;

        foreach (mb_str_split($up) as $c) {
            if (\ord($c[0]) >= 128 && !isset(self::LOOK2LAT[$c])) {
                $asciiOrLook = false;
                break;
            }
        }

        if (mb_strlen($norm) >= 2 && preg_match('/^(?:' . self::ROMAN . ')$/u', $norm) && $asciiOrLook) {
            return true;
        }

        if (preg_match('/^' . self::ROMAN . '/u', $tok, $m) && mb_strlen($m[0]) >= 2) {
            $rest = mb_substr($tok, mb_strlen($m[0]));

            return (bool) preg_match('/^[' . self::CYRL . ']{1,6}$/u', $rest === '' ? '-' : $rest);
        }

        return false;
    }

    /** Двойники в слове: кириллица, если в нём есть кириллица без латинского двойника, или латиница, если наоборот. */
    public static function fixHomoglyphs(string $tok): string
    {
        if (self::isRomanOrIt($tok)) {
            return $tok;
        }

        preg_match_all('/[' . self::CYRL . ']/u', $tok, $cy);
        preg_match_all('/[A-Za-z]/u', $tok, $la);
        $cy = $cy[0];
        $la = $la[0];

        if (!$cy || !$la) {
            return $tok;
        }

        $twins  = array_flip(array_values(self::LAT2CYR));
        $cyNon  = (bool) array_filter($cy, static fn ($c) => !isset($twins[$c]));
        $laNon  = (bool) array_filter($la, static fn ($c) => !isset(self::LAT2CYR[$c]));

        if ($cyNon && $laNon) {
            return $tok;
        }

        if ($cyNon || (!$laNon && \count($cy) >= \count($la))) {
            return strtr($tok, self::LAT2CYR);
        }

        return strtr($tok, array_flip(self::LAT2CYR));
    }

    private function hygiene(): void
    {
        foreach ($this->all('/[' . self::CYRL . 'A-Za-z]+/u') as $m) {
            [$tok, $o] = $m[0];

            if (self::isRomanOrIt($tok)) {
                continue;
            }

            if (preg_match('/[' . self::CYRL . ']/u', $tok) && preg_match('/[A-Za-z]/', $tok)) {
                $res = self::fixHomoglyphs($tok);

                if ($res !== $tok) {
                    $this->add($o, $o + \strlen($tok), $res, 'H01', 'hygiene', 'fix', 'apply');
                } else {
                    $this->add($o, $o + \strlen($tok), null, 'H01', 'hygiene', 'author_choice', 'flag');
                }
            }
        }

        $h02   = implode('', array_keys(self::H02_MAP));
        $chars = mb_str_split($this->t);
        $n     = \count($chars);
        $tokRe = '/^[' . self::CYRL . $h02 . ']$/u';
        $recent = [];

        foreach ($this->all('/[' . $h02 . ']/u') as $m) {
            $s = $this->ch($m[0][1]);
            $e = $s + 1;

            while ($s > 0 && preg_match($tokRe, $chars[$s - 1])) {
                $s--;
            }

            while ($e < $n && preg_match($tokRe, $chars[$e])) {
                $e++;
            }

            if (\in_array($s, $recent, true)) {
                continue;
            }

            $tok = implode('', \array_slice($chars, $s, $e - $s));
            $this->addChars($s, $e, strtr($tok, self::H02_MAP), 'H02', 'hygiene', 'fix', 'apply');
            $recent = \array_slice([...$recent, $s], -3);
        }

        if (class_exists(\Normalizer::class)) {
            foreach ($this->all('/[' . self::CYRL . '][\x{0300}-\x{036F}]+/u') as $m) {
                $nfc = \Normalizer::normalize($m[0][0], \Normalizer::FORM_C);

                if (\is_string($nfc) && $nfc !== $m[0][0]) {
                    $this->add($m[0][1], $m[0][1] + \strlen($m[0][0]), $nfc, 'H03', 'hygiene', 'fix', 'apply');
                }
            }
        }

        foreach ($this->all('/[' . self::CTRL . ']/u') as $m) {
            $s = $this->ch($m[0][1]);
            $this->addChars(max(0, $s - 1), $s + 2, null, 'H04', 'hygiene', 'fix', 'flag');
        }
    }

    // ------------------------------------------------------------------ T01–T02 термины

    private function glossary(array $gl): void
    {
        $L = '[' . self::CYRL . 'A-Za-z]';

        foreach ($gl['abbreviations'] ?? [] as $ab) {
            foreach ($this->all('/(?<!' . $L . ')(' . preg_quote($ab['ru'], '/') . ')(?!' . $L . ')/u') as $m) {
                $end    = $m[0][1] + \strlen($m[0][0]);
                $suffix = substr($this->t, $end, 1) === '-';
                $this->add($m[0][1], $end, $ab['kk'], 'T01', 'terms', $suffix ? 'author_choice' : 'fix', $suffix ? 'suggest' : 'apply',
                    ['kk' => $ab['kk'], 'ru' => $ab['ru'], 'affix' => $suffix]);
            }
        }

        $abbrRu = array_column($gl['abbreviations'] ?? [], 'ru');

        foreach ($gl['entries'] ?? [] as $e) {
            if (($e['status'] ?? null) === 'contested') {
                continue;
            }

            $mode = $e['avoid_action'] ?? 'apply';
            $ev   = $e['evidence'] ?? [];
            $why  = !empty($ev['a']) && !empty($ev['b']) ? [$ev['b']['form'], (int) $ev['b']['count'], $ev['a']['form'], (int) $ev['a']['count']] : null;
            $ex   = [];

            foreach ($e['avoid_exclude'] ?? [] as $x) {
                foreach ($this->all('/' . str_replace('/', '\/', $x) . '/iu') as $m) {
                    $ex[] = [$this->ch($m[0][1]), $this->ch($m[0][1] + \strlen($m[0][0]))];
                }
            }

            foreach ($e['avoid'] ?? [] as $bad) {
                if (\in_array($bad, $abbrRu, true)) {
                    continue;
                }

                $ru0  = mb_strtolower((string) ($e['ru'] ?? ''));
                $loan = (bool) preg_match('/[цщъьфвчэёюя]/u', mb_strtolower($bad))
                    || (mb_strlen($ru0) >= 5 && mb_substr(mb_strtolower($bad), 0, 5) === mb_substr($ru0, 0, 5));

                foreach ($this->all('/(?<!' . $L . ')(' . preg_quote($bad, '/') . ')(' . $L . '*)/iu') as $m) {
                    $s = $this->ch($m[0][1]);
                    $e2 = $s + mb_strlen($m[0][0]);

                    if (array_filter($ex, static fn ($r) => $r[0] <= $s && $s < $r[1]) || $this->inName($s, $e2)) {
                        continue;
                    }

                    $cont = $m[2][0];

                    if ($cont !== '' && !self::sameWord($bad, $cont, $loan)) {
                        continue;
                    }

                    if (self::firstUpper($m[1][0]) && !self::firstUpper($bad) && !$this->sentStart($s)) {
                        continue;
                    }

                    $kk = $e['kk'] ?? null;

                    if (!$kk) {
                        $this->addChars($s, $e2, null, 'T02', 'terms', 'fix', 'flag', ['bad' => $bad, 'variant' => 'banned']);
                    } elseif ($cont !== '') {
                        $this->addChars($s, $e2, null, 'T02', 'terms', 'author_choice', 'flag', ['bad' => $bad, 'kk' => $kk, 'why' => $why, 'variant' => 'affix']);
                    } else {
                        $rep = self::firstUpper($m[1][0]) ? mb_strtoupper(mb_substr($kk, 0, 1)) . mb_substr($kk, 1) : $kk;

                        if ($mode === 'suggest') {
                            $this->addChars($s, $e2, $rep, 'T02', 'terms', 'author_choice', 'suggest', ['bad' => $bad, 'kk' => $kk, 'why' => $why, 'variant' => 'usage']);
                        } else {
                            $this->addChars($s, $e2, $rep, 'T02', 'terms', 'fix', 'apply', ['bad' => $bad, 'kk' => $kk, 'variant' => 'glossary']);
                        }
                    }
                }
            }
        }
    }

    private function inName(int $s, int $e): bool
    {
        $before = mb_substr($this->t, max(0, $s - 40), $s - max(0, $s - 40));

        if (preg_match('/([' . self::CYRL . 'A-Za-z]+)[ \t]+$/u', $before, $pre)) {
            $w = $pre[1];

            if ((mb_strlen($w) >= 2 && self::isUpper($w)) || (self::firstUpper($w) && self::firstUpper(mb_substr($this->t, $s, 1)))) {
                return true;
            }
        }

        $from = max(0, $s - 80);
        $q    = mb_strrpos(mb_substr($this->t, $from, $s - $from), '«');

        if ($q !== false) {
            $q += $from;

            if (!str_contains(mb_substr($this->t, $q, $s - $q), '»')) {
                $r     = mb_strpos(mb_substr($this->t, $e, 80), '»');
                $r     = $r === false ? -1 : $r + $e;
                $inner = mb_substr($this->t, $q + 1, ($r !== -1 ? $r : $e) - $q - 1);
                preg_match_all('/[' . self::CYRL . 'A-Za-z]+/u', $inner, $ws);

                if (\count(array_filter($ws[0], [self::class, 'firstUpper'])) >= 2) {
                    return true;
                }
            }
        }

        return false;
    }

    private function sentStart(int $s): bool
    {
        $i = $s - 1;

        while ($i >= 0 && str_contains(" \t«\"“([—-", mb_substr($this->t, $i, 1))) {
            $i--;
        }

        return $i < 0 || str_contains("\n.!?…", mb_substr($this->t, $i, 1));
    }

    private static function sameWord(string $bad, string $cont, bool $loan): bool
    {
        $b = mb_strtolower($bad);
        $c = mb_strtolower($cont);

        if (KkStem::validEnding($b, $c, $loan)) {
            return true;
        }

        if ($loan) {
            foreach (self::DERIV as $dv) {
                if (str_starts_with($c, $dv) && ($c === $dv || KkStem::validEnding($b . $dv, substr($c, \strlen($dv)), true))) {
                    return true;
                }
            }
        }

        return false;
    }

    // ------------------------------------------------------------------ P01–P05, C01

    private function punct(): void
    {
        foreach ([
            ['/(?<=\S)[ \t]+(?=[,;:!?])/u', ''],
            ['/(?<=[^\s.])[ \t]+(?=\.(?!\.))/u', ''],
            ['/,,+/u', ','],
            ['/;;+/u', ';'],
            ['/::+/u', ':'],
            ['/(?<![.\d])\.\.(?!\.)/u', '.'],
            ['/(?<=\S)[ \t]{2,}(?=\S)/u', ' '],
        ] as $i => [$re, $rep]) {
            foreach ($this->all($re) as $m) {
                $this->add($m[0][1], $m[0][1] + \strlen($m[0][0]), $rep, 'P01', 'typography', 'fix', 'apply', ['kind' => $i]);
            }
        }

        foreach ($this->all('/[.,]{1}[.,]{1}(?![.])|:\.|\.:|;\.|,\./u') as $m) {
            [$x, $o] = $m[0];

            if ($x === ',,' || $x === '..') {
                continue;
            }

            if ($x[0] === '.') {
                $w = null;

                if (preg_match('/([' . self::CYRL . 'A-Za-z]+)$/u', mb_substr(substr($this->t, 0, $o), -12), $wm)) {
                    $w = $wm[1];
                }

                if ($w !== null && (mb_strlen($w) <= 3 || \in_array(mb_strtolower($w), self::ABBR_OK, true))) {
                    continue;
                }
            }

            $this->add($o, $o + \strlen($x), null, 'P01', 'typography', 'author_choice', 'flag', ['kind' => 'junction']);
        }

        foreach ($this->all('/\(\s*\)|«\s*»|\[\s*\]/u') as $m) {
            $this->add($m[0][1], $m[0][1] + \strlen($m[0][0]), null, 'P04', 'typography', 'fix', 'flag');
        }
    }

    private function lowerAfterDot(): void
    {
        foreach ($this->all('/(\S+)\.[ \t]+(?=[а-яәғқңөұүһі])/u') as $m) {
            // как str.strip("«»\"“”()[]") — по знакам, а не по байтам: trim() порвал бы кириллицу
            $word = preg_replace('/^[«»"“”()\[\]]+|[«»"“”()\[\]]+$/u', '', mb_strtolower($m[1][0])) ?? '';

            if (\in_array($word, self::ABBR_OK, true) || mb_strlen($word) <= 1 || preg_match('/\d/u', $word) || str_contains($word, '.')) {
                continue;
            }

            $this->add($m[0][1], $m[0][1] + \strlen($m[0][0]), null, 'P02', 'norm', 'author_choice', 'flag');
        }
    }

    private function quotes(): void
    {
        $pos = 0;

        foreach (preg_split('/(\n\s*\n)/u', $this->t, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $para) {
            $len = mb_strlen($para);

            if (trim($para) !== '' && !preg_match('/^\n\s*\n$/u', $para)) {
                $open  = mb_substr_count($para, '«');
                $close = mb_substr_count($para, '»');

                if ($open !== $close) {
                    $this->addChars($pos, min($pos + 60, $pos + $len), null, 'P03', 'typography', 'fix', 'flag', ['open' => $open, 'close' => $close]);
                }

                $i = mb_strpos($para, '"');

                if ($i !== false) {
                    $this->addChars($pos + max(0, $i - 15), $pos + min($len, $i + 25), null, 'P03', 'typography', 'fix', 'flag', ['straight' => true]);
                }
            }

            $pos += $len;
        }
    }

    private function numberAffix(): void
    {
        $c  = self::CYRL;
        $re = '/(?<![\w' . $c . '])(\d+)(ыншы|інші|ға|ге|қа|ке|ны|ні|ды|ді|ты|ті|да|де|та|те|дан|ден|тан|тен|нан|нен|'
            . 'мен|бен|пен|ның|нің|дың|дің|тың|тің|мыз|міз|шы|ші|дағы|дегі|тағы|тегі)(?![' . $c . '])/u';

        foreach ($this->all($re) as $m) {
            $this->add($m[0][1], $m[0][1] + \strlen($m[0][0]), $m[1][0] . '-' . $m[2][0], 'P05', 'norm', 'author_choice', 'suggest');
        }
    }

    private function currency(): void
    {
        $sym  = preg_match('/₸|\$|€/u', $this->t, $a, PREG_OFFSET_CAPTURE) ? $a[0][1] : null;
        $word = preg_match('/(?<![А-Яа-яӘәҒғҚқҢңӨөҰұҮүҺһІі])(теңге|тг|доллар\w*|USD|евро)(?![А-Яа-яӘәҒғҚқҢңӨөҰұҮүҺһІі])/u', $this->t, $b, PREG_OFFSET_CAPTURE) ? $b[0][1] : null;

        if ($sym !== null && $word !== null) {
            $s = $this->ch(max($sym, $word));
            $this->addChars(max(0, $s - 20), min(mb_strlen($this->t), $s + 30), null, 'C01', 'norm', 'author_choice', 'flag');
        }
    }
}

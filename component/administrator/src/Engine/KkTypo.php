<?php

/**
 * @package     Webmarka.Redkollegiya
 * @subpackage  com_wmarka_redkollegiya
 * @copyright   (C) 2026 Beibit Sakhanov
 * @license     GNU General Public License version 3 or later; see LICENSE
 *
 * Перенос на PHP типографа kk_typo из kk-redkollegiya (github.com/bsakhanov/kk-redkollegiya, слой Алтынсарина).
 * Правила Y01–Y14, их порядок и разрешение пересечений те же, что в kk_typo.py; совпадение проверяет tests/parity.
 */

namespace Webmarka\Component\Redkollegiya\Administrator\Engine;

\defined('_JEXEC') or die;

/**
 * Казахский типограф. Каждое правило отдаёт правки по месту в исходном тексте (начало, конец, замена);
 * пересекающиеся правки не вносятся дважды: остаётся первая по месту, а при общем начале — частное правило раньше общей чистки пробелов.
 */
final class KkTypo
{
    private const NB  = "\u{00A0}";
    private const CYR = 'А-Яа-яЁёӘәҒғҚқҢңӨөҰұҮүҺһІі';
    private const KU  = 'А-ЯӘҒҚҢӨҰҮҺІ';
    private const KL  = 'а-яәғқңөұүһі';

    private const UNITS = ['млрд', 'млн', 'трлн', 'мың', 'тонна', 'баррель', 'кг', 'км', 'см', 'мм', 'га', 'МВт', 'кВт', 'ГВт', 'Вт',
        'теңге', 'тг', 'доллар', 'евро', 'сағат', 'минут', 'секунд', 'пайыз', 'м', 'л'];

    private const ABBR = ['т.б.', 'т.с.с.', 'б.з.д.', 'б.з.'];

    private const PROTECT = [
        '/\A---\n.*?\n---[ \t]*(?:\n|\z)/su',
        '/^(```|~~~).*?^\1[^\n]*$/msu',
        '/`[^`\n]+`/u',
        '~https?://[^\s<>)\]]+|www\.[^\s<>)\]]+|[\w.+-]+@[\w-]+\.[\w.-]+~u',
        '/\]\([^)\s]*\)/u',
        '/<[^>\n]+>/u',
        '/(?<![\w])[@#][\w.-]+/u',
        '/^[ \t]*\|?[ \t]*:?-{2,}:?[ \t]*(?:\|[ \t]*:?-{2,}:?[ \t]*)*\|?[ \t]*$/mu',
    ];

    private string $t = '';

    /** @var array<int, array{0: int, 1: int}> защищённые участки (байты), по началу */
    private array $blocked = [];

    /** @var int[] */
    private array $bstarts = [];

    /** @var array<int, array{s: int, e: int, edit: string, rule: string, n: int}> */
    private array $spans = [];

    /**
     * @param  array{dash?: string, percent_space?: bool, numero_space?: bool, degree_space?: bool}  $opts
     */
    public function fix(string $text, array $opts, Counter $c): string
    {
        $this->t       = $text;
        $this->spans   = [];
        $this->blocked = [];

        foreach (self::PROTECT as $re) {
            if (preg_match_all($re, $text, $mm, PREG_OFFSET_CAPTURE)) {
                foreach ($mm[0] as [$s, $o]) {
                    $this->blocked[] = [$o, $o + \strlen($s)];
                }
            }
        }

        sort($this->blocked);
        $this->bstarts = array_column($this->blocked, 0);

        $this->quotes();
        $this->dash($opts['dash'] ?? 'em');
        $this->range();
        $this->units();
        $this->abbr();
        $this->ellipsis();
        $this->mln();
        $this->initials();
        $this->thousands();
        $this->percent(!empty($opts['percent_space']));
        $this->numero(!empty($opts['numero_space']));
        $this->degree(!empty($opts['degree_space']));
        $this->spaces();

        // по месту; при общем начале чистка пробелов (Y08) позже, длинная правка раньше короткой
        usort($this->spans, static fn ($a, $b) => [$a['s'], str_starts_with($a['rule'], 'Y08') ? 1 : 0, -$a['n']]
            <=> [$b['s'], str_starts_with($b['rule'], 'Y08') ? 1 : 0, -$b['n']]);

        $out  = '';
        $pos  = 0;
        $last = -1;

        foreach ($this->spans as $sp) {
            if ($sp['s'] < $last) {
                continue;
            }

            $out .= substr($text, $pos, $sp['s'] - $pos) . $sp['edit'];
            $pos  = $sp['e'];
            $last = $sp['e'];
            $c->add($sp['rule']);
        }

        return $out . substr($text, $pos);
    }

    // ------------------------------------------------------------------ служебное

    private function ok(int $s, int $e): bool
    {
        // как kk_lint.Doc.ok: смотрим четыре защищённых участка, начавшихся до конца правки
        $j = $this->bisectLeft($this->bstarts, $e);

        for ($k = max(0, $j - 4); $k < $j; $k++) {
            [$a, $b] = $this->blocked[$k];

            if ($a < $e && $s < $b) {
                return false;
            }
        }

        return true;
    }

    private function bisectLeft(array $a, int $x): int
    {
        $lo = 0;
        $hi = \count($a);

        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);

            if ($a[$mid] < $x) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }

        return $lo;
    }

    private function add(int $s, int $e, string $edit, string $rule): void
    {
        if (substr($this->t, $s, $e - $s) !== $edit && $this->ok($s, $e)) {
            $this->spans[] = ['s' => $s, 'e' => $e, 'edit' => $edit, 'rule' => $rule, 'n' => mb_strlen(substr($this->t, $s, $e - $s))];
        }
    }

    /** @return array<int, array<int, array{0: string, 1: int}>> */
    private function all(string $re): array
    {
        return preg_match_all($re, $this->t, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) ? $mm : [];
    }

    private static function isDigit(string $ch): bool
    {
        return $ch !== '' && (bool) preg_match('/^\d$/u', $ch);
    }

    private static function isSpace(string $ch): bool
    {
        return $ch !== '' && (bool) preg_match('/^\s$/u', $ch);
    }

    private function charBefore(int $off): string
    {
        return $off > 0 ? mb_substr(substr($this->t, 0, $off), -1) : '';
    }

    // ------------------------------------------------------------------ Y01 кавычки

    private function quotes(): void
    {
        $openCtx = array_flip(mb_str_split(" \t" . self::NB . '([{—–-:;,«„'));
        $pos     = 0;

        foreach (explode("\n", $this->t) as $line) {
            $ls   = $pos;
            $pos += \strlen($line) + 1;

            if (!str_contains($line, '"') && !str_contains($line, '“') && !str_contains($line, '”')) {
                continue;
            }

            $nested = str_contains($line, '„');
            $chars  = mb_str_split($line);
            $offs   = [];
            $o      = 0;

            foreach ($chars as $i => $ch) {
                $offs[$i] = $o;
                $o       += \strlen($ch);
            }

            $toks = [];
            $bad  = false;

            foreach ($chars as $i => $ch) {
                if (!\in_array($ch, ['"', '“', '”', '«', '»', '„'], true)) {
                    continue;
                }

                $a = $ls + $offs[$i];

                if (!$this->ok($a, $a + \strlen($ch))) {
                    continue;
                }

                $prev = $i ? $chars[$i - 1] : ' ';
                $nxt  = $chars[$i + 1] ?? ' ';

                if ($ch === '«' || $ch === '„') {
                    $toks[] = [$a, $ch, 'open', false];
                } elseif ($ch === '»') {
                    $toks[] = [$a, $ch, 'close', false];
                } elseif ($ch === '“' && $nested) {
                    $toks[] = [$a, $ch, 'close', false];
                } elseif ($ch === '”' && $nested) {
                    $bad = true;
                } elseif ($ch === '“') {
                    $toks[] = [$a, $ch, 'open', true];
                } elseif ($ch === '”') {
                    $toks[] = [$a, $ch, 'close', true];
                } else {
                    if (self::isDigit($prev) && (self::isSpace($nxt) || str_contains(',.;:)', $nxt))) {
                        continue;
                    }

                    $open  = $i === 0 || isset($openCtx[$prev]);
                    $close = !self::isSpace($prev) && (self::isSpace($nxt) || str_contains('.,;:!?)]}»—–-…', $nxt));

                    if ($open && !self::isSpace($nxt)) {
                        $toks[] = [$a, $ch, 'open', true];
                    } elseif ($close) {
                        $toks[] = [$a, $ch, 'close', true];
                    } else {
                        $bad = true;
                    }
                }
            }

            if ($bad || !$toks) {
                continue;
            }

            $depth = 0;
            $plan  = [];

            foreach ($toks as [$a, $ch, $role, $rep]) {
                if ($role === 'open') {
                    $new = $depth === 0 ? '«' : '„';
                    $depth++;
                } else {
                    $depth--;
                    $new = $depth === 0 ? '»' : '“';

                    if ($depth < 0) {
                        break;
                    }
                }

                $plan[] = [$a, $ch, $new, $rep];
            }

            if ($depth !== 0) {
                continue;
            }

            foreach ($plan as [$a, $ch, $new, $rep]) {
                if ($rep) {
                    $this->add($a, $a + \strlen($ch), $new, 'Y01');
                }
            }
        }
    }

    // ------------------------------------------------------------------ Y02, Y03 тире

    private function dash(string $mode): void
    {
        $cyr = self::CYR;
        $nb  = self::NB;

        if ($mode === 'em') {
            $re = '/(?<=[' . $cyr . 'A-Za-z»)"”%\d])([ ' . $nb . ']+)(---|--|-|–)(?=[ ' . $nb . ']+[' . $cyr . 'A-Za-z«("“„\d])/u';

            foreach ($this->all($re) as $m) {
                $start = $m[0][1];
                $end   = $start + \strlen($m[0][0]);
                $left  = $this->charBefore($start);
                $right = mb_substr(preg_replace('/^[ \x{A0}]+/u', '', substr($this->t, $end)) ?? '', 0, 1);

                if ($m[2][0] === '-' && self::isDigit($left)) {
                    continue;
                }

                if ($m[2][0] === '–' && self::isDigit($left) && self::isDigit($right)) {
                    continue;
                }

                $this->add($start, $end, $nb . '—', 'Y02');
            }
        }

        foreach ($this->all('/(?<=\S)[ ]+(?=—[ ' . $nb . '])/u') as $m) {
            $this->add($m[0][1], $m[0][1] + \strlen($m[0][0]), $nb, 'Y03');
        }
    }

    // ------------------------------------------------------------------ Y04 диапазон

    private function range(): void
    {
        foreach ($this->all('~(?<![\w№.,:/\-–—])(\d{1,4})([-—])(\d{1,4})(?![\w.,:/\-–—])~u') as $m) {
            $a = $m[1][0];
            $b = $m[3][0];

            if (\strlen($a) === 4 && \strlen($b) === 2 && (int) $b >= 1 && (int) $b <= 12) {
                continue;
            }

            $this->add($m[2][1], $m[2][1] + \strlen($m[2][0]), '–', 'Y04');
        }
    }

    // ------------------------------------------------------------------ Y05 единицы

    private function units(): void
    {
        $cyr   = self::CYR;
        $units = implode('|', self::UNITS);

        foreach ($this->all('/(?<=\d)[ ]+(?=(?:' . $units . ')(?![' . $cyr . 'A-Za-z])|жыл|ж\.)/u') as $m) {
            $this->add($m[0][1], $m[0][1] + \strlen($m[0][0]), self::NB, 'Y05');
        }

        foreach ($this->all('/\b(?:млрд|млн|трлн|мың)\.?([ ]+)(?=(?:теңге|тг|доллар|евро|тонна|баррель)(?![' . $cyr . 'A-Za-z]))/u') as $m) {
            $this->add($m[1][1], $m[1][1] + \strlen($m[1][0]), self::NB, 'Y05');
        }
    }

    // ------------------------------------------------------------------ Y06 сокращения

    private function abbr(): void
    {
        foreach (self::ABBR as $canon) {
            $letters = explode('.', rtrim($canon, '.'));
            $body    = implode('\.[ ' . self::NB . ']*', $letters) . '\.';
            $tail    = $canon === 'б.з.' ? '(?![ ' . self::NB . ']*[' . self::KL . ']\.)' : '';

            foreach ($this->all('/(?<![' . self::CYR . '])' . $body . $tail . '/u') as $m) {
                $this->add($m[0][1], $m[0][1] + \strlen($m[0][0]), $canon, 'Y06');
            }
        }
    }

    // ------------------------------------------------------------------ Y07, Y09

    private function ellipsis(): void
    {
        foreach ($this->all('/(?<!\.)\.\.\.(?!\.)/u') as $m) {
            $this->add($m[0][1], $m[0][1] + 3, '…', 'Y07');
        }
    }

    private function mln(): void
    {
        foreach ($this->all('/\b(?:млн|млрд|трлн)(\.)(?= [' . self::KL . '])/u') as $m) {
            $this->add($m[1][1], $m[1][1] + 1, '', 'Y09');
        }
    }

    // ------------------------------------------------------------------ Y10 инициалы, Y11 разряды

    private function initials(): void
    {
        $ku = self::KU;
        $kl = self::KL;

        foreach ($this->all('/(?<![' . $ku . $kl . '])[' . $ku . ']\.([ ' . self::NB . ']+)(?=[' . $ku . ']\.)/u') as $m) {
            $this->add($m[1][1], $m[1][1] + \strlen($m[1][0]), '', 'Y10');
        }

        foreach ($this->all('/(?<![' . $ku . $kl . '])(?:[' . $ku . ']\.)+( +)(?=[' . $ku . '][' . $kl . ']{2,})/u') as $m) {
            $this->add($m[1][1], $m[1][1] + \strlen($m[1][0]), self::NB, 'Y10');
        }
    }

    private function thousands(): void
    {
        foreach ($this->all('/(?<![\d.,\w])\d{1,3}(?:[ ]+\d{3})+(?![\d]|[.,]\d)/u') as $m) {
            if (preg_match_all('/[ ]+/u', $m[0][0], $sp, PREG_OFFSET_CAPTURE)) {
                foreach ($sp[0] as [$s, $o]) {
                    $this->add($m[0][1] + $o, $m[0][1] + $o + \strlen($s), self::NB, 'Y11');
                }
            }
        }
    }

    // ------------------------------------------------------------------ Y12, Y13, Y14

    private function percent(bool $space): void
    {
        $re = $space ? '/(?<=\d)(?:[ ]|)%/u' : '/(?<=\d)[ ' . self::NB . ']+%/u';

        foreach ($this->all($re) as $m) {
            $this->add($m[0][1], $m[0][1] + \strlen($m[0][0]), $space ? self::NB . '%' : '%', 'Y12');
        }
    }

    private function numero(bool $space): void
    {
        $re = $space ? '/№[ ' . self::NB . ']*(?=\d)/u' : '/№[ ' . self::NB . ']+(?=\d)/u';

        foreach ($this->all($re) as $m) {
            $this->add($m[0][1], $m[0][1] + \strlen($m[0][0]), $space ? '№' . self::NB : '№', 'Y13');
        }
    }

    private function degree(bool $space): void
    {
        foreach ($this->all('/(?<=\d)[ ' . self::NB . ']*[º˚](?=[CF])/u') as $m) {
            $this->add($m[0][1], $m[0][1] + \strlen($m[0][0]), $space ? self::NB . '°' : '°', 'Y14');
        }

        $re = $space ? '/(?<=\d)[ ]?(?=°[CF])/u' : '/(?<=\d)[ ' . self::NB . ']+(?=°[CF])/u';

        foreach ($this->all($re) as $m) {
            $this->add($m[0][1], $m[0][1] + \strlen($m[0][0]), $space ? self::NB : '', 'Y14');
        }
    }

    // ------------------------------------------------------------------ Y08 пробелы (последним)

    private function spaces(): void
    {
        $cyr = self::CYR;
        $nxt = '[' . $cyr . 'A-Za-z«("“„]';
        $let = '[' . $cyr . 'A-Za-z]';

        foreach ($this->all('/(?<=\S)[ \t]+([,;!?])(?=' . $nxt . ')/u') as $m) {
            $this->add($m[0][1], $m[0][1] + \strlen($m[0][0]), $m[1][0] . ' ', 'Y08');
        }

        foreach ($this->all('/(?<=\S)[ \t]+(?=[,;:!?](?!' . $nxt . '))/u') as $m) {
            $this->add($m[0][1], $m[0][1] + \strlen($m[0][0]), '', 'Y08');
        }

        foreach ($this->all('/(?<=[^\s.])[ \t]+(?=\.(?:[ \t]|\n|$))/u') as $m) {
            $this->add($m[0][1], $m[0][1] + \strlen($m[0][0]), '', 'Y08');
        }

        foreach ($this->all('/(?<=[(«„])[ \t]+(?=\S)/u') as $m) {
            $this->add($m[0][1], $m[0][1] + \strlen($m[0][0]), '', 'Y08');
        }

        foreach ($this->all('/(?<=\S)[ \t]+(?=[)»])/u') as $m) {
            $this->add($m[0][1], $m[0][1] + \strlen($m[0][0]), '', 'Y08');
        }

        foreach ($this->all('/(?<=' . $let . ')([,;!?])(?=[' . $cyr . 'A-Za-z«("“„])/u') as $m) {
            $this->add($m[0][1], $m[0][1] + \strlen($m[0][0]), $m[1][0] . ' ', 'Y08');
        }

        foreach ($this->all('/(?<=\S)[ ]{2,}(?=\S)/u') as $m) {
            $this->add($m[0][1], $m[0][1] + \strlen($m[0][0]), ' ', 'Y08');
        }
    }
}

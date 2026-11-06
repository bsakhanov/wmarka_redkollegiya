<?php

/**
 * @package     Webmarka.Redkollegiya
 * @subpackage  com_wmarka_redkollegiya
 * @copyright   (C) 2026 Beibit Sakhanov
 * @license     GNU General Public License version 3 or later; see LICENSE
 *
 * Перенос на PHP типографа «Мильчин» (github.com/beaverbeard/milchin, лицензия MIT; уведомление — в NOTICE).
 * Правила, их коды и порядок те же, что в milchin.py; совпадение с ним проверяет tests/parity.
 */

namespace Webmarka\Component\Redkollegiya\Administrator\Engine;

\defined('_JEXEC') or die;

/**
 * Русский типограф: только детерминированные правила, где верный ответ не зависит от смысла фразы.
 * Минус, разряды, десятичная запятая, буква «ё» здесь не делаются: это домашние правила издания (House) и словарь (Yo).
 */
final class Milchin
{
    public const NBSP     = "\u{00A0}";
    public const EMDASH   = "\u{2014}";
    public const ENDASH   = "\u{2013}";
    public const ELLIPSIS = "\u{2026}";
    public const LAQUO    = "\u{00AB}";
    public const RAQUO    = "\u{00BB}";
    public const LDQUO    = "\u{201E}";
    public const RDQUO    = "\u{201C}";
    private const MULSIGN = "\u{00B7}";
    private const APOS    = "\u{2019}";
    private const DEGREE  = "\u{00B0}";
    private const BOM     = "\u{FEFF}";
    private const PH_OPEN  = "\u{E000}";
    private const PH_CLOSE = "\u{E001}";

    private const ONE_LETTER = 'аиовкосуяАИОВКОСУЯ';

    private const UNIT_TOKENS = ['кг', 'г', 'км', 'см', 'мм', 'м', 'т', 'л', 'мл', 'ч', 'мин', 'с', 'млн', 'млрд', 'трлн', 'тыс', '₽', '$', '€', '%', "\u{00B0}", '№', '§'];

    private const LAT_TO_CYR = [
        'a' => 'а', 'c' => 'с', 'e' => 'е', 'o' => 'о', 'p' => 'р', 'x' => 'х', 'y' => 'у',
        'A' => 'А', 'B' => 'В', 'C' => 'С', 'E' => 'Е', 'H' => 'Н', 'K' => 'К', 'M' => 'М', 'O' => 'О', 'P' => 'Р', 'T' => 'Т', 'X' => 'Х', 'Y' => 'У',
    ];

    private const ZERO_WIDTH = ["\u{200B}", "\u{200C}", "\u{2060}", "\u{00AD}", "\u{200E}", "\u{200F}", "\u{2061}", "\u{2062}", "\u{2063}", "\u{2064}"];

    private const NARROW_SPACES = ["\u{202F}" => "\u{00A0}", "\u{2009}" => ' ', "\u{2007}" => ' '];

    private const MOJIBAKE = [
        ["\u{00E2}\u{20AC}\u{201D}", "\u{2014}"],
        ["\u{00E2}\u{20AC}\u{201C}", "\u{2014}"],
        ["\u{00E2}\u{20AC}\u{2122}", "\u{2019}"],
        ["\u{00E2}\u{20AC}\u{0153}", "\u{00AB}"],
        ["\u{00E2}\u{20AC}\u{00A6}", "\u{2026}"],
        ["\u{00C2}\u{00A0}", "\u{00A0}"],
        ["\u{00C2} ", "\u{00A0}"],
    ];

    private const UNITS_MULSIGN = [
        'м', 'см', 'мм', 'км', 'дм', 'мкм', 'нм', 'г', 'кг', 'мг', 'т', 'ц', 'с', 'мс', 'мкс', 'нс', 'ч', 'мин',
        'А', 'мА', 'кА', 'В', 'мВ', 'кВ', 'Вт', 'кВт', 'МВт', 'мВт', 'Дж', 'кДж', 'Н', 'Па', 'кПа', 'МПа', 'Гц', 'кГц', 'МГц', 'ГГц',
        'Ом', 'кОм', 'МОм', 'Кл', 'Тл', 'Вб', 'Ф', 'Гн', 'См', 'моль', 'кд', 'лм', 'лк', 'л', 'мл', 'рад', 'ср', 'К',
        'N', 'm', 'kg', 's', 'A', 'V', 'W', 'kW', 'J', 'Pa', 'Hz', 'Ohm',
    ];

    private const MONTHS_GEN = 'января|февраля|марта|апреля|мая|июня|июля|августа|сентября|октября|ноября|декабря';
    private const ROMAN      = '[IVXLC]+';

    private const ORDINAL_SHORT = [
        'ый' => 'й', 'ой' => 'й', 'ий' => 'й', 'ая' => 'я', 'ое' => 'е', 'ые' => 'е', 'ее' => 'е',
        'ую' => 'ю', 'ым' => 'м', 'ом' => 'м', 'им' => 'м', 'ых' => 'х', 'их' => 'х',
        'ого' => 'го', 'его' => 'го', 'ому' => 'му', 'ему' => 'му', 'ыми' => 'ми', 'ими' => 'ми',
    ];

    /** @var string[] */
    private array $store = [];

    /**
     * Весь конвейер в порядке milchin.proofread().
     *
     * @param  array{percent_space?: bool, initials_space?: bool, ruble?: bool, iso_dates?: bool}  $flags
     * @param  callable|null  $inside  доп. правила, которые идут внутри защиты зон, до её снятия: fn(string, Counter): string
     */
    public function proofread(string $text, array $flags, Counter $c, ?callable $inside = null): string
    {
        $this->store = [];

        $text = $this->w9Nfc($text, $c);
        $text = $this->w3Bom($text, $c);
        $text = $this->mask($text);

        $text = $this->w2ZeroWidth($text, $c);
        $text = $this->w15Narrow($text, $c);
        $text = $this->w6Mojibake($text, $c);
        $text = $this->w1Homoglyphs($text, $c);
        $text = $this->sub('/“([^“”]*)”/u', static fn ($m) => self::LAQUO . $m[1] . self::RAQUO, $text, $c, 'W4');

        $text = $this->sub('/[\x{20}\t\x{A0}]+(?=\r?\n|\z)/u', '', $text, $c, 'TG8');
        $text = $this->sub('/(?<=\S)\x{20}{2,}/u', ' ', $text, $c, 'T14');
        $text = $this->sub('/[ \t]+([.,;:!?»\)])/u', '$1', $text, $c, 'T15');
        $text = $this->sub('/([«\(])[ \t]+/u', '$1', $text, $c, 'T16');
        $text = $this->sub('/([,;:!?])(?=[A-Za-zА-Яа-яЁё])/u', '$1 ', $text, $c, 'T24');

        $text = $this->sub('/(?<!!)!!(?![!?])/u', '!', $text, $c, 'TG1');
        $text = $this->sub('/(?<![!?])!\?(?![!?])/u', '?!', $text, $c, 'TG2');
        $text = $this->sub('/(?<=[^\s,]),,(?!,)/u', ',', $text, $c, 'TG3');
        $text = $this->sub('/(?<=[^\s;]);;(?!;)/u', ';', $text, $c, 'TG3');
        $text = $this->sub('/(?<=[^\s?!])\?\?(?![?!])/u', '?', $text, $c, 'TG3');

        $text = $this->t1Quotes($text, $c);

        if (!empty($flags['iso_dates'])) {
            $text = $this->tg10IsoDate($text, $c);
        }

        $text = $this->tg6Ranges($text, $c);
        $text = $this->t4Range($text, $c);
        $text = $this->sub('/(?<=\S)\x{20}[-\x{2010}]+\x{20}/u', ' ' . self::EMDASH . ' ', $text, $c, 'T3');
        $text = $this->sub('/(\d)\x{20}(гг)(?=\.)/u', '$1' . self::NBSP . 'гг', $text, $c, 'D8');

        $text = $this->sub('/(\d)\x{20}(' . self::DEGREE . '(?![CFСcf])|′|″)/u', '$1$2', $text, $c, 'S6');

        $text = $this->t11Abbrev($text, $c);
        $text = $this->sub('/(?<![^\s(])([' . self::ONE_LETTER . '])\x{20}/u', '$1' . self::NBSP, $text, $c, 'T5');
        $text = $this->t6NumberUnit($text, $c);
        $text = $this->sub('/№\s*(\d)/u', '№' . self::NBSP . '$1', $text, $c, 'S23');
        $text = $this->sub('/(\S)\x{20}' . self::EMDASH . '/u', '$1' . self::NBSP . self::EMDASH, $text, $c, 'T9');
        $text = $this->tg5Abbr($text, $c);
        $text = $this->tg7Ordinals($text, $c);

        $text = $this->sub('/\.{3,}/u', self::ELLIPSIS, $text, $c, 'T12');
        $text = $this->sub('/([?!])' . self::ELLIPSIS . '/u', '$1..', $text, $c, 'TG4');
        $text = $this->s1MulSign($text, $c);

        $text = $this->t7Percent($text, $c, !empty($flags['percent_space']));

        if ($flags['initials_space'] ?? true) {
            $text = $this->sub('/([А-Я])\.\s*([А-Я])\.\s*([А-Я][а-я]+)/u', '$1.' . self::NBSP . '$2.' . self::NBSP . '$3', $text, $c, 'T10');
        }

        if (!empty($flags['ruble'])) {
            $text = $this->tg9Ruble($text, $c);
        }

        if ($inside) {
            $text = $inside($text, $c);
        }

        return $this->unmask($text);
    }

    // ------------------------------------------------------------------ защита зон (W11, W12)

    private function stash(string $s): string
    {
        $this->store[] = $s;

        return self::PH_OPEN . (\count($this->store) - 1) . self::PH_CLOSE;
    }

    private function mask(string $text): string
    {
        $stash = fn (array $m): string => $this->stash($m[0]);

        // фронтматтер YAML, огороженный и встроенный код
        $text = preg_replace_callback('/\A---\r?\n.*?\r?\n---(?=\r?\n|\z)/su', $stash, $text) ?? $text;
        $text = preg_replace_callback('/```.*?```|~~~.*?~~~/su', $stash, $text) ?? $text;
        $text = preg_replace_callback('/(`+)(?:(?!\1).)*?\1/su', $stash, $text) ?? $text;
        // markdown-ссылка: текст типографится, адрес — нет
        $text = preg_replace_callback('/\[([^\]]*)\]\(([^)]+)\)/u', fn ($m) => '[' . $m[1] . '](' . $this->stash($m[2]) . ')', $text) ?? $text;
        $text = preg_replace_callback('~<https?://[^>\s]+>~u', $stash, $text) ?? $text;
        $text = preg_replace_callback('~https?://[^\s<>\)\]]+~u', $stash, $text) ?? $text;
        $text = preg_replace_callback('/(?<!\w)@[\w_]+/u', $stash, $text) ?? $text;

        return preg_replace_callback('/(?<!\w)#[\w]+/u', $stash, $text) ?? $text;
    }

    private function unmask(string $text): string
    {
        $prev = null;

        while ($prev !== $text) {
            $prev = $text;
            $text = preg_replace_callback('/' . self::PH_OPEN . '(\d+)' . self::PH_CLOSE . '/u', fn ($m) => $this->store[(int) $m[1]] ?? $m[0], $text) ?? $text;
        }

        return $text;
    }

    // ------------------------------------------------------------------ юникод-гигиена

    private function w9Nfc(string $text, Counter $c): string
    {
        if (!class_exists(\Normalizer::class)) {
            return $text;
        }

        $out = \Normalizer::normalize($text, \Normalizer::FORM_C);

        if (\is_string($out) && $out !== $text) {
            $c->add('W9');

            return $out;
        }

        return $text;
    }

    private function w3Bom(string $text, Counter $c): string
    {
        if (str_starts_with($text, self::BOM)) {
            $c->add('W3');

            return substr($text, \strlen(self::BOM));
        }

        return $text;
    }

    private function w2ZeroWidth(string $text, Counter $c): string
    {
        $n = 0;

        foreach ([...self::ZERO_WIDTH, self::BOM] as $ch) {
            $k = substr_count($text, $ch);

            if ($k) {
                $text = str_replace($ch, '', $text);
                $n += $k;
            }
        }

        $c->add('W2', $n);

        return $text;
    }

    private function w15Narrow(string $text, Counter $c): string
    {
        $n = 0;

        foreach (self::NARROW_SPACES as $src => $dst) {
            $k = substr_count($text, $src);

            if ($k) {
                $text = str_replace($src, $dst, $text);
                $n += $k;
            }
        }

        $c->add('W15', $n);

        return $text;
    }

    private function w6Mojibake(string $text, Counter $c): string
    {
        $n = 0;

        foreach (self::MOJIBAKE as [$src, $dst]) {
            $k = substr_count($text, $src);

            if ($k) {
                $text = str_replace($src, $dst, $text);
                $n += $k;
            }
        }

        $c->add('W6', $n);

        return $text;
    }

    /** W1 — латиница внутри кириллического слова → кириллица, если у всех латинских букв есть двойник. Чистая латиница не трогается. */
    private function w1Homoglyphs(string $text, Counter $c): string
    {
        $n   = 0;
        $out = preg_replace_callback('/[^\W\d_]+/u', static function (array $m) use (&$n): string {
            $tok = $m[0];

            if (!preg_match('/\p{Cyrillic}/u', $tok) || !preg_match('/\p{Latin}/u', $tok)) {
                return $tok;
            }

            preg_match_all('/\p{Latin}/u', $tok, $lat);

            foreach ($lat[0] as $ch) {
                if (!isset(self::LAT_TO_CYR[$ch])) {
                    return $tok;
                }
            }

            $new = preg_replace_callback('/\p{Latin}/u', static fn ($x) => self::LAT_TO_CYR[$x[0]], $tok) ?? $tok;

            if ($new !== $tok) {
                $n++;
            }

            return $new;
        }, $text);

        $c->add('W1', $n);

        return $out ?? $text;
    }

    // ------------------------------------------------------------------ кавычки, тире

    /** T1/T2 — прямые кавычки → «ёлочки», вложенные → „лапки“; дюймы (5") не трогаются. */
    private function t1Quotes(string $text, Counter $c): string
    {
        if (!str_contains($text, '"')) {
            return $text;
        }

        $chars = mb_str_split($text);
        $depth = 0;
        $n     = 0;

        foreach ($chars as $i => $ch) {
            if ($ch !== '"') {
                continue;
            }

            $prev = $i > 0 ? $chars[$i - 1] : '';

            if ($depth === 0) {
                if ($prev !== '' && preg_match('/^\d$/u', $prev)) {
                    continue;
                }

                $chars[$i] = self::LAQUO;
                $depth     = 1;
            } elseif ($depth === 1) {
                if (\in_array($prev, [' ', self::NBSP, '(', self::LAQUO, ''], true)) {
                    $chars[$i] = self::LDQUO;
                    $depth     = 2;
                } else {
                    $chars[$i] = self::RAQUO;
                    $depth     = 0;
                }
            } else {
                $chars[$i] = self::RDQUO;
                $depth     = 1;
            }

            $n++;
        }

        $c->add('T1', $n);

        return implode('', $chars);
    }

    private function t4Range(string $text, Counter $c): string
    {
        $protected = [];
        $stash     = static function (array $m) use (&$protected): string {
            $protected[] = $m[0];

            return self::PH_OPEN . 'R' . (\count($protected) - 1) . self::PH_CLOSE;
        };

        $text = preg_replace_callback('/(?<![\d-])\d{4}-\d{2}-\d{2}(?![\d-])/u', $stash, $text) ?? $text;
        $text = preg_replace_callback('/\b\d+(?:-\d+){2,}\b/u', $stash, $text) ?? $text;
        $text = $this->sub('/(\d)-(\d)/u', '$1' . self::ENDASH . '$2', $text, $c, 'T4');

        return preg_replace_callback('/' . self::PH_OPEN . 'R(\d+)' . self::PH_CLOSE . '/u', static fn ($m) => $protected[(int) $m[1]], $text) ?? $text;
    }

    private function tg6Ranges(string $text, Counter $c): string
    {
        $dash = '[\x{20}\x{A0}]?[-' . self::ENDASH . self::EMDASH . '][\x{20}\x{A0}]?';
        $to   = '$1' . self::ENDASH . '$2';

        $text = $this->sub('/(?<![\d.,:])(\d{4})' . $dash . '(\d{4})(?=[\x{20}\x{A0}]?гг?\.)/u', $to, $text, $c, 'TG6');
        $text = $this->sub('/\b(' . self::ROMAN . ')' . $dash . '(' . self::ROMAN . ')(?=[\x{20}\x{A0}]?вв?\.)/u', $to, $text, $c, 'TG6');

        return $this->sub('/(?<![\d.,:])([12]?\d|3[01])' . $dash . '([12]?\d|3[01])(?=[\x{20}\x{A0}](?:' . self::MONTHS_GEN . ')\b)/u', $to, $text, $c, 'TG6');
    }

    private function tg10IsoDate(string $text, Counter $c): string
    {
        $n   = 0;
        $out = preg_replace_callback('/(?<![\w.\-])(\d{4})-(\d{2})-(\d{2})(?![\w\-])/u', static function ($m) use (&$n) {
            if ((int) $m[2] < 1 || (int) $m[2] > 12 || (int) $m[3] < 1 || (int) $m[3] > 31) {
                return $m[0];
            }

            $n++;

            return $m[3] . '.' . $m[2] . '.' . $m[1];
        }, $text);

        $c->add('TG10', $n);

        return $out ?? $text;
    }

    // ------------------------------------------------------------------ неразрывные пробелы

    private function t11Abbrev(string $text, Counter $c): string
    {
        $nb = self::NBSP;

        foreach ([
            ['/\bи\s+т\.\s*д\./u', "и{$nb}т.{$nb}д."],
            ['/\bи\s+т\.\s*п\./u', "и{$nb}т.{$nb}п."],
            ['/\bв\s+т\.\s*ч\./u', "в{$nb}т.{$nb}ч."],
            ['/\bт\.\s*е\./u', "т.{$nb}е."],
        ] as [$pat, $dst]) {
            $text = $this->sub($pat, $dst, $text, $c, 'T11');
        }

        return $text;
    }

    private function t6NumberUnit(string $text, Counter $c): string
    {
        $plain = array_values(array_filter(self::UNIT_TOKENS, static fn ($u) => $u !== self::DEGREE && $u !== '%'));
        // как sorted(key=len, reverse=True) в Python: длинные раньше, при равной длине порядок списка сохраняется
        $order = array_keys($plain);
        usort($order, static fn ($a, $b) => (mb_strlen($plain[$b]) <=> mb_strlen($plain[$a])) ?: ($a <=> $b));
        $units = implode('|', array_map(static fn ($i) => preg_quote($plain[$i], '/'), $order));

        $text = $this->sub('/(\d)\x{20}?(' . $units . ')(?![\w])/u', '$1' . self::NBSP . '$2', $text, $c, 'T6');

        return $this->sub('/(\d)\x{20}?' . self::DEGREE . '([CFСcf])/u', '$1' . self::NBSP . self::DEGREE . '$2', $text, $c, 'T6');
    }

    private function tg5Abbr(string $text, Counter $c): string
    {
        $afterNum = '(?<!\d\x{20})(?<!\d\x{A0})';
        $to       = '$1' . self::NBSP;

        foreach ([
            '/(?<![\w.])([Сс]тр\.|[Гг]л\.|[Рр]ис\.|[Кк]в\.)\x{20}(?=\d|[IVXLC]+\b)/u',
            '/' . $afterNum . '(?<![\w.])([Сс]м\.)\x{20}(?=[\w«(])/u',
            '/' . $afterNum . '(?<![\w.])(г\.|ул\.)\x{20}(?=[А-ЯЁ\d])/u',
            '/(§+)\x{20}?(?=\d|[IVXLC]+\b)/u',
            '/(\b' . self::ROMAN . ')\x{20}(?=вв?\.)/u',
        ] as $pat) {
            $text = $this->sub($pat, $to, $text, $c, 'TG5');
        }

        return $text;
    }

    private function tg7Ordinals(string $text, Counter $c): string
    {
        $ends = array_keys(self::ORDINAL_SHORT);
        $idx  = array_keys($ends);
        usort($idx, static fn ($a, $b) => (mb_strlen($ends[$b]) <=> mb_strlen($ends[$a])) ?: ($a <=> $b));
        $alt = implode('|', array_map(static fn ($i) => $ends[$i], $idx));

        $n   = 0;
        $out = preg_replace_callback('/(\d)-(' . $alt . ')(?![А-Яа-яЁё\w])/u', static function ($m) use (&$n) {
            $n++;

            return $m[1] . '-' . self::ORDINAL_SHORT[$m[2]];
        }, $text);

        $c->add('TG7', $n);

        return $out ?? $text;
    }

    private function s1MulSign(string $text, Counter $c): string
    {
        $n   = 0;
        $out = preg_replace_callback('/\b([A-Za-zА-я]{1,4})\s+([xх*])\s+([A-Za-zА-я]{1,4})\b/u', static function ($m) use (&$n) {
            if (\in_array($m[1], self::UNITS_MULSIGN, true) && \in_array($m[3], self::UNITS_MULSIGN, true)) {
                $n++;

                return $m[1] . self::MULSIGN . $m[3];
            }

            return $m[0];
        }, $text);

        $c->add('S1', $n);

        return $out ?? $text;
    }

    private function t7Percent(string $text, Counter $c, bool $space): string
    {
        return $space
            ? $this->sub('/(\d)%/u', '$1' . self::NBSP . '%', $text, $c, 'T7')
            : $this->sub('/(\d)[\x{20}\x{A0}]%/u', '$1%', $text, $c, 'T7');
    }

    private function tg9Ruble(string $text, Counter $c): string
    {
        $n   = 0;
        $out = preg_replace_callback(
            '/(\d(?:[\x{20}\x{A0}](?:тыс\.|млн|млрд|трлн))?)[\x{20}\x{A0}]?(?:руб|р)\.((?=\s*\z)|(?=\s+[А-ЯЁA-Z])|(?=\r?\n))?/u',
            static function ($m) use (&$n) {
                $n++;

                return $m[1] . self::NBSP . '₽' . (isset($m[2]) ? '.' : '');
            },
            $text
        );

        $c->add('TG9', $n);

        return $out ?? $text;
    }

    // ------------------------------------------------------------------ служебное

    /** preg_replace с подсчётом правок по коду правила. */
    private function sub(string $pattern, string|callable $to, string $text, Counter $c, string $rule): string
    {
        $n   = 0;
        $out = \is_string($to) ? preg_replace($pattern, $to, $text, -1, $n) : preg_replace_callback($pattern, $to, $text, -1, $n);

        if ($out === null) {
            return $text;
        }

        $c->add($rule, $n);

        return $out;
    }
}

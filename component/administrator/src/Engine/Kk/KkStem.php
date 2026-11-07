<?php

/**
 * @package     Webmarka.Redkollegiya
 * @subpackage  com_wmarka_redkollegiya
 * @copyright   (C) 2026 Beibit Sakhanov
 * @license     GNU General Public License version 3 or later; see LICENSE
 *
 * Перенос valid_ending из kk_stem.py (github.com/bsakhanov/kk-redkollegiya). Совпадение — tests/parity/kk_compare.py.
 */

namespace Webmarka\Component\Redkollegiya\Administrator\Engine\Kk;

\defined('_JEXEC') or die;

/**
 * Можно ли получить «основа + окончание» цепочкой [мн. число] [притяжательность] [падеж] по правилам гармонии гласных
 * и стыков согласных. Нужна, чтобы отличить словоизменение («проектің» — форма «проект») от другого слова («проектор»).
 */
final class KkStem
{
    private const BACKV  = 'аоұыя';
    private const FRONTV = 'әөүіе';
    private const VOWELS = 'аәеёиоөұүыіэюя';
    private const VOICELESS   = 'кқпстфхцчшщһ';
    private const VOICED_STOP = 'бвгғджз';

    public static function validEnding(string $stem, string $ending, bool $both = false): bool
    {
        if ($ending === '') {
            return false;
        }

        $last = mb_substr($stem, -1);
        $prev = mb_strlen($stem) >= 2 ? mb_substr($stem, -2, 1) : '';

        if ($prev !== '' && $last === 'т' && !self::in($prev, self::VOWELS) && self::in(mb_substr($ending, 0, 1), self::VOWELS)
            && self::valid($stem, 'т' . $ending, $both)) {
            return true;
        }

        return self::valid($stem, $ending, $both);
    }

    private static function valid(string $stem, string $ending, bool $both): bool
    {
        foreach ($both ? ['b', 'f'] : self::classes($stem) as $h) {
            foreach (['', self::plural($stem, $h)] as $pl) {
                if ($pl !== '' && !str_starts_with($ending, $pl)) {
                    continue;
                }

                $w1 = $stem . $pl;
                $r1 = substr($ending, \strlen($pl));

                foreach (array_merge([['', null]], self::possessives($w1, $h)) as [$pos, $pk]) {
                    if ($pos !== '' && !str_starts_with($r1, $pos)) {
                        continue;
                    }

                    $r2 = substr($r1, \strlen($pos));

                    if ($r2 === '') {
                        if ($pl !== '' || $pos !== '') {
                            return true;
                        }

                        continue;
                    }

                    if (\in_array($r2, self::cases($w1 . $pos, $h, $pk), true)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private static function in(string $ch, string $set): bool
    {
        return $ch !== '' && mb_strpos($set, $ch) !== false;
    }

    /** @return string[] */
    private static function classes(string $w): array
    {
        foreach (array_reverse(mb_str_split($w)) as $ch) {
            if (self::in($ch, self::BACKV)) {
                return ['b'];
            }

            if (self::in($ch, self::FRONTV)) {
                return ['f'];
            }
        }

        return ['b', 'f'];
    }

    private static function sound(string $w): string
    {
        $c = mb_substr($w, -1);

        return match (true) {
            self::in($c, self::VOWELS)    => 'V',
            self::in($c, 'уй')            => 'J',
            $c === 'р'                    => 'R',
            self::in($c, 'мнң')           => 'S',
            self::in($c, self::VOICELESS) => 'U',
            default                       => 'L',
        };
    }

    private static function plural(string $w, string $h): string
    {
        $s   = self::sound($w);
        $ini = str_contains('VRJ', $s) ? 'л' : ($s === 'U' ? 'т' : 'д');

        return $ini . ($h === 'b' ? 'ар' : 'ер');
    }

    /** @return array<int, array{0: string, 1: string}> */
    private static function possessives(string $w, string $h): array
    {
        $a = $h === 'b' ? 'ы' : 'і';

        if (self::sound($w) === 'V') {
            return [['м', '1'], ['ң', '2'], ['ң' . $a . 'з', '2f'], [$h === 'b' ? 'сы' : 'сі', '3'], [$h === 'b' ? 'мыз' : 'міз', '1pl']];
        }

        return [[$a . 'м', '1'], [$a . 'ң', '2'], [$a . 'ң' . $a . 'з', '2f'], [$a, '3'], [$a . ($h === 'b' ? 'мыз' : 'міз'), '1pl']];
    }

    /** @return string[] */
    private static function cases(string $w, string $h, ?string $pk): array
    {
        $f = static fn (string $b, string $fr): string => $h === 'b' ? $b : $fr;

        if ($pk === '3') {
            return [$f('ның', 'нің'), 'н', $f('на', 'не'), $f('нда', 'нде'), $f('нан', 'нен'), 'мен'];
        }

        $s   = self::sound($w);
        $gen = str_contains('VS', $s) ? 'ның' : ($s === 'U' ? 'тың' : 'дың');
        $acc = $s === 'V' ? 'ны' : ($s === 'U' ? 'ты' : 'ды');

        if ($h === 'f') {
            $gen = str_replace('ы', 'і', $gen);
            $acc = str_replace('ы', 'і', $acc);
        }

        $dat  = \in_array($pk, ['1', '2', '2f'], true) ? $f('а', 'е') : ($s === 'U' ? $f('қа', 'ке') : $f('ға', 'ге'));
        $loc  = $s === 'U' ? $f('та', 'те') : $f('да', 'де');
        $abl  = $s === 'S' ? $f('нан', 'нен') : ($s === 'U' ? $f('тан', 'тен') : $f('дан', 'ден'));
        $last = mb_substr($w, -1);
        $ins  = self::in($last, self::VOICED_STOP) ? 'бен' : (self::in($last, self::VOICELESS) ? 'пен' : 'мен');

        return [$gen, $acc, $dat, $loc, $abl, $ins];
    }
}

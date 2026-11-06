<?php

/**
 * @package     Webmarka.Redkollegiya
 * @subpackage  com_wmarka_redkollegiya
 * @copyright   (C) 2026 Beibit Sakhanov
 * @license     GNU General Public License version 3 or later; see LICENSE
 */

namespace Webmarka\Component\Redkollegiya\Administrator\Engine;

\defined('_JEXEC') or die;

/** Итог прогона: текст, язык и правки по правилам; для сводки редактору правила сведены в понятные группы. */
final class Result
{
    /** Группа сводки → правила. Порядок групп — порядок в сообщении. */
    public const GROUPS = [
        'quotes'   => ['T1', 'W4', 'Y01'],
        'dashes'   => ['T3', 'T4', 'TG6', 'Y02', 'Y04', 'H-DASH', 'H-RANGE'],
        'minus'    => ['H-MINUS'],
        'decimal'  => ['H-DEC'],
        'nbsp'     => ['T5', 'T6', 'T9', 'T10', 'T11', 'TG5', 'D8', 'S23', 'Y03', 'Y05', 'Y10', 'Y11', 'H-UNIT', 'H-PREP'],
        'yo'       => ['YO'],
        'currency' => ['H-CUR', 'TG9'],
        'numbers'  => ['T7', 'Y12', 'Y13', 'Y14', 'TG7', 'TG10', 'S1', 'S6', 'H-MLN', 'Y09', 'Y06'],
        'ellipsis' => ['T12', 'TG4', 'Y07'],
        'spaces'   => ['T14', 'T15', 'T16', 'T24', 'TG8', 'Y08', 'TG1', 'TG2', 'TG3'],
        'hygiene'  => ['W1', 'W2', 'W3', 'W6', 'W9', 'W15'],
    ];

    public function __construct(public readonly string $text, public readonly string $lang, private Counter $counter)
    {
    }

    /** @return array<string, int> правило → правок */
    public function stats(): array
    {
        return $this->counter->all();
    }

    public function total(): int
    {
        return $this->counter->total();
    }

    /** @return array<string, int> группа → правок (только непустые) */
    public function groups(): array
    {
        $stats = $this->stats();
        $out   = [];

        foreach (self::GROUPS as $group => $rules) {
            $n = 0;

            foreach ($rules as $r) {
                $n += $stats[$r] ?? 0;
                unset($stats[$r]);
            }

            if ($n) {
                $out[$group] = $n;
            }
        }

        if ($rest = array_sum($stats)) {
            $out['other'] = $rest;
        }

        return $out;
    }
}

<?php

/**
 * @package     Webmarka.Redkollegiya
 * @subpackage  com_wmarka_redkollegiya
 * @copyright   (C) 2026 Beibit Sakhanov
 * @license     GNU General Public License version 3 or later; see LICENSE
 */

namespace Webmarka\Component\Redkollegiya\Administrator\Engine;

\defined('_JEXEC') or die;

/** Сколько правок внесло каждое правило: код правила → число. */
final class Counter
{
    /** @var array<string, int> */
    private array $counts = [];

    public function add(string $rule, int $n = 1): void
    {
        if ($n > 0) {
            $this->counts[$rule] = ($this->counts[$rule] ?? 0) + $n;
        }
    }

    public function merge(self $other): void
    {
        foreach ($other->all() as $rule => $n) {
            $this->add($rule, $n);
        }
    }

    /** @return array<string, int> */
    public function all(): array
    {
        ksort($this->counts, SORT_NATURAL);

        return $this->counts;
    }

    public function total(): int
    {
        return array_sum($this->counts);
    }
}

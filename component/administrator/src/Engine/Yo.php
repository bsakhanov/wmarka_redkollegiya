<?php

/**
 * @package     Webmarka.Redkollegiya
 * @subpackage  com_wmarka_redkollegiya
 * @copyright   (C) 2026 Beibit Sakhanov
 * @license     GNU General Public License version 3 or later; see LICENSE
 *
 * Поиск слов и словарь — по eyo-kernel Дениса Селезнёва (github.com/e2yo/eyo-kernel, лицензия MIT; уведомление — в NOTICE).
 */

namespace Webmarka\Component\Redkollegiya\Administrator\Engine;

\defined('_JEXEC') or die;

/**
 * Буква «ё». Восстанавливается только по безопасному словарю eyo: там слова, где «е» вместо «ё» не бывает
 * («ещё», «её», «объём»); «все/всё», «небо/нёбо» в нём нет и не трогаются. Имена собственные из словаря («Королёв»)
 * по умолчанию не трогаются: фамилию пишут так, как в документах человека.
 *
 * Словарь не разворачивается целиком (212 тыс. форм, около 30 МБ памяти): хранятся 14,6 тыс. основ с их окончаниями,
 * и слово проверяется по своим началам.
 */
final class Yo
{
    private const PUNCT = '[{}()|<>=_"\'«»„“#$^%&*+:;,?!\x{2011}\-\[\]]';

    /** @var array<string, array<int, array{0: string, 1: array<string, string>, 2: bool, 3: bool}>> основа (через «е») → [основа с «ё», окончания, только строчная, имя] */
    private static array $bases = [];

    private static string $loaded = '';

    public function __construct(private string $dictionary)
    {
    }

    public function restore(string $text, Counter $c, bool $names = false): string
    {
        if (!preg_match('/[ЕЁеё]/u', $text) || !$this->load()) {
            return $text;
        }

        $re = '/([А-ЯЁа-яё])[а-яё]+(?![а-яё]|\.[ \x{A0}\t]+([а-яё]|[А-ЯЁ]{2}|' . self::PUNCT . ')|\.' . self::PUNCT . ')/u';
        $n  = 0;

        $out = preg_replace_callback($re, function (array $m) use (&$n, $names): string {
            $word = $m[0];
            $yo   = $this->word($word, $names);

            if ($yo !== $word) {
                $n++;
            }

            return $yo;
        }, $text);

        $c->add('YO', $n);

        return $out ?? $text;
    }

    public function remove(string $text, Counter $c): string
    {
        $n = preg_match_all('/[\p{L}]*[Ёё][\p{L}]*/u', $text);
        $c->add('YO', (int) $n);

        return str_replace(['ё', 'Ё'], ['е', 'Е'], $text);
    }

    /** Слово с восстановленной «ё» или как было. */
    public function word(string $word, bool $names = false): string
    {
        $key  = str_replace(['ё', 'Ё'], ['е', 'Е'], $word);
        $hit  = $this->lookup($key, $names, false);

        if ($hit === null && preg_match('/^[А-ЯЁ]/u', $key)) {
            // строчное слово словаря в начале предложения: «Ещё» — как «ещё»
            $low = mb_strtolower(mb_substr($key, 0, 1)) . mb_substr($key, 1);
            $hit = $this->lookup($low, $names, true);

            if ($hit !== null) {
                $hit = mb_strtoupper(mb_substr($hit, 0, 1)) . mb_substr($hit, 1);
            }
        }

        return $hit ?? $word;
    }

    private function lookup(string $key, bool $names, bool $capitalised): ?string
    {
        $found = null;
        $len   = mb_strlen($key);

        for ($l = 1; $l <= $len; $l++) {
            $base = mb_substr($key, 0, $l);

            if (!isset(self::$bases[$base])) {
                continue;
            }

            $rest = mb_substr($key, $l);

            foreach (self::$bases[$base] as [$yoBase, $ends, $lowOnly, $isName]) {
                if (!isset($ends[$rest]) || ($isName && !$names) || ($capitalised && ($lowOnly || $isName))) {
                    continue;
                }

                // как в eyo: при совпадении ключей действует запись, стоящая в словаре позже
                $found = $yoBase . $ends[$rest];
            }
        }

        return $found;
    }

    private function load(): bool
    {
        if (self::$loaded === $this->dictionary) {
            return self::$bases !== [];
        }

        self::$loaded = $this->dictionary;
        self::$bases  = [];

        if (!is_file($this->dictionary)) {
            return false;
        }

        $fold = static fn (string $s): string => str_replace(['ё', 'Ё'], ['е', 'Е'], $s);

        foreach (file($this->dictionary, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $raw) {
            $line = $raw;

            if (($p = strpos($line, '#')) !== false) {
                $line = trim(substr($line, 0, $p));
            }

            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $lowOnly = str_starts_with($line, '_');
            $line    = ltrim($line, '_');
            $isName  = (bool) preg_match('/^[А-ЯЁ]/u', $line);

            if (str_contains($line, '(')) {
                $parts = preg_split('/[(|)]/u', $line) ?: [$line];
                $base  = $parts[0];
                $ends  = [];

                for ($i = 1, $k = \count($parts) - 1; $i < $k; $i++) {
                    $ends[$fold($parts[$i])] = $parts[$i];
                }
            } else {
                $base = $line;
                $ends = ['' => ''];
            }

            self::$bases[$fold($base)][] = [$base, $ends, $lowOnly, $isName];
        }

        return self::$bases !== [];
    }
}

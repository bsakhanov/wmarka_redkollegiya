<?php

/**
 * @package     Webmarka.Redkollegiya
 * @subpackage  com_wmarka_redkollegiya
 * @copyright   (C) 2026 Beibit Sakhanov
 * @license     GNU General Public License version 3 or later; see LICENSE
 */

namespace Webmarka\Component\Redkollegiya\Administrator\Engine;

use Webmarka\Component\Redkollegiya\Administrator\Engine\Kk\KkLint;

\defined('_JEXEC') or die;

/**
 * Ядро Редколлегии: одна точка входа для кнопки в редакторе, правки при сохранении, ассистента через MCP и консоли.
 *
 *   $result = (new Engine($options))->process($html, 'ru-RU');      // разметка
 *   $result = (new Engine($options))->process($title, 'kk-KZ', false); // строка без разметки
 *
 * Язык: ru → Мильчин и правила издания, kk → kk_typo и правила издания; «*» или пусто — по буквам текста;
 * у прочих языков текст не меняется. Слова из списка «не трогать» и вызовы плагинов вида {loadmoduleid 5} закрываются
 * до всех правил и возвращаются после.
 */
final class Engine
{
    public const DEFAULTS = [
        'percent'        => 'slit',     // ru: «10%» (slit) или «10 %» с неразрывным пробелом (nbsp)
        'initials'       => 'nbsp',     // ru: «А. С. Пушкин» с неразрывными пробелами (nbsp) или как есть (keep)
        'ruble'          => false,      // ru: «5 руб.» → «5 ₽»
        'iso_dates'      => false,      // ru: «2026-06-09» → «09.06.2026»
        'kk_percent'     => 'slit',
        'kk_numero'      => 'slit',
        'kk_degree'      => 'slit',
        'kk_dash'        => 'em',
        'en_dash_words'  => true,       // «слово – слово» → «слово — слово»
        'minus'          => true,       // «-5%» → «−5%»
        'decimal_comma'  => true,       // «1.5 млрд» → «1,5 млрд»
        'mln_period'     => true,       // «млн.» → «млн» внутри предложения
        'prepositions'   => 'one',      // one — однобуквенные (Мильчин), short — и двух-, трёхбуквенные
        'range_dash'     => 'en',       // en — «2020–2025», em — «2020—2025»
        'currency_before' => false,     // «500 ₸», «5 млн $» → «₸500», «$5 млн» (новостной стиль)
        'nested_quotes'  => 'ldquo',    // вложенные кавычки: ldquo — „лапки“ (Мильчин), guillemets — тоже «ёлочки»
        'expand_abbr'    => false,      // «и т. д.», «и т. п.», «т. е.», «в т. ч.» → полными словами
        'time_colon'     => false,      // «в 15.00», «15-00 часов» → «в 15:00»
        'thousands'      => 5,          // разряды неразрывным пробелом в числах от стольки цифр («56 347»); 0 — не трогать
        'speech_comma'   => true,       // «…?», — сказал → «…?» — сказал; «…» перед авторскими словами без точки
        'link_punct'     => true,       // точка, запятая и пробелы — за пределы гиперссылки
        'no_h1'          => true,       // заголовок H1 в тексте материала → H2: H1 на странице один — заголовок материала
        'units'          => ['₸', 'тенге', 'тг', 'долл.', 'евро', 'баррелей', 'кубометров'],
        'yo'             => 'restore',  // restore — по безопасному словарю, remove — снять, off — не трогать
        'yo_names'       => false,      // ставить ли «ё» в именах собственных из словаря
        'protect'        => [],         // слова, которые не трогает ни одно правило
        'dictionary'     => '',         // путь к словарю «ё» (safe.txt eyo)
    ];

    private array $o;

    /** @var string[] */
    private array $masks = [];

    private string $lang = '';

    public function __construct(array $options = [])
    {
        $this->o = array_replace(self::DEFAULTS, array_intersect_key($options, self::DEFAULTS));

        if ($this->o['dictionary'] === '') {
            $this->o['dictionary'] = \dirname(__DIR__, 2) . '/data/yo-safe.txt';
        }
    }

    public function options(): array
    {
        return $this->o;
    }

    public function process(string $input, string $lang = '', bool $html = true): Result
    {
        $c    = new Counter();
        $lang = self::language($lang, $input);

        if ($lang === '' || trim($input) === '') {
            return new Result($input, $lang ?: 'other', $c);
        }

        $fn  = fn (string $plain): string => $this->plain($plain, $lang, $c);
        $out = $html ? (new Html())->process($this->markup($input, $c), $fn) : $fn($input);

        // правило могло «совпасть» с тем, что уже стоит как надо (Мильчин считает и такие совпадения), — если текст не изменился,
        // правок нет: иначе повторное сохранение чистого материала попадало бы в журнал
        return new Result($out, $lang, $out === $input ? new Counter() : $c);
    }

    /**
     * Правки разметки, а не текста: H1 в тексте материала → H2 (H1 на странице один — заголовок материала, его выводит
     * шаблон); точка, запятая и пробелы по краям гиперссылки выносятся за её пределы («передает <a>сайт.</a>» →
     * «передает <a>сайт</a>.»). Кавычки не выносятся: «<a>«Название»</a>» — ссылка на название целиком.
     */
    private function markup(string $html, Counter $c): string
    {
        if ($this->o['no_h1'] && stripos($html, '<h1') !== false) {
            $n    = 0;
            $html = preg_replace('~<(/?)h1(?=[\s>])~i', '<$1h2', $html, -1, $n) ?? $html;
            $c->add('H-H1', intdiv($n, 2));
        }

        if ($this->o['link_punct'] && stripos($html, '</a>') !== false) {
            $n    = 0;
            $prev = null;

            while ($prev !== $html) {
                $prev = $html;
                $k    = 0;
                // знаки и пробелы в конце ссылки — за </a>
                $html = preg_replace('~([.,;:!?]+|(?:\x{20}|\x{A0}|&nbsp;)+)(</a>)~u', '$2$1', $html, -1, $k) ?? $html;
                $n   += $k;
                // пробелы в начале ссылки — перед <a>
                $html = preg_replace('~(<a\b[^>]*>)((?:\x{20}|\x{A0}|&nbsp;)+)~u', '$2$1', $html, -1, $k) ?? $html;
                $n   += $k;
            }

            $c->add('H-LINK', $n);
        }

        return $html;
    }

    /** Код языка ru или kk; пустая строка — язык, который типограф не правит. */
    public static function language(string $tag, string $text = ''): string
    {
        $short = strtolower(substr(trim($tag), 0, 2));

        if ($short === 'ru' || $short === 'kk') {
            return $short;
        }

        if ($short !== '' && $short !== '*') {
            return '';
        }

        return self::detect($text);
    }

    /** По буквам: казахских букв (ә, ғ, қ…) не меньше трёх и не меньше 2% кириллицы — kk; иначе кириллица — ru. */
    public static function detect(string $text): string
    {
        $text = strip_tags($text);
        $cyr  = preg_match_all('/\p{Cyrillic}/u', $text);

        if (!$cyr) {
            return '';
        }

        $kk = preg_match_all('/[әғқңөұүһіӘҒҚҢӨҰҮҺІ]/u', $text);

        return $kk >= 3 && $kk >= 0.02 * $cyr ? 'kk' : 'ru';
    }

    /** Текст без разметки. */
    public function plain(string $text, string $lang, Counter $c): string
    {
        $this->lang = $lang;
        $text       = $this->mask($text);
        $house = new House();
        $yo    = $this->o['yo'] !== 'off' ? new Yo((string) $this->o['dictionary']) : null;
        $ho    = [
            'en_dash_words' => (bool) $this->o['en_dash_words'],
            'minus'         => (bool) $this->o['minus'],
            'decimal_comma' => (bool) $this->o['decimal_comma'],
            'mln_period'    => (bool) $this->o['mln_period'],
            'prepositions'  => (string) $this->o['prepositions'],
            'units'         => (array) $this->o['units'],
            'range_dash'    => (string) $this->o['range_dash'],
            'currency_before' => (bool) $this->o['currency_before'],
            'nested_quotes'   => (string) $this->o['nested_quotes'],
            'expand_abbr'     => (bool) $this->o['expand_abbr'],
            'time_colon'      => (bool) $this->o['time_colon'],
            'thousands'       => (int) $this->o['thousands'],
            'speech_comma'    => (bool) $this->o['speech_comma'],
        ];

        if ($lang === 'kk') {
            // Алтынсарин, гигиена (kk_lint H01–H04): двойники латиницы в словах, «ə» вместо «ә», разложенные диакритики, невидимые знаки
            $text = KkLint::cleanse($text, static fn (string $rule) => $c->add($rule));
            $text = (new KkTypo())->fix($text, [
                'dash'          => $this->o['kk_dash'] === 'keep' ? 'keep' : 'em',
                'percent_space' => $this->o['kk_percent'] === 'nbsp',
                'numero_space'  => $this->o['kk_numero'] === 'nbsp',
                'degree_space'  => $this->o['kk_degree'] === 'nbsp',
            ], $c);
            $text = $house->apply($text, 'kk', $ho, $c);
        } else {
            $text = (new Milchin())->proofread($text, [
                'percent_space'  => $this->o['percent'] === 'nbsp',
                'initials_space' => $this->o['initials'] !== 'keep',
                'ruble'          => (bool) $this->o['ruble'],
                'iso_dates'      => (bool) $this->o['iso_dates'],
            ], $c, fn (string $t, Counter $c): string => $house->apply($t, 'ru', $ho, $c, $yo, (string) $this->o['yo'], (bool) $this->o['yo_names']));
        }

        return $this->unmask($text);
    }

    // ------------------------------------------------------------------ защита слов и вызовов плагинов

    /**
     * Защищённый кусок заменяется одним знаком из частной области Юникода (U+F0000 и дальше): для правил это «не буква,
     * не цифра, не пробел», в нём нечего исправлять, и на месте он остаётся при любой правке соседей.
     */
    private function mask(string $text): string
    {
        $this->masks = [];
        $stash       = function (array $m): string {
            $this->masks[] = $m[0];

            return mb_chr(0xF0000 + \count($this->masks) - 1, 'UTF-8');
        };

        // вызовы плагинов Joomla: {loadmoduleid 17}, {loadposition top}, {source}…{/source}, {{заполнитель}},
        // и с вложенными скобками до двух уровней: {gallery {"dir":"a - b"}} — кавычки внутри не трогаются
        $text = preg_replace_callback(
            '/\{\{[^{}\n]+\}\}|\{\/?[a-z][a-z0-9_-]*(?:[\s=](?:[^{}\n]|\{(?:[^{}\n]|\{[^{}\n]*\})*\})*)?\}/u',
            $stash,
            $text
        ) ?? $text;

        // непарные прямые кавычки (дюймы «5"» не в счёт) остаются как есть: цитата на несколько абзацев или опечатка —
        // угадывать, где она открывается и где закрывается, типограф не берётся
        if ($this->lang === 'ru' && preg_match_all('/(?<!\d)"/u', $text) % 2 === 1) {
            $text = preg_replace_callback('/(?<!\d)"/u', $stash, $text) ?? $text;
        }

        $words = array_values(array_unique(array_filter(array_map('trim', (array) $this->o['protect']), static fn ($w) => $w !== '')));

        if ($words) {
            usort($words, static fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

            foreach (array_chunk($words, 500) as $chunk) {
                $alt  = implode('|', array_map(static fn ($w) => preg_quote($w, '/'), $chunk));
                $text = preg_replace_callback('/(?<![\w])(?:' . $alt . ')(?![\w])/u', $stash, $text) ?? $text;
            }
        }

        return $text;
    }

    private function unmask(string $text): string
    {
        if (!$this->masks) {
            return $text;
        }

        $map = [];

        foreach ($this->masks as $i => $s) {
            $map[mb_chr(0xF0000 + $i, 'UTF-8')] = $s;
        }

        return strtr($text, $map);
    }
}

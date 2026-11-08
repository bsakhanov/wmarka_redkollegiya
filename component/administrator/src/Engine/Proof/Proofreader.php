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
use Webmarka\Component\Redkollegiya\Administrator\Engine\Kk\KkLint;
use Webmarka\Component\Redkollegiya\Administrator\Engine\Kk\KkParity;
use Webmarka\Component\Redkollegiya\Administrator\Engine\Kk\KkRestore;

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
        'kk_lint'   => true,      // казахский текст: Алтынсарин, Шакарим, Байтурсынов (kk_lint)
        'kk_glossary' => true,    // термины по глоссарию казахской Редколлегии (T01, T02, PR06)
        'kk_restore'  => true,    // S01: казахские буквы в словах, набранных без них (модель KazNERD)
        'original'  => null,      // абзацы русского оригинала для сверки казахской версии (Валиханов)
        'glossary'  => '',        // путь к глоссарию JSON
        'house'     => [],        // правила редакции: time, style, names, units, struct, title
        'site'      => '',        // имя сайта для проверок «лид» и «первое упоминание — ссылкой» (the-tenge.kz)
    ];

    /** Слой находки kk_lint по её коду. */
    private const KK_LAYER = ['H' => 'altynsarin', 'P01' => 'altynsarin', 'P03' => 'altynsarin', 'P04' => 'altynsarin',
        'T' => 'shakarim', 'P02' => 'baitursynov', 'P05' => 'baitursynov', 'C' => 'baitursynov', 'S' => 'baitursynov'];

    private ?array $gl = null;

    /** @var string[] тексты абзацев последней проверки — для сверки с оригиналом */
    private array $blocks = [];

    private array $o;

    public function __construct(array $options = [])
    {
        $this->o = array_replace(self::DEFAULTS, array_intersect_key($options, self::DEFAULTS));

        if ($this->o['glossary'] === '') {
            $this->o['glossary'] = \dirname(__DIR__, 3) . '/data/kk-glossary.json';
        }
    }

    /** Глоссарий казахской Редколлегии (T01, T02, PR06); пустой, если выключен или файла нет. */
    public function glossary(): array
    {
        if ($this->gl === null) {
            $this->gl = [];

            if ($this->o['kk_glossary'] && is_file($this->o['glossary'])) {
                $this->gl = (array) json_decode((string) file_get_contents($this->o['glossary']), true);
            }
        }

        return $this->gl;
    }

    /** Находки по тексту (разметка или строка). */
    public function check(string $input, string $lang = '', bool $html = true): array
    {
        $lang = Engine::language($lang, $input);

        if ($lang === '') {
            return [];
        }

        $found        = [];
        $block        = 0;
        $this->blocks = [];
        $fn           = function (string $text) use (&$found, &$block, $lang): string {
            array_push($found, ...$this->block($text, $block, $lang));
            $this->blocks[] = $text;
            $block++;

            return $text;
        };

        $html ? (new Html())->process($input, $fn) : self::plain($input, $fn);

        if ($lang === 'kk' && \is_array($this->o['original']) && $this->o['original']) {
            array_push($found, ...$this->parity($this->o['original']));
        }

        if ($html && \in_array('struct', (array) $this->o['house'], true)) {
            array_push($found, ...$this->structure($input));
        }

        return $found;
    }

    /**
     * Заголовок материала: длина (до 80 знаков), кавычки, относительное время, «город». Находки — к сведению, без правки:
     * заголовок перестраивают целиком. Блок -1 — это поле «Заголовок», а не текст.
     */
    public function title(string $title, string $lang = ''): array
    {
        $title = trim($title);

        if ($title === '' || !\in_array('title', (array) $this->o['house'], true) || Engine::language($lang, $title) !== 'ru') {
            return [];
        }

        $out  = [];
        $add  = function (string $rule, string $weight, array $args = []) use (&$out, $title): void {
            $out[] = ['id' => 'title-' . $rule, 'block' => -1, 'offset' => 0, 'quote' => $title, 'edit' => null, 'rule' => $rule, 'variant' => '',
                'args' => $args, 'layer' => 'title', 'weight' => $weight, 'auto' => false, 'cluster' => false, 'context' => ['', $title, '']];
        };
        $len = mb_strlen($title);

        if ($len > 80) {
            $add('T-LEN', 'warn', [(string) $len]);
        }

        if (preg_match('/[«»"„“]/u', $title)) {
            $add('T-QUOTES', 'warn');
        }

        if (preg_match('/\b(?:[Сс]егодня|[Вв]чера|[Зз]автра|[Пп]ослезавтра|[Пп]озавчера)\b/u', $title)) {
            $add('T-REL', 'warn');
        }

        if (preg_match('/\b(?:[Гг]ород(?:а|е|у|ом)?|г\.)[\x{20}\x{A0}]+\p{Lu}/u', $title)) {
            $add('T-CITY', 'info');
        }

        return $out;
    }

    /**
     * Строение текста по правилам редакции: лид жирным (без «передает сайт»), первое упоминание сайта — ссылкой, «напомним»
     * без ссылки, две цитаты подряд, текст не кончается цитатой, у снимка подпись с источником, H1 и пропуск уровня заголовков.
     */
    private function structure(string $html): array
    {
        $out  = [];
        $add  = function (string $rule, string $weight, int $block, string $quote) use (&$out): void {
            $text  = $this->blocks[$block] ?? '';
            $out[] = ['id' => 'html-' . $rule . '-' . $block . '-' . \count($out), 'block' => $block, 'offset' => 0, 'quote' => $quote, 'edit' => null,
                'rule' => $rule, 'variant' => '', 'args' => [], 'layer' => 'house', 'weight' => $weight, 'auto' => false, 'cluster' => false,
                'context' => ['', $quote !== '' ? $quote : mb_substr($text, 0, 120), ''],
            ];
        };

        // лид: первый абзац; жирным должна быть большая его часть, а «передает сайт» — нет
        if (preg_match('~<p\b[^>]*>(.*?)</p>~is', $html, $p)) {
            $plain = trim(html_entity_decode(strip_tags($p[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            preg_match_all('~<(strong|b)\b[^>]*>(.*?)</\1>~is', $p[1], $bm);
            $bold  = trim(html_entity_decode(strip_tags(implode(' ', $bm[2] ?? [])), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $site  = preg_quote((string) $this->o['site'], '~');
            $credit = '(?:переда[её]т|сообща[её]т|пишет|в материале)[\s\x{A0}]+' . ($site !== '' ? $site : '[\w.-]+\.kz');

            if ($plain !== '' && mb_strlen($bold) < 0.6 * mb_strlen(preg_replace('~,?[\s\x{A0}]*' . $credit . '\.?~iu', '', $plain) ?? $plain)) {
                $add('R-LEAD', 'warn', 0, mb_substr($plain, 0, 120));
            } elseif ($bold !== '' && preg_match('~' . $credit . '~iu', $bold)) {
                $add('R-LEAD-CREDIT', 'warn', 0, mb_substr($bold, -60));
            }
        }

        // первое упоминание сайта — ссылкой
        if ((string) $this->o['site'] !== '') {
            $site = preg_quote((string) $this->o['site'], '~');

            if (preg_match('~' . $site . '~iu', strip_tags($html)) && preg_match('~^(.*?)' . $site . '~isu', $html, $first)) {
                $opens  = preg_match_all('~<a\b~i', $first[1]);
                $closes = preg_match_all('~</a>~i', $first[1]);

                if ($opens <= $closes) {
                    $add('R-SITELINK', 'warn', 0, (string) $this->o['site']);
                }
            }
        }

        // «напомним» ссылкой
        if (preg_match('~<a\b[^>]*>[\s\x{A0}]*[Нн]апомним[\s\x{A0},]*</a>~u', $html)) {
            $add('R-NAPOMNIM-LINK', 'warn', 0, 'Напомним');
        }

        // снимок без подписи с источником
        $credits = '(?:Фото|Иллюстрация|Инфографика|Скриншот|Кадр|Изображение|Коллаж|Источник)[\s\x{A0}]*:';

        foreach (preg_split('~(?=<figure\b)|(?<=</figure>)~i', $html) ?: [] as $chunk) {
            if (stripos($chunk, '<figure') === 0) {
                if (stripos($chunk, '<img') !== false && stripos($chunk, 'data-wm-video') === false
                    && !preg_match('~<figcaption\b[^>]*>.*?' . $credits . '~isu', $chunk)) {
                    $add('R-PHOTO', 'warn', 0, trim(html_entity_decode(strip_tags($chunk), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: '<img>');
                }
            } elseif (preg_match_all('~<img\b[^>]*>~i', $chunk, $imgs)) {
                foreach ($imgs[0] as $img) {
                    preg_match('~alt\s*=\s*"([^"]*)"~i', $img, $alt);
                    $add('R-PHOTO', 'warn', 0, ($alt[1] ?? '') !== '' ? html_entity_decode($alt[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '<img>');
                }
            }
        }

        // заголовки: H1 в тексте и пропуск уровня
        if (preg_match_all('~<h([1-6])\b[^>]*>(.*?)</h\1>~is', $html, $hm, PREG_SET_ORDER)) {
            $prev = 1;

            foreach ($hm as $h) {
                $level = (int) $h[1];
                $label = trim(html_entity_decode(strip_tags($h[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                if ($level === 1) {
                    $add('R-H1', 'warn', 0, $label);
                } elseif ($level > $prev + 1) {
                    $add('R-HSKIP', 'info', 0, $label);
                }

                $prev = $level;
            }
        }

        // цитаты: две подряд и цитата в конце текста
        $isQuote = static fn (string $t): bool => (bool) preg_match('/^[«„"]|^—[\x{20}\x{A0}]/u', trim($t));
        $last    = \count($this->blocks) - 1;

        foreach ($this->blocks as $i => $t) {
            if ($i > 0 && $isQuote($t) && $isQuote($this->blocks[$i - 1])) {
                $add('R-QUOTES2', 'warn', $i, mb_substr(trim($t), 0, 100));
            }
        }

        if ($last >= 1 && $isQuote($this->blocks[$last])) {
            $add('R-QUOTE-END', 'warn', $last, mb_substr(trim($this->blocks[$last]), 0, 100));
        }

        return $out;
    }

    /** Начало предложения: перед словом — начало текста или конец предложения (через кавычку, скобку, тире, двоеточие). */
    private static function sentenceStart(string $text, int $start): bool
    {
        $from   = max(0, $start - 12);
        $window = mb_substr($text, $from, $start - $from);
        $before = preg_replace('/[\s\x{A0}«„"(—–\-]+$/u', '', $window) ?? '';

        // начало строки — тоже начало: подзаголовок без точки, а за ним новая строка
        return ($before === '' && $from === 0) || (bool) preg_match('/[.!?…:]$/u', $before) || (bool) preg_match('/\n[\s\x{A0}«„"(—–\-]*$/u', $window);
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

            if ($this->o['house']) {
                $rules = array_merge($rules, HouseRules::rules((array) $this->o['house']));
            }
        }

        $quotes = self::quoteSpans($text);
        $found  = $lang === 'kk' && $this->o['kk_lint'] ? $this->kkLint($text, $block) : [];

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

                // правила редакции: где правило не действует
                if ((\in_array('midsentence', $flags, true) && self::sentenceStart($text, $start))
                    || (\in_array('not_after_admin', $flags, true) && HouseRules::afterAdmin($text, $start))
                    || (\in_array('passive', $flags, true) && !HouseRules::isParticiple($groups[1] ?? ''))
                    || (\in_array('tech_context', $flags, true) && !HouseRules::techContext($text, $start))) {
                    continue;
                }

                $agree = array_values(array_intersect(['agree_mag', 'agree_unit', 'agree_addr'], $flags))[0] ?? null;
                $new   = match (true) {
                    $agree !== null     => HouseRules::expand($agree, $text, $start, $groups),
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

    /** Находки kk_lint (Алтынсарин, Шакарим, Байтурсынов): fix/apply — правка, suggest — правка на решение, flag — к сведению. */
    private function kkLint(string $text, int $block): array
    {
        $out = [];

        foreach ((new KkLint())->spans($text, $this->glossary()) as $sp) {
            $rule  = $sp['rule'];
            $layer = self::KK_LAYER[$rule] ?? self::KK_LAYER[$rule[0]] ?? 'altynsarin';
            $edit  = $sp['action'] === 'flag' ? null : $sp['edit'];
            $quote = mb_substr($text, $sp['s'], $sp['e'] - $sp['s']);

            if ($quote === '' || $edit === $quote) {
                continue;
            }

            $a       = $sp['args'];
            $variant = match ($rule) {
                'T01'   => !empty($a['affix']) ? 'AFFIX' : '',
                'T02'   => strtoupper((string) ($a['variant'] ?? '')),
                'P01'   => ($a['kind'] ?? null) === 'junction' ? 'JUNCTION' : '',
                'P03'   => !empty($a['straight']) ? 'STRAIGHT' : '',
                default => '',
            };
            $why  = isset($a['why']) && \is_array($a['why']) ? vsprintf('%s %d, %s %d', $a['why']) : '';
            $args = match ($rule) {
                'T01'   => [$a['kk'] ?? '', $a['ru'] ?? ''],
                'T02'   => [$a['bad'] ?? '', $a['kk'] ?? '', $why],
                'P03'   => !empty($a['straight']) ? [] : [(string) ($a['open'] ?? 0), (string) ($a['close'] ?? 0)],
                default => [],
            };

            $out[] = [
                'id'      => 'b' . $block . '-' . $sp['s'] . '-' . $rule,
                'block'   => $block,
                'offset'  => $sp['s'],
                'quote'   => $quote,
                'edit'    => $edit,
                'rule'    => $rule,
                'variant' => $variant,
                'args'    => $args,
                'layer'   => $layer,
                'weight'  => $edit !== null && $sp['severity'] === 'fix' ? 'fix' : ($sp['severity'] === 'author_choice' ? ($edit !== null ? 'warn' : 'info') : 'warn'),
                'auto'    => $edit !== null && $sp['severity'] === 'fix' && $sp['action'] === 'apply',
                'cluster' => false,
                'context' => self::context($text, $sp['s'], $sp['e']),
            ];
        }

        // S01 — модель букв: только подсказки, решает читающий по-казахски
        if ($this->o['kk_restore'] && ($r = KkRestore::shared(\dirname($this->o['glossary'])))) {
            foreach ($r->spans($text, $this->glossaryWords()) as $sp) {
                $quote = mb_substr($text, $sp['s'], $sp['e'] - $sp['s']);
                $out[] = [
                    'id' => 'b' . $block . '-' . $sp['s'] . '-S01', 'block' => $block, 'offset' => $sp['s'], 'quote' => $quote, 'edit' => $sp['edit'],
                    'rule' => 'S01', 'variant' => $sp['known'] ? '' : 'UNKNOWN', 'args' => [$quote, $sp['edit']], 'layer' => 'baitursynov',
                    'weight' => 'warn', 'auto' => false, 'cluster' => false, 'context' => self::context($text, $sp['s'], $sp['e']),
                ];
            }
        }

        return $out;
    }

    /** Слова казахских форм глоссария: S01 их не трогает (как gl_words в kk_lint). */
    private function glossaryWords(): array
    {
        $words = [];

        foreach ($this->glossary()['entries'] ?? [] as $e) {
            if (!empty($e['kk']) && preg_match_all('/[' . KkLint::CYRL . ']+/u', (string) $e['kk'], $m)) {
                foreach ($m[0] as $w) {
                    $words[mb_strtolower($w)] = true;
                }
            }
        }

        return array_keys($words);
    }

    /** Валиханов: казахская версия против русского оригинала — числа, латинские имена, термины глоссария. */
    private function parity(array $original): array
    {
        $out = [];

        foreach (KkParity::compare($original, $this->blocks, $this->glossary()) as $n => $f) {
            $block = min($f['para'], max(0, \count($this->blocks) - 1));
            $text  = $this->blocks[$block] ?? '';
            $args  = match ($f['rule']) {
                'PR01'  => array_map('strval', $f['counts']),
                'PR02'  => [implode(', ', $f['lost']), implode(', ', $f['extra'])],
                'PR03'  => [implode(', ', $f['lost'])],
                'PR04'  => [implode(', ', $f['extra'])],
                'PR05'  => [implode(', ', $f['latin'])],
                'PR06'  => [$f['ru'], $f['kk']],
                default => [],
            };

            $out[] = [
                'id' => 'p' . $n . '-' . $f['rule'], 'block' => $block, 'offset' => 0, 'quote' => mb_substr($text, 0, 80), 'edit' => null,
                'rule' => $f['rule'], 'variant' => '', 'args' => $args, 'layer' => 'valikhanov',
                'weight' => $f['rule'] === 'PR02' ? 'fix' : ($f['rule'] === 'PR03' || $f['rule'] === 'PR05' ? 'warn' : 'info'),
                'auto' => false, 'cluster' => false, 'context' => self::context($text, 0, 0, 160),
            ];
        }

        return $out;
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

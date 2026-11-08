<?php

/**
 * @package     Webmarka.Redkollegiya
 * @subpackage  com_wmarka_redkollegiya
 * @copyright   (C) 2026 Beibit Sakhanov
 * @license     GNU General Public License version 3 or later; see LICENSE
 */

namespace Webmarka\Component\Redkollegiya\Administrator\Engine;

\defined('_JEXEC') or die;

/**
 * Правила издания поверх Мильчина и kk_typo. Мильчин сознательно не ставит минус и десятичную запятую: в общем виде
 * дефис не отличить от минуса, а точку — от версии или даты. Здесь они делаются только там, где ошибки быть не может:
 * минус — перед числом в начале фразы или после пробела, скобки, кавычки (слева не число); запятая — в числе, за которым
 * стоит единица («1.5 млрд», «3.2%», «в 2.5 раза»), но не в «Joomla 6.2», «п. 3.2», «06.11.2026».
 */
final class House
{
    private const NB = "\u{00A0}";

    /** Слова и знаки после числа, при которых точка в нём — десятичная. */
    private const DEC_RU = '%|‰|°|₸|\$|€|₽|млн|млрд|трлн|тыс|раз(?:а|ы)?(?![а-яё])|процент|пункт|п\.[\x{20}\x{A0}]?п\.|тенге|тг(?![а-яё])|долл|руб|евро|юан|баррел|кубометр|тонн|т(?![а-яё.])|кг(?![а-яё])|км(?![а-яё])|га(?![а-яё])|м³|кВт|МВт|ГВт|л(?![а-яё.])';

    private const DEC_KK = '%|‰|°|₸|\$|€|₽|млн|млрд|трлн|мың|есе|пайыз|пункт|теңге|тг(?![а-яәғқңөұүһі])|доллар|евро|юань|баррель|тонна|кг|км|га|кВт|МВт|ГВт';

    private const PREP_SHORT = 'во|ко|со|об|обо|но|да|не|ни|на|по|за|из|от|до|для|при|про|без|под|над';

    /** @var string[] */
    private array $store = [];

    /**
     * @param  array{en_dash_words?: bool, minus?: bool, decimal_comma?: bool, mln_period?: bool, prepositions?: string,
     *               units?: string[], range_dash?: string, currency_before?: bool}  $o
     */
    public function apply(string $text, string $lang, array $o, Counter $c, ?Yo $yo = null, string $yoMode = 'off', bool $yoNames = false): string
    {
        $this->store = [];
        $text        = $this->mask($text);
        $letters     = $lang === 'kk' ? 'А-Яа-яЁёӘәҒғҚқҢңӨөҰұҮүҺһІі' : 'А-Яа-яЁё';

        // реплика или пункт в начале абзаца: «- Привет» → «— Привет»; минус («-5%») сюда не попадает — после знака нужен пробел
        $text = $this->sub('/^([\x{20}\t]*)(?:(?:-{1,3}|–)[\x{20}\x{A0}]+|—\x{20}+)(?=[' . $letters . 'A-Za-z«„"(\d])/mu', '$1—' . self::NB, $text, $c, 'H-DASH');

        // тире после знака препинания: «Сәлем, - деді ол», «Привет, – сказал он», «Кто? - спросил» → «, — деді»;
        // Мильчин делает это только для дефиса, kk_typo — ни для чего: слева от тире он ждёт букву, цифру или кавычку
        $text = $this->sub('/(?<=[,.!?…;:])[\x{20}\x{A0}]+(?:-{1,3}|–)(?=[\x{20}\x{A0}]+[' . $letters . 'A-Za-z«„"(\d])/u', self::NB . '—', $text, $c, 'H-DASH');

        // короткое тире между словами → длинное (в казахском это уже делает Y02)
        if ($lang === 'ru' && ($o['en_dash_words'] ?? true)) {
            $text = $this->enDashWords($text, $letters, $c);
        }

        if ($o['minus'] ?? true) {
            $text = $this->minus($text, $c);
        }

        if ($o['decimal_comma'] ?? true) {
            $units = $lang === 'kk' ? self::DEC_KK : self::DEC_RU;
            $text  = $this->sub('/(?<![\d.,\w])(\d+)\.(\d+)(?![\d]|[.,]\d)(?=[\x{20}\x{A0}]?(?:' . $units . '))/u', '$1,$2', $text, $c, 'H-DEC');
        }

        if ($o['time_colon'] ?? false) {
            $text = $this->timeColon($text, $c);
        }

        if ($lang === 'ru') {
            if ($o['expand_abbr'] ?? false) {
                $text = $this->expandAbbr($text, $c);
            }

            if ($o['mln_period'] ?? true) {
                // «5 млн. тенге» → «5 млн тенге»; перед заглавной (конец предложения) точка остаётся
                $text = $this->sub('/\b(млн|млрд|трлн)\.(?=[\x{20}\x{A0}]*(?:[а-яё0-9,;:)»—–-]))/u', '$1', $text, $c, 'H-MLN');
            }

            // «5 млн тенге», «2 млрд долларов»: счётное слово не отрывается от единицы
            $text = $this->sub('/\b(тыс\.|млн|млрд|трлн)\x{20}(?=₸|\$|€|тенге|тг|долл|руб|евро|юан|баррел|тонн|кубометр)/u', '$1' . self::NB, $text, $c, 'H-UNIT');

            // «0,25 п. п.» — процентные пункты с неразрывными пробелами
            $text = $this->sub('/(\d)[\x{20}\x{A0}]?п\.[\x{20}\x{A0}]?п\./u', '$1' . self::NB . 'п.' . self::NB . 'п.', $text, $c, 'H-UNIT');

            foreach ($o['units'] ?? [] as $u) {
                $u = trim((string) $u);

                if ($u !== '') {
                    $text = $this->sub('/(\d)\x{20}(' . preg_quote($u, '/') . ')(?![\w])/u', '$1' . self::NB . '$2', $text, $c, 'H-UNIT');
                }
            }

            if (($o['prepositions'] ?? 'one') === 'short') {
                $text = $this->sub('/(?<![^\s(«„])(' . self::PREP_SHORT . ')\x{20}/iu', '$1' . self::NB, $text, $c, 'H-PREP');
                $text = $this->sub('/(?<=\S)\x{20}(же|ли|бы)(?=[\s.,!?;:»)]|$)/u', self::NB . '$1', $text, $c, 'H-PREP');
            }

            if ($yo && $yoMode === 'restore') {
                $text = $yo->restore($text, $c, $yoNames);
            } elseif ($yo && $yoMode === 'remove') {
                $text = $yo->remove($text, $c);
            }
        } else {
            // знак тенге после числа: «500 ₸»
            $text = $this->sub('/(\d)\x{20}(₸)/u', '$1' . self::NB . '$2', $text, $c, 'H-UNIT');
        }

        $text = $this->currency($text, (bool) ($o['currency_before'] ?? false), $c);

        if (($o['nested_quotes'] ?? 'ldquo') === 'guillemets') {
            // только «ёлочки», и внутри тоже: «Компания „Самрук“» → «Компания «Самрук»»
            $text = $this->sub('/„/u', '«', $text, $c, 'H-QUOTES');
            $text = $this->sub('/“/u', '»', $text, $c, 'H-QUOTES');
        }

        if ($o['speech_comma'] ?? true) {
            // прямая речь: после «?», «!», «…» запятой перед тире нет; точка перед авторскими словами становится запятой за кавычкой
            $text = $this->sub('/([?!…])»,([\x{20}\x{A0}]*—)/u', '$1»$2', $text, $c, 'H-SPEECH');
            $text = $this->sub('/(?<![.?!…])\.»,?([\x{20}\x{A0}]*—[\x{20}\x{A0}]*\p{Ll})/u', '»,$1', $text, $c, 'H-SPEECH');
        }

        if (($n = (int) ($o['thousands'] ?? 0)) >= 4) {
            $text = $this->thousands($text, $n, $c);
        }

        if (($o['range_dash'] ?? 'en') === 'em') {
            $text = $this->sub('/(\d)–(\d)/u', '$1—$2', $text, $c, 'H-RANGE');
        }

        return $this->unmask($text);
    }

    /**
     * Знак валюты перед числом пишется слитно: «₸ 8,5 млрд» → «₸8,5 млрд», «$ 5» → «$5» (если слева от знака не число:
     * в «500 ₸ 20 штук» знак принадлежит первому числу). С настройкой currency_before знак ₸ и $ после числа переносится
     * вперёд, как требует новостной стиль: «500 ₸» → «₸500», «5,2 млрд $» → «$5,2 млрд».
     */
    private function currency(string $text, bool $before, Counter $c): string
    {
        $n = 0;

        if ($before) {
            $out = preg_replace_callback(
                '/(?<![\d.,\w])(\d{1,3}(?:[\x{20}\x{A0}]\d{3})+|\d+)((?:[.,]\d+)?)(?:[\x{20}\x{A0}](тыс\.|млн|млрд|трлн|мың))?[\x{20}\x{A0}]?(₸|\$)(?![\w])/u',
                static function (array $m) use (&$n): string {
                    $n++;

                    return $m[4] . $m[1] . $m[2] . ($m[3] !== '' ? self::NB . $m[3] : '');
                },
                $text
            );
            $text = $out ?? $text;
        }

        if (preg_match_all('/([₸$€£¥₽])[\x{20}\x{A0}]+(?=\d)/u', $text, $mm, PREG_OFFSET_CAPTURE)) {
            $res = '';
            $pos = 0;

            foreach ($mm[0] as $k => [$s, $o]) {
                $before = rtrim(substr($text, 0, $o), " \t" . self::NB);

                if ($before !== '' && preg_match('/\d$/u', $before)) {
                    continue;
                }

                $res .= substr($text, $pos, $o - $pos) . $mm[1][$k][0];
                $pos  = $o + \strlen($s);
                $n++;
            }

            $text = $res . substr($text, $pos);
        }

        $c->add('H-CUR', $n);

        return $text;
    }

    /**
     * «и т. д.» → «и так далее», «и т. п.» → «и тому подобное», «т. е.» → «то есть», «в т. ч.» → «в том числе».
     * Точка сокращения, на которой кончается предложение, остаётся точкой предложения: «…и т. д. Затем» → «…и так далее. Затем».
     */
    private function expandAbbr(string $text, Counter $c): string
    {
        $s = '[\x{20}\x{A0}]*';
        $n = 0;

        foreach ([
            ['/\b([Ии])[\x{20}\x{A0}]+т\.' . $s . 'д\./u', 'так далее'],
            ['/\b([Ии])[\x{20}\x{A0}]+т\.' . $s . 'п\./u', 'тому подобное'],
            ['/\b([Вв])[\x{20}\x{A0}]+т\.' . $s . 'ч\./u', 'том числе'],
        ] as [$re, $words]) {
            $text = preg_replace_callback($re, function (array $m) use ($words, &$n): string {
                $n++;

                return $m[1] . self::NB . $words . "\u{E006}";
            }, $text) ?? $text;
        }

        $text = preg_replace_callback('/\b([Тт])\.' . $s . 'е\./u', function (array $m) use (&$n): string {
            $n++;

            return ($m[1] === 'Т' ? 'То' : 'то') . ' есть' . "\u{E006}";
        }, $text) ?? $text;

        // метка на месте прежней точки: перед заглавной или в конце текста точка нужна, иначе — нет
        $text = preg_replace('/\x{E006}(?=[\x{20}\x{A0}]+[«„"(]?\p{Lu}|[\x{20}\x{A0}]*$)/u', '.', $text) ?? $text;
        $text = str_replace("\u{E006}", '', $text);
        $c->add('H-ABBR', $n);

        return $text;
    }

    /**
     * Время через двоеточие: «в 15.00» → «в 15:00», «с 9.00 до 18.00», «15-00 часов». Только после «в», «с», «до», «к», «около»,
     * «после», «от» и только если дальше конец фразы, знак или слово времени. «12.05» с минутами от 01 до 12 похоже на дату —
     * его правим, лишь когда рядом прямо сказано «часов», «утра» или «вечера».
     */
    private function timeColon(string $text, Counter $c): string
    {
        $n    = 0;
        $word = '(?:ч|час\w*|утра|вечера|дня|ночи|мск|по\s+(?:местному|астанинскому|алматинскому|времени)\w*)(?![\p{L}])';
        $re   = '/\b([Вв]|[Сс]о?|[Дд]о|[Кк]о?|[Оо]коло|[Пп]осле|[Оо]т)([\x{20}\x{A0}]+)([01]?\d|2[0-3])[.\-–]([0-5]\d)(?![\d]|[.,:]\d)'
            . '(?=([\x{20}\x{A0}]*' . $word . ')|[\x{20}\x{A0}]*(?:$|[.,;:!?)»—–]|(?:и|до|в|на|с)(?![\p{L}])))/u';

        $text = preg_replace_callback($re, static function (array $m) use (&$n): string {
            $min = (int) $m[4];

            if ($min >= 1 && $min <= 12 && empty($m[5])) {
                return $m[0];
            }

            $n++;

            return $m[1] . $m[2] . $m[3] . ':' . $m[4];
        }, $text) ?? $text;

        $c->add('H-TIME', $n);

        return $text;
    }

    /**
     * Разряды: «56347» → «56 347» (неразрывный пробел), у дробей — целая часть. Не трогаются номера, телефоны, коды и индексы:
     * число с нулём впереди, после «№», «ИИН», «БИН», «ЖСН», «БСН», «тел.», «индекс», «счёт», «код», «IBAN», и номера телефонов из 10–11 цифр.
     */
    private function thousands(string $text, int $from, Counter $c): string
    {
        $n  = 0;
        $re = '/(?<![\d.,\w№\/:+\-–])(\d{' . $from . ',15})(?=(?:[.,]\d+)?(?![\d\w]))/u';

        if (!preg_match_all($re, $text, $mm, PREG_OFFSET_CAPTURE)) {
            return $text;
        }

        $res = '';
        $pos = 0;

        foreach ($mm[1] as [$num, $o]) {
            $before = mb_substr(substr($text, 0, $o), -16);

            if ($num[0] === '0' || (\strlen($num) >= 10 && \strlen($num) <= 11 && ($num[0] === '7' || $num[0] === '8'))
                || preg_match('/(?:№|ИИН|БИН|ЖСН|БСН|IBAN|ID|тел\.?|телефон\w*|индекс\w*|сч[её]т\w*|код\w*|номер\w*|артикул\w*)[\s\x{A0}:]*$/iu', $before)) {
                continue;
            }

            $groups = [];

            for ($i = \strlen($num); $i > 0; $i -= 3) {
                array_unshift($groups, substr($num, max(0, $i - 3), $i - max(0, $i - 3)));
            }

            $grouped = implode(self::NB, $groups);
            $res    .= substr($text, $pos, $o - $pos) . $grouped;
            $pos     = $o + \strlen($num);
            $n++;
        }

        $c->add('H-THOUSANDS', $n);

        return $res . substr($text, $pos);
    }

    /** «слово – слово» → «слово — слово» с неразрывным пробелом перед тире; «2020 – 2025» и «5 – 3» не трогаются. */
    private function enDashWords(string $text, string $letters, Counter $c): string
    {
        $n   = 0;
        $re  = '/(?<=[' . $letters . 'A-Za-z»)"”%\d])([ ' . self::NB . ']+)–(?=([ ' . self::NB . ']+)([' . $letters . 'A-Za-z«("“„\d]))/u';

        // по смещениям: для решения нужен символ слева от пробела, а preg_replace_callback его не отдаёт
        if (!preg_match_all($re, $text, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return $text;
        }

        $res = '';
        $pos = 0;

        foreach ($mm as $m) {
            $start = $m[0][1];
            $left  = $start > 0 ? mb_substr(substr($text, 0, $start), -1) : '';
            $right = $m[3][0];

            if (preg_match('/^\d$/u', $left) && preg_match('/^\d$/u', $right)) {
                continue;
            }

            $res .= substr($text, $pos, $start - $pos) . self::NB . '—';
            $pos  = $start + \strlen($m[0][0]);
            $n++;
        }

        $c->add('H-DASH', $n);

        return $res . substr($text, $pos);
    }

    /**
     * Минус «−» вместо дефиса или короткого тире перед числом: «-0,25 п. п.», «до –3%». Только если слева начало строки, пробел,
     * скобка, кавычка или знак «=», «:», а предыдущее слово не число («5 -3» — не минус); телефон и номер («-701-12-12») не трогаются.
     */
    private function minus(string $text, Counter $c): string
    {
        if (!preg_match_all('/(?<=^|[\s(\[«„=:;])[-–](?=\d)/u', $text, $mm, PREG_OFFSET_CAPTURE)) {
            return $text;
        }

        $res = '';
        $pos = 0;
        $n   = 0;

        foreach ($mm[0] as [$s, $o]) {
            $before = rtrim(substr($text, 0, $o), " \t\n\r" . self::NB);
            $after  = substr($text, $o + \strlen($s), 40);

            if (($before !== '' && preg_match('/\d$/u', $before)) || preg_match('/^\d+(?:[-–]\d+)+/u', $after)) {
                continue;
            }

            $res .= substr($text, $pos, $o - $pos) . '−';
            $pos  = $o + \strlen($s);
            $n++;
        }

        $c->add('H-MINUS', $n);

        return $res . substr($text, $pos);
    }

    // ------------------------------------------------------------------ защита: адреса, почта, код, упоминания

    private function mask(string $text): string
    {
        $stash = function (array $m): string {
            $this->store[] = $m[0];

            return "\u{E004}" . (\count($this->store) - 1) . "\u{E005}";
        };

        foreach (['/`[^`\n]+`/u', '~https?://[^\s<>\)\]]+|www\.[^\s<>\)\]]+~u', '/[\w.+-]+@[\w-]+\.[\w.-]+/u', '/(?<!\w)[@#][\w_]+/u'] as $re) {
            $text = preg_replace_callback($re, $stash, $text) ?? $text;
        }

        return $text;
    }

    private function unmask(string $text): string
    {
        return preg_replace_callback('/\x{E004}(\d+)\x{E005}/u', fn ($m) => $this->store[(int) $m[1]] ?? $m[0], $text) ?? $text;
    }

    /** preg_replace, но в счёт идут только настоящие правки: совпадение, которое уже стоит как надо («0,25 п. п.»), не считается. */
    private function sub(string $pattern, string $to, string $text, Counter $c, string $rule): string
    {
        $n   = 0;
        $out = preg_replace_callback($pattern, static function (array $m) use ($to, &$n): string {
            $new = preg_replace_callback('/\$(\d)/', static fn ($g) => $m[(int) $g[1]] ?? '', $to) ?? $to;

            if ($new !== $m[0]) {
                $n++;
            }

            return $new;
        }, $text);

        if ($out === null) {
            return $text;
        }

        $c->add($rule, $n);

        return $out;
    }
}

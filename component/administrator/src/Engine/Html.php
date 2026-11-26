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
 * Типограф по разметке. Теги и атрибуты не трогаются никогда. Текст блока (абзаца, пункта, ячейки, подписи) собирается
 * в одну строку через строчные теги — <a>, <em>, <strong>, <span>…, — чтобы правила видели фразу целиком:
 * в «"<em>Самрук</em>"» кавычки парные, в «в <a>Астане</a>» предлог привязывается к слову за ссылкой.
 * Исправленная строка раскладывается обратно по кусочкам текста между тегами: совпадающие знаки остаются в своём кусочке,
 * заменённые и вставленные — в кусочке, где стоял первый заменённый знак (вставка — за знаком слева).
 *
 * Код, формулы, скрипты, стили и всё внутри элемента с классом «notypo» или атрибутом data-typograph="off" остаются как есть.
 */
final class Html
{
    /** Строчные элементы: текст по обе стороны — одна фраза. Остальные теги (и <br>, <img>) делят текст. */
    private const INLINE = ['a', 'abbr', 'b', 'bdi', 'bdo', 'big', 'cite', 'data', 'del', 'dfn', 'em', 'font', 'i', 'ins', 'label',
        'mark', 'nobr', 'q', 's', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u'];

    private const SKIP = ['script', 'style', 'pre', 'code', 'kbd', 'samp', 'textarea', 'svg', 'math', 'iframe', 'object', 'template', 'noscript', 'var',
        'video', 'audio', 'canvas', 'select'];

    /**
     * Вставки, текст которых не правится: метка встраивания Webmarka Clean и цитаты-заглушки соцсетей (X/Twitter, Instagram,
     * TikTok, Facebook, Threads, Bluesky, Reddit) — до загрузки их сценария это чужой текст, часто не на языке статьи.
     * Плюс ручной выключатель: класс notypo или data-typograph="off".
     */
    private const SKIP_MARK = '/\\bclass\\s*=\\s*["\'][^"\']*\\b(?:notypo|wm-embed|twitter-tweet|twitter-timeline|instagram-media|tiktok-embed|fb-post|fb-video'
        . '|text-post-media|bluesky-embed|reddit-embed-bq)\\b|\\bdata-(?:typograph\\s*=\\s*["\']?off|instgrm-permalink|telegram-post|bluesky-uri|text-post-permalink|wm-embed)\\b/i';

    private const VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];

    /** @var string[] */
    private array $tokens = [];

    /** @var array<int, array{0: int, 1: string}> текущий блок: [номер токена, расшифрованный текст] */
    private array $run = [];

    /** @var callable(string): string */
    private $fn;

    /**
     * @param  callable(string): string  $fn  что сделать с текстом блока
     */
    public function process(string $html, callable $fn): string
    {
        $this->fn     = $fn;
        $this->run    = [];
        // тег — до «>» вне кавычек: в значении атрибута «>» бывает («data-note="a > b"»), и тег не должен рваться на нём
        $this->tokens = preg_split('/(<!--.*?-->|<!\[CDATA\[.*?\]\]>|<[a-zA-Z\/!?](?:[^>"\']|"[^"]*"|\'[^\']*\')*>)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [$html];

        $skipTag   = '';
        $skipDepth = 0;

        foreach ($this->tokens as $i => $tok) {
            $isTag   = $tok[0] === '<' && str_ends_with($tok, '>') && (bool) preg_match('#^<\s*(/)?\s*([a-zA-Z][a-zA-Z0-9:-]*)#', $tok, $m);
            $isOther = !$isTag && $tok[0] === '<' && str_ends_with($tok, '>') && (bool) preg_match('/^<(?:!--|!\[CDATA\[|[!?\/])/', $tok);

            if ($skipDepth > 0) {
                // внутри кода и прочего — ждём закрытия того же элемента
                if ($isTag && strtolower($m[2]) === $skipTag && !str_ends_with($tok, '/>')) {
                    $skipDepth += $m[1] === '/' ? -1 : 1;
                }

                continue;
            }

            if ($isOther) {
                // комментарий, CDATA, объявление <!DOCTYPE> и инструкции обработки делят текст и не правятся
                $this->flush();
                continue;
            }

            if ($isTag) {
                $name  = strtolower($m[2]);
                $close = $m[1] === '/';

                if (!$close && !\in_array($name, self::VOID, true) && !str_ends_with($tok, '/>')
                    && (\in_array($name, self::SKIP, true) || preg_match(self::SKIP_MARK, $tok))) {
                    $this->flush();
                    $skipTag   = $name;
                    $skipDepth = 1;
                    continue;
                }

                if (!\in_array($name, self::INLINE, true)) {
                    $this->flush();
                }

                continue;
            }

            $this->run[] = [$i, html_entity_decode($tok, ENT_QUOTES | ENT_HTML5, 'UTF-8')];
        }

        $this->flush();

        return implode('', $this->tokens);
    }

    private function flush(): void
    {
        $run       = $this->run;
        $this->run = [];

        if (!$run) {
            return;
        }

        $plain = implode('', array_column($run, 1));

        if (preg_match('/^[\s\x{A0}]*$/u', $plain)) {
            return;
        }

        // пробелы по краям блока значимы: за ними может стоять код или снимок, и «Команда <code>» не должна стать «Команда<code>»
        preg_match('/^([\s\x{A0}]*)(.*?)([\s\x{A0}]*)$/su', $plain, $m);
        $out = $m[1] . ($this->fn)($m[2]) . $m[3];

        if ($out === $plain) {
            return;
        }

        foreach (self::distribute($run, $out) as $k => $text) {
            if ($text !== $run[$k][1]) {
                $this->tokens[$run[$k][0]] = self::encode($text);
            }
        }
    }

    /**
     * Раскладка исправленного текста по кусочкам.
     *
     * @param  array<int, array{0: int, 1: string}>  $run
     * @return string[]  новый текст каждого кусочка
     */
    public static function distribute(array $run, string $out): array
    {
        $a     = [];
        $owner = [];

        foreach ($run as $k => [, $text]) {
            foreach (mb_str_split($text) as $ch) {
                $a[]     = $ch;
                $owner[] = $k;
            }
        }

        $b     = mb_str_split($out);
        $parts = array_fill(0, \count($run), '');

        foreach (self::align($a, $b) as [$i1, $i2, $j1, $j2]) {
            $chunk = implode('', \array_slice($b, $j1, $j2 - $j1));

            if ($i2 > $i1) {
                // совпадение или замена: если совпадение, знаки идут к своим владельцам по одному
                if ($i2 - $i1 === $j2 - $j1) {
                    // совпадение или замена знак в знак (пробел → неразрывный, дефис → тире, кавычки): каждый знак — своему владельцу.
                    // Раньше замена целиком уходила первому кусочку, и предлог с неразрывным пробелом уводил слово из выделения:
                    // «и <i>слово</i> — текст» → «и слово <i></i>— текст» (до 0.5.3)
                    for ($x = 0; $x < $i2 - $i1; $x++) {
                        $parts[$owner[$i1 + $x]] .= $b[$j1 + $x];
                    }
                } else {
                    foreach (self::spread(\array_slice($a, $i1, $i2 - $i1), \array_slice($owner, $i1, $i2 - $i1), \array_slice($b, $j1, $j2 - $j1)) as $k => $text) {
                        $parts[$k] .= $text;
                    }
                }
            } elseif ($chunk !== '') {
                // вставка: к знаку слева, а в самом начале — к первому кусочку
                $parts[$i1 > 0 ? $owner[$i1 - 1] : 0] .= $chunk;
            }
        }

        return $parts;
    }

    /**
     * Замена разной длины («...» → «…», «млн.» → «млн», разряды): общие знаки старого и нового куска (наибольшая общая
     * подпоследовательность) остаются у своих владельцев, новые знаки — у владельца знака слева (в начале — справа).
     * Так выделение, ссылка и прочий строчный тег не теряют своё слово, даже когда правка задела его край.
     * Длинный кусок (больше 160 тысяч сравнений) — по-прежнему целиком первому владельцу.
     *
     * @param  string[]  $a      старые знаки
     * @param  int[]     $owner  владелец каждого старого знака
     * @param  string[]  $b      новые знаки
     * @return array<int, string>  владелец => его доля нового текста, по порядку
     */
    private static function spread(array $a, array $owner, array $b): array
    {
        $n = \count($a);
        $m = \count($b);

        if ($n === 0 || $n * $m > 160000) {
            return [$owner[0] ?? 0 => implode('', $b)];
        }

        // таблица длин общей подпоследовательности с конца
        $L = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));

        for ($x = $n - 1; $x >= 0; $x--) {
            for ($y = $m - 1; $y >= 0; $y--) {
                $L[$x][$y] = $a[$x] === $b[$y] ? $L[$x + 1][$y + 1] + 1 : max($L[$x + 1][$y], $L[$x][$y + 1]);
            }
        }

        $out  = [];
        $last = null;           // владелец последнего прошедшего старого знака
        $lead = '';             // новые знаки до первого старого — уйдут его владельцу
        $put  = static function (int $k, string $ch) use (&$out): void {
            $out[$k] = ($out[$k] ?? '') . $ch;
        };
        $x = 0;
        $y = 0;

        while ($x < $n || $y < $m) {
            if ($x < $n && $y < $m && $a[$x] === $b[$y]) {
                if ($lead !== '') {
                    $put($owner[$x], $lead);
                    $lead = '';
                }

                $put($owner[$x], $b[$y]);
                $last = $owner[$x];
                $x++;
                $y++;
            } elseif ($y < $m && ($x >= $n || $L[$x][$y + 1] >= $L[$x + 1][$y])) {
                // новый знак
                if ($last === null) {
                    $lead .= $b[$y];
                } else {
                    $put($last, $b[$y]);
                }

                $y++;
            } else {
                // старый знак удалён: его место в куске занимает владелец
                $last = $owner[$x];

                if ($lead !== '') {
                    $put($last, $lead);
                    $lead = '';
                }

                $x++;
            }
        }

        if ($lead !== '') {
            $put($owner[0], $lead);
        }

        ksort($out);

        return $out;
    }

    /**
     * Выравнивание двух строк знаков: последовательность [i1, i2, j1, j2] — кусок a[i1:i2] стал b[j1:j2].
     * Правки типографа короткие и редкие, поэтому хватает жадного прохода с ресинхронизацией по окну:
     * при расхождении ищется ближайшее место, где K знаков подряд снова совпадают. Текст на выходе — ровно b при любом исходе;
     * от выравнивания зависит только то, по какую сторону тега окажется заменённый знак.
     *
     * @param  string[]  $a
     * @param  string[]  $b
     * @return array<int, array{0: int, 1: int, 2: int, 3: int}>
     */
    public static function align(array $a, array $b, int $window = 24, int $k = 6): array
    {
        $n   = \count($a);
        $m   = \count($b);
        $i   = 0;
        $j   = 0;
        $ops = [];

        $same = static function (int $x, int $y) use ($a, $b, $n, $m, $k): bool {
            if ($n - $x !== $m - $y && ($n - $x < $k || $m - $y < $k)) {
                return false;
            }

            $len = min($k, $n - $x, $m - $y);

            for ($t = 0; $t < $len; $t++) {
                if ($a[$x + $t] !== $b[$y + $t]) {
                    return false;
                }
            }

            return true;
        };

        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $si = $i;
                $sj = $j;

                while ($i < $n && $j < $m && $a[$i] === $b[$j]) {
                    $i++;
                    $j++;
                }

                $ops[] = [$si, $i, $sj, $j];
                continue;
            }

            $found = false;

            for ($s = 1; $s <= 2 * $window && !$found; $s++) {
                for ($di = max(0, $s - $window); $di <= min($s, $window); $di++) {
                    $dj = $s - $di;

                    if ($i + $di <= $n && $j + $dj <= $m && $same($i + $di, $j + $dj)) {
                        $ops[] = [$i, $i + $di, $j, $j + $dj];
                        $i    += $di;
                        $j    += $dj;
                        $found = true;
                        break;
                    }
                }
            }

            if (!$found) {
                $ops[] = [$i, $n, $j, $m];
                $i     = $n;
                $j     = $m;
            }
        }

        if ($i < $n || $j < $m) {
            $ops[] = [$i, $n, $j, $m];
        }

        return $ops;
    }

    public static function encode(string $text): string
    {
        return str_replace("\u{00A0}", '&nbsp;', htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8', true));
    }
}

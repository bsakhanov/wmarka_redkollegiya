<?php

/**
 * @package     Webmarka.Redkollegiya
 * @subpackage  com_wmarka_redkollegiya
 * @copyright   (C) 2026 Beibit Sakhanov
 * @license     GNU General Public License version 3 or later; see LICENSE
 *
 * Перенос CharLM из kk_restore.py и правила S01 (d_restore) из kk_lint.py — kk-redkollegiya 0.14.0
 * (github.com/bsakhanov/kk-redkollegiya); словарь — как Dictionary(forms=…) из kk_clean.py, без Hunspell.
 * Модель и список форм — те же файлы, что у kk-redkollegiya 0.14.0: lm-kaznerd.json.gz из её релиза и data/kk_forms_kaznerd.txt.
 * Совпадение с оригиналом — tests/parity/kk_compare.py.
 */

namespace Webmarka\Component\Redkollegiya\Administrator\Engine\Kk;

\defined('_JEXEC') or die;

/**
 * S01: казахские буквы в слове, набранном без них («Казакстан» → «Қазақстан»).
 *
 * Символьная модель — 5-граммы букв внутри слов с «глупым откатом», обученные на открытом корпусе KazNERD (ISSAI, CC BY 4.0):
 * по каждой русской букве перебираются казахские варианты (к → қ, г → ғ, н → ң, а → ә, о → ө, у → ұ/ү, х → һ, и → і),
 * поиском по лучу выбирается самое вероятное слово, и подсказка даётся, только если оно вероятнее исходного (запас Δ ≥ 2).
 *
 * Словарь — открытые списки, как kk_lint --forms: формы KazNERD (не реже двух раз, от трёх букв), засвидетельствованные слова и основы.
 * Слово, известное спискам или узнаваемое как форма известной основы («шығынын»), не трогается; подсказка, слова которой
 * в списках нет, даётся только при Δ ≥ 12. Подсказки никогда не вносятся сами: решает читающий по-казахски.
 */
final class KkRestore
{
    private const SPECIAL = ['к' => 'қ', 'г' => 'ғ', 'н' => 'ң', 'а' => 'ә', 'о' => 'ө', 'у' => 'ұү', 'х' => 'һ', 'и' => 'і', 'э' => 'ә'];

    private const RU_FUNC = ['что', 'как', 'это', 'его', 'все', 'или', 'они', 'если', 'для', 'чтобы', 'только', 'есть', 'так', 'когда', 'быть', 'будет',
        'может', 'можно', 'себя', 'тебя', 'меня', 'этого', 'них', 'нет', 'всех', 'который', 'которые', 'потому', 'чем', 'тоже', 'были', 'было',
        'была', 'этот', 'эта', 'эти', 'при', 'также', 'очень', 'между', 'после', 'перед', 'через', 'без', 'мне', 'нас', 'вас', 'был', 'будут',
        'ещё', 'еще', 'уже', 'даже', 'себе', 'этой', 'этих', 'своей', 'своих'];

    private const MUTATE = ['б' => 'п', 'г' => 'к', 'ғ' => 'қ'];

    private int $n = 5;

    /** @var array<int, array<string, int>> */
    private array $ng = [];

    /** @var array<int, array<string, int>> */
    private array $tot = [];

    /** @var array<string, bool> */
    private array $forms = [];

    /** @var array<string, bool> */
    private array $stems = [];

    /** @var array<string, bool> */
    private array $att = [];

    /** @var array<string, bool> */
    private array $infl = [];

    private static ?self $shared = null;

    public function __construct(string $model, string $forms, string $stems, string $attested)
    {
        $data    = json_decode((string) gzdecode((string) file_get_contents($model)), true);
        $this->n = (int) $data['n'];

        foreach ($data['ng'] as $k => $grams) {
            $this->ng[$k]  = [];
            $this->tot[$k] = [];

            foreach ((array) $grams as $g => $c) {
                $g = (string) $g;
                $this->ng[$k][$g] = (int) $c;
                $ctx = mb_substr($g, 0, -1);
                $this->tot[$k][$ctx] = ($this->tot[$k][$ctx] ?? 0) + (int) $c;
            }
        }

        $this->forms = self::lexicon($forms);
        $this->stems = self::lexicon($stems);
        $this->att   = self::lexicon($attested);
    }

    /** Модель и списки из папки данных компонента; загружаются один раз за запрос. */
    public static function shared(string $dir): ?self
    {
        if (self::$shared === null && is_file($dir . '/kk-lm-kaznerd.json.gz')) {
            self::$shared = new self($dir . '/kk-lm-kaznerd.json.gz', $dir . '/kk-forms-kaznerd.txt', $dir . '/kk-stems.txt', $dir . '/kk-attested.txt');
        }

        return self::$shared;
    }

    private static function lexicon(string $path): array
    {
        $out = [];

        foreach (is_file($path) ? (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [] as $w) {
            $w = trim($w);

            if ($w !== '' && $w[0] !== '#') {
                $out[mb_strtolower($w)] = true;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ модель

    private function logp(string $hist, string $ch): float
    {
        $pen = 0.0;

        for ($k = $this->n; $k >= 1; $k--) {
            $ctx = $k > 1 ? mb_substr($hist, mb_strlen($hist) - ($k - 1)) : '';
            $num = $this->ng[$k][$ctx . $ch] ?? 0;

            if ($num) {
                return $pen + log($num / $this->tot[$k][$ctx]);
            }

            $pen += log(0.4);
        }

        return $pen + log(1e-7);
    }

    public function score(string $word): float
    {
        $hist  = str_repeat('^', $this->n - 1);
        $total = 0.0;

        foreach ([...mb_str_split($word), '$'] as $ch) {
            $total += $this->logp($hist, $ch);
            $hist   = mb_substr($hist . $ch, -($this->n - 1));
        }

        return $total;
    }

    /** @return array<int, array{0: string, 1: float}> варианты по убыванию вероятности */
    public function best(string $word, int $beam = 40): array
    {
        $states = [[str_repeat('^', $this->n - 1), 0.0, '']];

        foreach (mb_str_split(mb_strtolower($word)) as $c) {
            $opts = [$c, ...(isset(self::SPECIAL[$c]) ? mb_str_split(self::SPECIAL[$c]) : [])];
            $new  = [];

            foreach ($states as [$hist, $sc, $out]) {
                foreach ($opts as $ch) {
                    $new[] = [mb_substr($hist . $ch, -($this->n - 1)), $sc + $this->logp($hist, $ch), $out . $ch];
                }
            }

            usort($new, static fn ($a, $b) => $b[1] <=> $a[1]);
            $states = \array_slice($new, 0, $beam);
        }

        $fin = array_map(fn ($s) => [$s[2], $s[1] + $this->logp($s[0], '$')], $states);
        usort($fin, static fn ($a, $b) => $b[1] <=> $a[1]);

        return $fin;
    }

    /** @return array{0: ?string, 1: float} исправленное слово (с регистром исходного) и выигрыш Δ, или [null, Δ] */
    public function suggest(string $word, float $margin = 2.0, int $beam = 40): array
    {
        $low = mb_strtolower($word);

        if (mb_strlen($low) < 3 || !array_filter(mb_str_split($low), static fn ($c) => isset(self::SPECIAL[$c]))) {
            return [null, 0.0];
        }

        [$top, $sc] = $this->best($word, $beam)[0];

        if ($top === $low) {
            return [null, 0.0];
        }

        $delta = $sc - $this->score($low);

        if ($delta < $margin) {
            return [null, $delta];
        }

        $orig = mb_str_split($word);
        $out  = '';

        foreach (mb_str_split($top) as $i => $ch) {
            $o    = $orig[$i] ?? '';
            $out .= $o !== '' && mb_strtoupper($o) === $o && mb_strtolower($o) !== $o ? mb_strtoupper($ch) : $ch;
        }

        return [$out, $delta];
    }

    // ------------------------------------------------------------------ словарь

    public function known(string $w): bool
    {
        $lw = mb_strtolower($w);

        return isset($this->forms[$lw]) || isset($this->att[$lw]);
    }

    /** Правильная форма известной основы (основа от пяти букв): таким словам чужие буквы не подставляются. */
    public function inflectedOk(string $w): bool
    {
        $lw = mb_strtolower($w);

        return $this->infl[$lw] ??= isset($this->att[$lw]) || $this->strip($lw, 5) !== null;
    }

    private function strip(string $w, int $minstem = 5, int $maxending = 14): ?array
    {
        $len = mb_strlen($w);

        if ($len < $minstem + 1) {
            return null;
        }

        for ($k = 1; $k <= min($len - $minstem, $maxending); $k++) {
            $stem  = mb_substr($w, 0, $len - $k);
            $end   = mb_substr($w, $len - $k);
            $cands = [$stem];
            $last  = mb_substr($stem, -1);

            if (isset(self::MUTATE[$last]) && mb_strpos('аәеёиоөұүыіэюя', mb_substr($end, 0, 1)) !== false) {
                $cands[] = mb_substr($stem, 0, -1) . self::MUTATE[$last];
            }

            foreach ($cands as $s) {
                if ((isset($this->stems[$s]) || isset($this->att[$s]) || $this->known($s)) && KkStem::validEnding($s, $end)) {
                    return [$s, $end];
                }
            }
        }

        return null;
    }

    // ------------------------------------------------------------------ правило S01

    /**
     * Подсказки S01 по тексту: места — в знаках.
     *
     * @param  string[]  $glWords  слова казахских форм глоссария: их не трогаем
     * @return array<int, array{s: int, e: int, edit: string, known: bool, delta: float}>
     */
    public function spans(string $t, array $glWords = []): array
    {
        $cyrl  = KkLint::CYRL;
        $chars = mb_str_split($t);
        $n     = \count($chars);
        $ru    = [];
        $pos   = 0;

        // строки на русском (две служебные русские словоформы и ни одной казахской буквы) пропускаются
        foreach (explode("\n", $t) as $line) {
            preg_match_all('/[' . $cyrl . ']+/u', $line, $ws);
            $toks = array_unique(array_map('mb_strtolower', $ws[0]));

            if (\count(array_intersect($toks, self::RU_FUNC)) >= 2 && !preg_match('/[әғқңөұүһіӘҒҚҢӨҰҮҺІ]/u', $line)) {
                $ru[] = [$pos, $pos + mb_strlen($line)];
            }

            $pos += mb_strlen($line) + 1;
        }

        $glWords = array_flip($glWords);
        $out     = [];

        if (!preg_match_all('/[' . $cyrl . ']+(?:-[' . $cyrl . ']+)*/u', $t, $mm, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        foreach ($mm[0] as [$tok, $o]) {
            $s = mb_strlen(substr($t, 0, $o));
            $e = $s + mb_strlen($tok);

            if (mb_strlen($tok) < 3) {
                continue;
            }

            if (($s > 0 && preg_match('/^[A-Za-z0-9]$/', $chars[$s - 1])) || ($e < $n && preg_match('/^[A-Za-z0-9]$/', $chars[$e]))) {
                continue;
            }

            foreach ($ru as [$a, $b]) {
                if ($a <= $s && $s < $b) {
                    continue 2;
                }
            }

            if ((preg_match('/\p{Lu}/u', $tok) && !preg_match('/\p{Ll}/u', $tok)) || isset($glWords[mb_strtolower($tok)]) || $this->known($tok)) {
                continue;
            }

            if (preg_match('/[эцщъьёфвчюяЭЦЩЪЬЁФВЧЮЯ]/u', $tok)) {
                continue;
            }

            if (str_contains($tok, '-') && array_reduce(explode('-', $tok), fn ($ok, $p) => $ok && ($this->known($p) || mb_strlen($p) < 3), true)) {
                continue;
            }

            if (preg_match('/^\p{Lu}/u', $tok) && !$this->sentStart($chars, $s)) {
                continue;
            }

            if ($this->inflectedOk($tok)) {
                continue;
            }

            [$cand, $delta] = $this->suggest($tok, 2.0);

            if ($cand !== null && $this->known($cand)) {
                $out[] = ['s' => $s, 'e' => $e, 'edit' => $cand, 'known' => true, 'delta' => $delta];
            } elseif ($cand !== null && $delta >= 12.0) {
                $out[] = ['s' => $s, 'e' => $e, 'edit' => $cand, 'known' => false, 'delta' => $delta];
            }
        }

        return $out;
    }

    private function sentStart(array $chars, int $s): bool
    {
        $i = $s - 1;

        while ($i >= 0 && str_contains(" \t«\"“([—-", $chars[$i])) {
            $i--;
        }

        return $i < 0 || str_contains("\n.!?…", $chars[$i]);
    }
}

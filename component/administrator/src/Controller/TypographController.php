<?php

/**
 * @package     Webmarka.Redkollegiya
 * @subpackage  com_wmarka_redkollegiya
 * @copyright   (C) 2026 Beibit Sakhanov
 * @license     GNU General Public License version 3 or later; see LICENSE
 */

namespace Webmarka\Component\Redkollegiya\Administrator\Controller;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Session\Session;
use Webmarka\Component\Redkollegiya\Administrator\Engine\Html;
use Webmarka\Component\Redkollegiya\Administrator\Service\Typographer;

\defined('_JEXEC') or die;

/**
 * Прогон текста (JSON): для кнопки «Типограф» в редакторе и для страницы «Проверить текст».
 * Пускает того, кто может писать или править материалы (или управлять компонентом); текст на сервере не хранится.
 */
class TypographController extends BaseController
{
    private const LIMIT = 4194304;

    public function process(): void
    {
        if (!Session::checkToken('post')) {
            $this->json(['ok' => false, 'error' => Text::_('JINVALID_TOKEN')], 403);
        }

        $user = $this->app->getIdentity();

        if (!$user || !($user->authorise('core.manage', 'com_wmarka_redkollegiya') || $user->authorise('core.create', 'com_content')
            || $user->authorise('core.edit', 'com_content') || $user->authorise('core.edit.own', 'com_content'))) {
            $this->json(['ok' => false, 'error' => Text::_('JERROR_ALERTNOAUTHOR')], 403);
        }

        $text = (string) $this->input->post->get('text', '', 'raw');

        if (\strlen($text) > self::LIMIT) {
            $this->json(['ok' => false, 'error' => Text::_('COM_WMARKA_REDKOLLEGIYA_TOO_LONG')], 413);
        }

        $lang   = (string) $this->input->post->getString('lang', '*');
        $html   = (bool) $this->input->post->getInt('html', 1);
        $check  = $this->input->post->getCmd('source', 'button') === 'check';
        $item   = $this->input->post->getInt('item', 0);
        $result = Typographer::run($text, $lang, $html, $check ? '' : 'button', $item);
        $groups = [];

        foreach ($result->groups() as $key => $n) {
            $groups[] = ['key' => $key, 'label' => Text::_('COM_WMARKA_REDKOLLEGIYA_GROUP_' . strtoupper($key)), 'count' => $n];
        }

        $language = Text::_('COM_WMARKA_REDKOLLEGIYA_LANG_' . strtoupper($result->lang));
        $message  = match (true) {
            $result->lang === 'other' => Text::_('COM_WMARKA_REDKOLLEGIYA_MSG_OTHER_LANG'),
            $result->total() === 0    => Text::sprintf('COM_WMARKA_REDKOLLEGIYA_MSG_NOTHING', $language),
            default                   => Text::sprintf('COM_WMARKA_REDKOLLEGIYA_MSG_DONE', Typographer::summary($result), $language),
        };

        $this->json([
            'ok'      => true,
            'text'    => $result->text,
            'lang'    => $result->lang,
            'total'   => $result->total(),
            'groups'  => $groups,
            'stats'   => (object) $result->stats(),
            'message' => $message,
            'marked'  => $check ? self::marked($text, $result->text) : null,
            // на странице проверки — и находки вычитки по уже исправленному тексту: типографика к этому времени сделана
            'findings' => $check ? Typographer::describe(Typographer::proofreader()->check($result->text, $lang, $html)) : null,
        ]);
    }

    /** Исправленный текст с отметками правок для страницы «Проверить текст». */
    private static function marked(string $old, string $new): string
    {
        $a   = mb_str_split($old);
        $b   = mb_str_split($new);
        $out = '';
        $esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $vis = static fn (string $s): string => str_replace("\u{00A0}", '<span class="rk-nb" title="' . $esc(Text::_('COM_WMARKA_REDKOLLEGIYA_NBSP')) . '">·</span>', $esc($s));

        foreach (Html::align($a, $b) as [$i1, $i2, $j1, $j2]) {
            $was = \array_slice($a, $i1, $i2 - $i1);
            $now = \array_slice($b, $j1, $j2 - $j1);

            // выравнивание ядра склеивает близкие правки в один кусок; для показа кусок дробится до отдельных знаков
            foreach (self::refine($was, $now) as [$op, $text, $old]) {
                if ($op === '=') {
                    $out .= $esc($text);
                } elseif ($op === '-') {
                    // убранный пробел виден знаком «␣», иначе зачёркнутый пробел похож на дефис
                    $out .= '<del title="' . $esc(Text::_('COM_WMARKA_REDKOLLEGIYA_REMOVED')) . '">' . str_replace(' ', '␣', $vis($text)) . '</del>';
                } else {
                    $out .= '<ins title="' . $esc(Text::sprintf('COM_WMARKA_REDKOLLEGIYA_WAS', $old === '' ? '—' : str_replace("\u{00A0}", '⍽', $old))) . '">' . $vis($text) . '</ins>';
                }
            }
        }

        return $out;
    }

    /**
     * Наименьшая правка одного куска по общей подпоследовательности: [['=', текст, ''], ['+', новое, было], ['-', убранное, '']].
     * Куски короткие (правки типографа — один-три знака), поэтому квадратичный подсчёт здесь дёшев.
     *
     * @param  string[]  $a
     * @param  string[]  $b
     */
    private static function refine(array $a, array $b): array
    {
        $n = \count($a);
        $m = \count($b);

        if ($a === $b) {
            return $n ? [['=', implode('', $a), '']] : [];
        }

        if (!$n || !$m || $n * $m > 40000) {
            return $m ? [['+', implode('', $b), implode('', $a)]] : [['-', implode('', $a), '']];
        }

        $l = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));

        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $l[$i][$j] = $a[$i] === $b[$j] ? $l[$i + 1][$j + 1] + 1 : max($l[$i + 1][$j], $l[$i][$j + 1]);
            }
        }

        $ops = [];
        $i   = 0;
        $j   = 0;
        $del = '';
        $ins = '';
        $put = static function () use (&$ops, &$del, &$ins): void {
            if ($ins !== '') {
                $ops[] = ['+', $ins, $del];
            } elseif ($del !== '') {
                $ops[] = ['-', $del, ''];
            }

            $del = $ins = '';
        };

        while ($i < $n || $j < $m) {
            if ($i < $n && $j < $m && $a[$i] === $b[$j]) {
                $put();
                $last = end($ops);

                if ($last && $last[0] === '=') {
                    $ops[\count($ops) - 1][1] .= $a[$i];
                } else {
                    $ops[] = ['=', $a[$i], ''];
                }

                $i++;
                $j++;
            } elseif ($j < $m && ($i === $n || $l[$i][$j + 1] >= $l[$i + 1][$j])) {
                $ins .= $b[$j++];
            } else {
                $del .= $a[$i++];
            }
        }

        $put();

        return $ops;
    }

    private function json(array $data, int $status = 200): never
    {
        $this->app->setHeader('status', (string) $status, true);
        $this->app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $this->app->sendHeaders();
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->app->close();
    }
}

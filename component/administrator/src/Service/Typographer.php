<?php

/**
 * @package     Webmarka.Redkollegiya
 * @subpackage  com_wmarka_redkollegiya
 * @copyright   (C) 2026 Beibit Sakhanov
 * @license     GNU General Public License version 3 or later; see LICENSE
 */

namespace Webmarka\Component\Redkollegiya\Administrator\Service;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Event\Event;
use Joomla\Registry\Registry;
use Webmarka\Component\Redkollegiya\Administrator\Engine\Engine;
use Webmarka\Component\Redkollegiya\Administrator\Engine\Proof\Proofreader;
use Webmarka\Component\Redkollegiya\Administrator\Engine\Result;

\defined('_JEXEC') or die;

/**
 * Типограф с настройками сайта — для кнопки в редакторе, правки при сохранении, ассистента через MCP и консоли:
 *
 *   use Webmarka\Component\Redkollegiya\Administrator\Service\Typographer;
 *   $result = Typographer::run($html, 'ru-RU', true, 'mcp', $articleId);
 *   $result->text;     // исправленный текст
 *   $result->groups(); // ['quotes' => 4, 'nbsp' => 12, …]
 *
 * Слова, которые не трогает ни одно правило, — из настроек компонента и от расширений сайта: плагины группы
 * «redkollegiya» получают событие onWmarkaRedkollegiyaWords и дописывают свои слова в аргумент words
 * (так подключается, например, словарь терминов издания).
 */
final class Typographer
{
    private static ?Engine $engine = null;

    public static function engine(): Engine
    {
        return self::$engine ??= new Engine(self::options());
    }

    /** Прогнать текст; если указан источник (button, save, mcp, cli) и что-то поправлено — запись в журнал. */
    public static function run(string $text, string $lang, bool $html = true, string $source = '', int $itemId = 0, string $context = 'com_content.article'): Result
    {
        $result = self::engine()->process($text, $lang, $html);

        if ($source !== '' && $result->total() > 0) {
            Journal::write($source, $result, $itemId, $context);
        }

        return $result;
    }

    /** Сводка для редактора: «кавычки 4, тире 3, неразрывные пробелы 12». */
    public static function summary(Result $result): string
    {
        $parts = [];

        foreach ($result->groups() as $group => $n) {
            $parts[] = Text::_('COM_WMARKA_REDKOLLEGIYA_GROUP_' . strtoupper($group)) . ' ' . $n;
        }

        return implode(', ', $parts);
    }

    /** Настройки компонента в виде настроек ядра. */
    public static function options(?Registry $p = null): array
    {
        $p ??= ComponentHelper::getParams('com_wmarka_redkollegiya');
        $d   = Engine::DEFAULTS;
        $yes = static fn (string $key) => (bool) (int) $p->get($key, (int) $d[$key]);

        $protect = preg_split('/\R/u', (string) $p->get('protect', '')) ?: [];

        return [
            'percent'         => (string) $p->get('percent', $d['percent']),
            'initials'        => (string) $p->get('initials', $d['initials']),
            'ruble'           => $yes('ruble'),
            'iso_dates'       => $yes('iso_dates'),
            'kk_percent'      => (string) $p->get('kk_percent', $d['kk_percent']),
            'kk_numero'       => (string) $p->get('kk_numero', $d['kk_numero']),
            'kk_degree'       => (string) $p->get('kk_degree', $d['kk_degree']),
            'kk_dash'         => (string) $p->get('kk_dash', $d['kk_dash']),
            'en_dash_words'   => $yes('en_dash_words'),
            'minus'           => $yes('minus'),
            'decimal_comma'   => $yes('decimal_comma'),
            'mln_period'      => $yes('mln_period'),
            'prepositions'    => (string) $p->get('prepositions', $d['prepositions']),
            'range_dash'      => (string) $p->get('range_dash', $d['range_dash']),
            'currency_before' => $yes('currency_before'),
            'units'           => preg_split('/\s+/u', trim((string) $p->get('units', implode(' ', $d['units'])))) ?: [],
            'yo'              => (string) $p->get('yo', $d['yo']),
            'yo_names'        => $yes('yo_names'),
            'protect'         => array_merge($protect, self::providedWords()),
        ];
    }

    /** Вычитка с настройками сайта; рубрика решает жанр: в новостях слова-маркеры нейрослопа не проверяются. */
    public static function proofreader(int $catid = 0, ?Registry $p = null): Proofreader
    {
        $p ??= ComponentHelper::getParams('com_wmarka_redkollegiya');
        $news  = array_map('intval', (array) $p->get('news_categories', []));
        $words = (string) $p->get('proof_slop_words', 'except_news');
        $genre = match (true) {
            $words === 'never'                                  => 'news',
            $words === 'always'                                 => 'other',
            $catid > 0 && \in_array($catid, $news, true)        => 'news',
            default                                             => 'other',
        };

        return new Proofreader([
            'genre'    => $genre,
            'slop'     => (bool) (int) $p->get('proof_slop', 1),
            'kanc'     => (bool) (int) $p->get('proof_kanc', 1),
            'rozental' => (bool) (int) $p->get('proof_rozental', 1),
            'rhythm'   => (bool) (int) $p->get('proof_rhythm', 1),
            'yo'       => (string) $p->get('yo', 'restore') === 'restore',
        ]);
    }

    /** Находки с текстом пояснения и названием слоя — для панели и страницы проверки. */
    public static function describe(array $found): array
    {
        foreach ($found as &$f) {
            $key          = 'COM_WMARKA_REDKOLLEGIYA_PROOF_' . str_replace('-', '_', $f['rule']);
            $f['message'] = isset($f['count']) ? Text::sprintf($key, $f['count'], $f['spread'] ?? '') : Text::_($key);
            $f['layerLabel'] = Text::_('COM_WMARKA_REDKOLLEGIYA_LAYER_' . strtoupper($f['layer']));
        }

        return $found;
    }

    /** Слова от расширений сайта (плагины группы redkollegiya). */
    private static function providedWords(): array
    {
        try {
            PluginHelper::importPlugin('redkollegiya');
            $event = new Event('onWmarkaRedkollegiyaWords', ['words' => []]);
            Factory::getApplication()->getDispatcher()->dispatch('onWmarkaRedkollegiyaWords', $event);

            return array_values(array_filter((array) $event->getArgument('words', []), 'is_string'));
        } catch (\Throwable) {
            return [];
        }
    }
}

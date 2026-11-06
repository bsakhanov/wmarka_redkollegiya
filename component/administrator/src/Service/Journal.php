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
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Webmarka\Component\Redkollegiya\Administrator\Engine\Result;

\defined('_JEXEC') or die;

/**
 * Журнал прогонов: кто, когда, какой материал, откуда (кнопка, сохранение, ассистент), сколько правок по каким правилам.
 * Зачем: правило, которое раз за разом правит одно и то же у одного автора, — повод для памятки; правило, которое правит
 * «не то», видно по выросшему счёту. Сами тексты в журнал не пишутся.
 */
final class Journal
{
    public static function write(string $source, Result $result, int $itemId = 0, string $context = 'com_content.article'): void
    {
        $params = ComponentHelper::getParams('com_wmarka_redkollegiya');

        if (!(int) $params->get('journal', 1)) {
            return;
        }

        try {
            $db   = Factory::getContainer()->get(DatabaseInterface::class);
            $user = Factory::getApplication()->getIdentity();
            $row  = (object) [
                'created' => Factory::getDate()->toSql(),
                'user_id' => $user ? (int) $user->id : 0,
                'item_id' => $itemId,
                'context' => substr($context, 0, 100),
                'source'  => substr($source, 0, 20),
                'lang'    => $result->lang,
                'changes' => $result->total(),
                'stats'   => json_encode($result->stats(), JSON_UNESCAPED_UNICODE),
            ];
            $db->insertObject('#__wmarka_redkollegiya_log', $row);

            // журнал не растёт без конца: изредка подчищаются записи старше срока из настроек
            if (random_int(1, 50) === 1) {
                $days = max(7, (int) $params->get('journal_days', 180));
                $edge = Factory::getDate('-' . $days . ' days')->toSql();
                $db->setQuery($db->getQuery(true)->delete($db->quoteName('#__wmarka_redkollegiya_log'))
                    ->where($db->quoteName('created') . ' < :edge')->bind(':edge', $edge))->execute();
            }
        } catch (\Throwable) {
            // журнал — подсказка, а не условие: сбой записи не должен мешать сохранению материала
        }
    }

    /** Последние прогоны с именем пользователя и заголовком материала. */
    public static function recent(int $limit = 30): array
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $q  = $db->getQuery(true)
            ->select(['l.*', $db->quoteName('u.name', 'user_name'), $db->quoteName('c.title', 'item_title')])
            ->from($db->quoteName('#__wmarka_redkollegiya_log', 'l'))
            ->join('LEFT', $db->quoteName('#__users', 'u'), 'u.id = l.user_id')
            ->join('LEFT', $db->quoteName('#__content', 'c'), 'c.id = l.item_id AND l.context = ' . $db->quote('com_content.article'))
            ->order($db->quoteName('l.id') . ' DESC')
            ->setLimit($limit);

        return $db->setQuery($q)->loadObjectList() ?: [];
    }

    /**
     * Итоги за последние дни: прогонов, правок, правки по правилам и по источникам.
     *
     * @return array{runs: int, changes: int, rules: array<string, int>, sources: array<string, int>}
     */
    public static function totals(int $days = 30): array
    {
        $db   = Factory::getContainer()->get(DatabaseInterface::class);
        $edge = Factory::getDate('-' . $days . ' days')->toSql();
        $rows = $db->setQuery($db->getQuery(true)
            ->select([$db->quoteName('source'), $db->quoteName('changes'), $db->quoteName('stats')])
            ->from($db->quoteName('#__wmarka_redkollegiya_log'))
            ->where($db->quoteName('created') . ' >= :edge')->bind(':edge', $edge, ParameterType::STRING)
            ->order($db->quoteName('id') . ' DESC')
            ->setLimit(5000))->loadObjectList() ?: [];

        $out = ['runs' => \count($rows), 'changes' => 0, 'rules' => [], 'sources' => []];

        foreach ($rows as $r) {
            $out['changes'] += (int) $r->changes;
            $out['sources'][$r->source] = ($out['sources'][$r->source] ?? 0) + 1;

            foreach ((array) json_decode((string) $r->stats, true) as $rule => $n) {
                $out['rules'][$rule] = ($out['rules'][$rule] ?? 0) + (int) $n;
            }
        }

        arsort($out['rules']);

        return $out;
    }
}

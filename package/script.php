<?php

/**
 * @package     Webmarka.Redkollegiya
 * @subpackage  pkg_wmarka_redkollegiya
 * @copyright   (C) 2026 Beibit Sakhanov
 * @license     GNU General Public License version 3 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

return new class () implements InstallerScriptInterface {
    /** Группы плагинов пакета: включаются сами при первой установке; выключенный редактором плагин обновление не включает. */
    private const GROUPS = ['editors-xtd', 'content'];

    /** Файлы прежних версий, которых в пакете больше нет: установщик Joomla сам их не убирает. */
    private const OBSOLETE = [
        'administrator/components/com_wmarka_redkollegiya/data/kk-forms.txt',   // 0.4.0 → kk-forms-kaznerd.txt (kk-redkollegiya 0.14.0)
        'administrator/components/com_wmarka_redkollegiya/data/kk-lm.json.gz',  // 0.4.0 → kk-lm-kaznerd.json.gz
    ];

    private array $existing = [];

    public function install(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function update(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function uninstall(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function preflight(string $type, InstallerAdapter $adapter): bool
    {
        if ($type !== 'uninstall' && (version_compare(PHP_VERSION, '8.3.0', '<') || version_compare(JVERSION, '6.0.0', '<'))) {
            Factory::getApplication()->enqueueMessage('Webmarka Редколлегия: нужны PHP 8.3 и выше и Joomla 6.0 и выше (проверено на Joomla 6.2, PHP 8.3 и 8.4).', 'error');

            return false;
        }

        if ($type !== 'uninstall') {
            $db             = Factory::getContainer()->get(DatabaseInterface::class);
            $this->existing = $db->setQuery($db->getQuery(true)->select($db->quoteName('folder'))->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))->where($db->quoteName('element') . ' = ' . $db->quote('wmarka_redkollegiya'))
                ->whereIn($db->quoteName('folder'), self::GROUPS, ParameterType::STRING))->loadColumn();
        }

        return true;
    }

    public function postflight(string $type, InstallerAdapter $adapter): bool
    {
        if ($type === 'uninstall') {
            return true;
        }

        foreach (self::OBSOLETE as $rel) {
            if (is_file(JPATH_ROOT . '/' . $rel)) {
                @unlink(JPATH_ROOT . '/' . $rel);
            }
        }

        $db  = Factory::getContainer()->get(DatabaseInterface::class);
        $new = array_values(array_diff(self::GROUPS, $this->existing));

        if ($new) {
            $db->setQuery($db->getQuery(true)->update($db->quoteName('#__extensions'))->set($db->quoteName('enabled') . ' = 1')
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))->where($db->quoteName('element') . ' = ' . $db->quote('wmarka_redkollegiya'))
                ->whereIn($db->quoteName('folder'), $new, ParameterType::STRING))->execute();
        }

        if ($type === 'install') {
            Factory::getApplication()->enqueueMessage('Webmarka Редколлегия установлена: кнопка «Типограф» — в редакторе материалов (в TinyMCE и на панели), '
                . 'при сохранении материала типограф проходит текст сам. Что он правит, проверить и настроить — '
                . '<a href="index.php?option=com_wmarka_redkollegiya">«Компоненты → Webmarka Редколлегия»</a>.');
        }

        return true;
    }
};

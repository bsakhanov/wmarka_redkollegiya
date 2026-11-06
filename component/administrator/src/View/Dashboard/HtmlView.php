<?php

/**
 * @package     Webmarka.Redkollegiya
 * @subpackage  com_wmarka_redkollegiya
 * @copyright   (C) 2026 Beibit Sakhanov
 * @license     GNU General Public License version 3 or later; see LICENSE
 */

namespace Webmarka\Component\Redkollegiya\Administrator\View\Dashboard;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\CMS\Uri\Uri;
use Webmarka\Component\Redkollegiya\Administrator\Service\Journal;

\defined('_JEXEC') or die;

/** Страница компонента: проверить текст, последние прогоны, итоги за месяц. */
class HtmlView extends BaseHtmlView
{
    public array $recent = [];

    public array $totals = [];

    public bool $saveOn = false;

    public bool $buttonOn = false;

    public bool $journalOn = true;

    public function display($tpl = null): void
    {
        $this->recent    = Journal::recent(40);
        $this->totals    = Journal::totals(30);
        $this->saveOn    = PluginHelper::isEnabled('content', 'wmarka_redkollegiya');
        $this->buttonOn  = PluginHelper::isEnabled('editors-xtd', 'wmarka_redkollegiya');
        $this->journalOn = (bool) (int) ComponentHelper::getParams('com_wmarka_redkollegiya')->get('journal', 1);

        ToolbarHelper::title(Text::_('COM_WMARKA_REDKOLLEGIYA'), 'spell-check');

        if ($this->getCurrentUser()->authorise('core.admin', 'com_wmarka_redkollegiya') || $this->getCurrentUser()->authorise('core.options', 'com_wmarka_redkollegiya')) {
            ToolbarHelper::preferences('com_wmarka_redkollegiya');
        }

        $doc = $this->getDocument();
        $wa  = $doc->getWebAssetManager();
        $wa->getRegistry()->addExtensionRegistryFile('com_wmarka_redkollegiya');
        $wa->useStyle('com_wmarka_redkollegiya.admin')->useScript('com_wmarka_redkollegiya.dashboard');
        $doc->addScriptOptions('com_wmarka_redkollegiya.dashboard', [
            'url'   => Uri::base(true) . '/index.php?option=com_wmarka_redkollegiya&task=typograph.process&format=json',
            'error' => Text::_('COM_WMARKA_REDKOLLEGIYA_ERROR'),
        ]);

        parent::display($tpl);
    }
}

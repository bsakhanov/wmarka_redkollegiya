<?php

/**
 * @package     Webmarka.Redkollegiya
 * @subpackage  plg_editors-xtd_wmarka_redkollegiya
 * @copyright   (C) 2026 Beibit Sakhanov
 * @license     GNU General Public License version 3 or later; see LICENSE
 */

namespace Webmarka\Plugin\EditorsXtd\Redkollegiya\Extension;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Editor\Button\Button;
use Joomla\CMS\Event\Editor\EditorButtonsSetupEvent;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\SubscriberInterface;

\defined('_JEXEC') or die;

/**
 * Кнопка «Типограф» в редакторе: «ёлочки», тире, минус, неразрывные пробелы, десятичная запятая, буква «ё» — по настройкам
 * компонента Webmarka Редколлегия. В TinyMCE — пункт меню «Контент» и (по настройке) кнопка на панели.
 * Только в панели управления: текст прогоняет страница компонента в админке, а сеанс сайта с сеансом админки не общий.
 */
final class Redkollegiya extends CMSPlugin implements SubscriberInterface
{
    /** Кавычки-«ёлочки»: правка набора, а не орфографии. */
    private const ICON = '<svg width="24" height="24" viewBox="0 0 24 24" aria-hidden="true">'
        . '<path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"'
        . ' d="M7.5 7 3.5 12l4 5M11.5 7l-4 5 4 5M12.5 7l4 5-4 5M16.5 7l4 5-4 5"/></svg>';

    public static function getSubscribedEvents(): array
    {
        return ['onEditorButtonsSetup' => 'onEditorButtonsSetup'];
    }

    public function onEditorButtonsSetup(EditorButtonsSetupEvent $event): void
    {
        $app  = $this->getApplication();
        $user = $app->getIdentity();

        if (\in_array($this->_name, $event->getDisabledButtons(), true) || !$app->isClient('administrator')
            || !ComponentHelper::isEnabled('com_wmarka_redkollegiya') || !$user || !$user->id) {
            return;
        }

        $this->loadLanguage();
        $doc  = $app->getDocument();
        $wa   = $doc->getWebAssetManager();
        $text = Text::_('PLG_EDITORS-XTD_WMARKA_REDKOLLEGIYA_BUTTON');

        if (!$wa->assetExists('script', 'editor-button.' . $this->_name)) {
            $wa->registerScript('editor-button.' . $this->_name, 'com_wmarka_redkollegiya/button.js', [], ['type' => 'module'], ['editors']);
        }

        // и для меню «Контент», и для кнопки на панели: действие должно быть зарегистрировано, даже если разметка кнопок не выводится
        $wa->useScript('editor-button.' . $this->_name);

        $doc->addScriptOptions('com_wmarka_redkollegiya.button', [
            'url'     => Uri::base(true) . '/index.php?option=com_wmarka_redkollegiya&task=typograph.process&format=json',
            'error'   => Text::_('PLG_EDITORS-XTD_WMARKA_REDKOLLEGIYA_ERROR'),
            'changed' => Text::_('PLG_EDITORS-XTD_WMARKA_REDKOLLEGIYA_CHANGED'),
        ]);

        if ($event->getEditorType() === 'tinymce' && (int) $this->params->get('toolbar', 1)) {
            $wa->getRegistry()->addExtensionRegistryFile('com_wmarka_redkollegiya');
            $wa->useScript('com_wmarka_redkollegiya.tinymce');
            $doc->addScriptOptions('com_wmarka_redkollegiya.tinymce', [
                'place'   => (string) $this->params->get('place', 'start'),
                'text'    => $text,
                'tooltip' => Text::_('PLG_EDITORS-XTD_WMARKA_REDKOLLEGIYA_TOOLTIP'),
                'icon'    => self::ICON,
            ]);
        }

        $event->getButtonsRegistry()->add(new Button(
            $this->_name,
            [
                'action'  => 'wmarka-typograph',
                'text'    => $text,
                'icon'    => 'spell-check',
                'iconSVG' => self::ICON,
                'name'    => $this->_type . '_' . $this->_name,
            ]
        ));
    }
}

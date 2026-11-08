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
 * Кнопки «Типограф» и «Вычитка» в редакторе. Типограф расставляет «ёлочки», тире, минус, неразрывные пробелы, десятичную
 * запятую и «ё»; вычитка показывает находки (следы чат-ботов, канцелярит, ошибки по закрытым спискам Розенталя), а вносит
 * только принятые. Настройки — у компонента Webmarka Редколлегия. В TinyMCE — пункты меню «Контент» и (по настройке) кнопки на панели.
 * Только в панели управления: текст прогоняет страница компонента в админке, а сеанс сайта с сеансом админки не общий.
 */
final class Redkollegiya extends CMSPlugin implements SubscriberInterface
{
    /** Кавычки-«ёлочки»: правка набора, а не орфографии. */
    private const ICON = '<svg width="24" height="24" viewBox="0 0 24 24" aria-hidden="true">'
        . '<path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"'
        . ' d="M7.5 7 3.5 12l4 5M11.5 7l-4 5 4 5M12.5 7l4 5-4 5M16.5 7l4 5-4 5"/></svg>';

    /** Лупа со строкой: вычитка — находки, а не правки. */
    private const ICON_PROOF = '<svg width="24" height="24" viewBox="0 0 24 24" aria-hidden="true">'
        . '<path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"'
        . ' d="M3.5 6.5h9M3.5 10.5h5M3.5 14.5h4M15 17.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7zM17.6 16.6l3 3"/></svg>';

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
        $doc   = $app->getDocument();
        $wa    = $doc->getWebAssetManager();
        $text  = Text::_('PLG_EDITORS-XTD_WMARKA_REDKOLLEGIYA_BUTTON');
        $proof = Text::_('PLG_EDITORS-XTD_WMARKA_REDKOLLEGIYA_PROOF');
        $base  = Uri::base(true) . '/index.php?option=com_wmarka_redkollegiya&format=json&task=';

        // и для меню «Контент», и для кнопок на панели: действия должны быть зарегистрированы, даже если разметка кнопок не выводится
        foreach ([$this->_name => ['button.js', ['editors']], $this->_name . '_proof' => ['proof.js', ['editors', 'joomla.dialog']]] as $name => [$file, $deps]) {
            if (!$wa->assetExists('script', 'editor-button.' . $name)) {
                $wa->registerScript('editor-button.' . $name, 'com_wmarka_redkollegiya/' . $file, [], ['type' => 'module'], $deps);
            }

            $wa->useScript('editor-button.' . $name);
        }

        $wa->getRegistry()->addExtensionRegistryFile('com_wmarka_redkollegiya');
        $wa->useStyle('com_wmarka_redkollegiya.proof');

        $doc->addScriptOptions('com_wmarka_redkollegiya.button', [
            'url'     => $base . 'typograph.process',
            'error'   => Text::_('PLG_EDITORS-XTD_WMARKA_REDKOLLEGIYA_ERROR'),
            'changed' => Text::_('PLG_EDITORS-XTD_WMARKA_REDKOLLEGIYA_CHANGED'),
        ]);
        $doc->addScriptOptions('com_wmarka_redkollegiya.proof', [
            'check' => $base . 'proof.check',
            'apply' => $base . 'proof.apply',
            'text'  => array_map(static fn ($k) => Text::_('PLG_EDITORS-XTD_WMARKA_REDKOLLEGIYA_PROOF_' . strtoupper($k)), array_combine(
                ['title', 'lead', 'accept', 'decline', 'seen', 'remove', 'acceptAuto', 'acceptGroup', 'apply', 'close', 'error', 'changed'],
                ['title', 'lead', 'accept', 'decline', 'seen', 'remove', 'accept_auto', 'accept_group', 'apply', 'close', 'error', 'changed']
            )),
        ]);

        $toolbar = (int) $this->params->get('toolbar', 1);
        $blocks  = (string) $this->params->get('blocks', 'news') === 'news';

        if ($event->getEditorType() === 'tinymce' && ($toolbar || $blocks)) {
            $wa->useScript('com_wmarka_redkollegiya.tinymce');
            $doc->addScriptOptions('com_wmarka_redkollegiya.tinymce', [
                'place'   => (string) $this->params->get('place', 'start'),
                // список «Блоки» без H1 (он один на странице — заголовок материала), H5, H6 и «Предварительно форматированного»
                'blockFormats' => $blocks ? implode('; ', [
                    Text::_('PLG_EDITORS-XTD_WMARKA_REDKOLLEGIYA_BLOCK_P') . '=p',
                    Text::_('PLG_EDITORS-XTD_WMARKA_REDKOLLEGIYA_BLOCK_H2') . '=h2',
                    Text::_('PLG_EDITORS-XTD_WMARKA_REDKOLLEGIYA_BLOCK_H3') . '=h3',
                    Text::_('PLG_EDITORS-XTD_WMARKA_REDKOLLEGIYA_BLOCK_H4') . '=h4',
                ]) : '',
                'buttons' => !$toolbar ? [] : [
                    ['name' => 'wmarkatypograph', 'action' => 'wmarka-typograph', 'text' => $text, 'icon' => self::ICON,
                        'tooltip' => Text::_('PLG_EDITORS-XTD_WMARKA_REDKOLLEGIYA_TOOLTIP')],
                    ['name' => 'wmarkaproof', 'action' => 'wmarka-proof', 'text' => $proof, 'icon' => self::ICON_PROOF,
                        'tooltip' => Text::_('PLG_EDITORS-XTD_WMARKA_REDKOLLEGIYA_PROOF_TOOLTIP')],
                ],
            ]);
        }

        $event->getButtonsRegistry()->add(new Button($this->_name, [
            'action'  => 'wmarka-typograph',
            'text'    => $text,
            'icon'    => 'spell-check',
            'iconSVG' => self::ICON,
            'name'    => $this->_type . '_' . $this->_name,
        ]));
        $event->getButtonsRegistry()->add(new Button($this->_name . '_proof', [
            'action'  => 'wmarka-proof',
            'text'    => $proof,
            'icon'    => 'search',
            'iconSVG' => self::ICON_PROOF,
            'name'    => $this->_type . '_' . $this->_name . '_proof',
        ]));
    }
}

<?php

/**
 * @package     Webmarka.Redkollegiya
 * @subpackage  plg_content_wmarka_redkollegiya
 * @copyright   (C) 2026 Beibit Sakhanov
 * @license     GNU General Public License version 3 or later; see LICENSE
 */

namespace Webmarka\Plugin\Content\Redkollegiya\Extension;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Event\Model\AfterSaveEvent;
use Joomla\CMS\Event\Model\BeforeSaveEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\Priority;
use Joomla\Event\SubscriberInterface;
use Joomla\Registry\Registry;
use Webmarka\Component\Redkollegiya\Administrator\Engine\Counter;
use Webmarka\Component\Redkollegiya\Administrator\Engine\Engine;
use Webmarka\Component\Redkollegiya\Administrator\Engine\Result;
use Webmarka\Component\Redkollegiya\Administrator\Service\Journal;
use Webmarka\Component\Redkollegiya\Administrator\Service\Typographer;

\defined('_JEXEC') or die;

/**
 * Типограф при сохранении материала: текст, заголовок, метаописание, подписи и альтернативный текст снимков —
 * что именно, решают настройки компонента. Идёт последним из всех обработчиков сохранения (приоритет MIN):
 * сначала очистка (Webmarka Clean убирает следы Word и значки), затем типограф, иначе очистка могла бы снять его работу.
 * Адрес материала Joomla строит из заголовка раньше, до этого события, — «ёлочки» и тире в адрес не попадают.
 */
final class Redkollegiya extends CMSPlugin implements SubscriberInterface
{
    private const CONTEXTS = ['com_content.article', 'com_content.form'];

    /** Правки последнего сохранения: в журнал они пишутся после записи, когда известен номер нового материала. */
    private ?Result $pending = null;

    public static function getSubscribedEvents(): array
    {
        return [
            'onContentBeforeSave' => ['onContentBeforeSave', Priority::MIN],
            'onContentAfterSave'  => ['onContentAfterSave', Priority::MIN],
        ];
    }

    public function onContentBeforeSave(BeforeSaveEvent $event): void
    {
        $this->pending = null;

        if (!\in_array($event->getContext(), self::CONTEXTS, true) || !class_exists(Typographer::class)
            || !ComponentHelper::isEnabled('com_wmarka_redkollegiya')) {
            return;
        }

        $item = $event->getItem();

        if (!\is_object($item)) {
            return;
        }

        $p      = ComponentHelper::getParams('com_wmarka_redkollegiya');
        $engine = Typographer::engine();
        $total  = new Counter();
        $sample = trim(implode(' ', [(string) ($item->title ?? ''), (string) ($item->introtext ?? ''), (string) ($item->fulltext ?? '')]));
        $lang   = Engine::language((string) ($item->language ?? '*'), $sample);

        if ($lang === '') {
            return;
        }

        $run = static function (string $text, bool $html) use ($engine, $lang, $total): string {
            if (trim($text) === '') {
                return $text;
            }

            $out = $engine->process($text, $lang, $html);

            foreach ($out->stats() as $rule => $n) {
                $total->add($rule, $n);
            }

            return $out->text;
        };

        if ((int) $p->get('save_text', 1)) {
            foreach (['introtext', 'fulltext'] as $f) {
                if (isset($item->$f) && \is_string($item->$f)) {
                    $item->$f = $run($item->$f, true);
                }
            }
        }

        foreach (['title' => 'save_title', 'metadesc' => 'save_metadesc'] as $f => $opt) {
            if ((int) $p->get($opt, 1) && isset($item->$f) && \is_string($item->$f)) {
                $item->$f = $run($item->$f, false);
            }
        }

        if ((int) $p->get('save_images', 1) && !empty($item->images) && \is_string($item->images)) {
            $images  = json_decode($item->images, true);
            $changed = false;

            if (\is_array($images)) {
                foreach (['image_intro_alt', 'image_intro_caption', 'image_fulltext_alt', 'image_fulltext_caption'] as $key) {
                    if (isset($images[$key]) && \is_string($images[$key]) && $images[$key] !== '') {
                        $new = $run($images[$key], false);

                        if ($new !== $images[$key]) {
                            $images[$key] = $new;
                            $changed      = true;
                        }
                    }
                }

                if ($changed) {
                    $item->images = (string) (new Registry($images));
                }
            }
        }

        if ($total->total() > 0) {
            $this->pending = new Result('', $lang, $total);
        }
    }

    public function onContentAfterSave(AfterSaveEvent $event): void
    {
        if ($this->pending === null || !\in_array($event->getContext(), self::CONTEXTS, true)) {
            return;
        }

        $item = $event->getItem();
        Journal::write('save', $this->pending, \is_object($item) ? (int) ($item->id ?? 0) : 0, 'com_content.article');
        $this->pending = null;
    }
}

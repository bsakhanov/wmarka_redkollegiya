<?php

/**
 * @package     Webmarka Редколлегия
 * @copyright   (C) 2026 Beibit Sakhanov
 * @license     GNU General Public License version 3 or later; see LICENSE
 */

namespace Webmarka\Component\Redkollegiya\Administrator\Dispatcher;

\defined('_JEXEC') or die;

use Joomla\CMS\Dispatcher\ComponentDispatcher;

/**
 * Диспетчер компонента: пропускает запросы кнопок «Типограф» и «Вычитка» к тем, кто пишет материалы.
 *
 * Кнопки в редакторе шлют запросы в компонент (task=typograph.* и proof.*). Штатная проверка Joomla — ComponentDispatcher::checkAccess() —
 * пускает в компонент админки только с правом core.manage на него и срабатывает раньше контроллера. Поэтому у автора без этого права
 * кнопки получали ответ 403, а скрипт показывал «Ошибка true» (найдено на сайте The Tenge 8.11.2026). Свою проверку — право писать или
 * править материалы и токен формы — контроллеры делают сами (TypographController::process, ProofController::guard), так что запросы кнопок
 * здесь пропускаются дальше. Страница компонента, журнал и настройки по-прежнему только с core.manage.
 */
class Dispatcher extends ComponentDispatcher
{
    /**
     * Имя компонента задано явно: без него ComponentDispatcher выводит его из пространства имён (Webmarka\Component\Redkollegiya →
     * com_redkollegiya), и тогда не грузится языковой файл (сводка типографа приходила ключом COM_WMARKA_REDKOLLEGIYA_MSG_DONE),
     * а core.manage проверяется не на том компоненте — страница закрывалась и у выпускающего.
     */
    protected $option = 'com_wmarka_redkollegiya';

    /** Контроллеры, к которым ходят кнопки редактора; у каждого своя проверка прав и токена. */
    private const BUTTON_CONTROLLERS = ['typograph', 'proof'];

    protected function checkAccess()
    {
        $task = (string) $this->input->getCmd('task', '');

        if ($this->app->isClient('administrator') && str_contains($task, '.')
            && \in_array(strtolower(strstr($task, '.', true)), self::BUTTON_CONTROLLERS, true)) {
            $user = $this->app->getIdentity();

            if ($user && $user->id && ($user->authorise('core.manage', $this->option) || $user->authorise('core.create', 'com_content')
                || $user->authorise('core.edit', 'com_content') || $user->authorise('core.edit.own', 'com_content'))) {
                return;
            }
        }

        parent::checkAccess();
    }
}

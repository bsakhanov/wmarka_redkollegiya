<?php

/**
 * @package     Webmarka.Redkollegiya
 * @subpackage  plg_editors-xtd_wmarka_redkollegiya
 * @copyright   (C) 2026 Beibit Sakhanov
 * @license     GNU General Public License version 3 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Webmarka\Plugin\EditorsXtd\Redkollegiya\Extension\Redkollegiya;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(PluginInterface::class, $container->lazy(Redkollegiya::class, function (Container $container) {
            $plugin = new Redkollegiya((array) PluginHelper::getPlugin('editors-xtd', 'wmarka_redkollegiya'));
            $plugin->setApplication(Factory::getApplication());

            return $plugin;
        }));
    }
};

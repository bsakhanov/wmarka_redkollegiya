<?php

/**
 * Проверки без Joomla: ядро Редколлегии — обычные классы PHP, им нужен только _JEXEC.
 */

\define('_JEXEC', 1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

spl_autoload_register(static function (string $class): void {
    $prefix = 'Webmarka\\Component\\Redkollegiya\\Administrator\\';

    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/../component/administrator/src/' . str_replace('\\', '/', substr($class, \strlen($prefix))) . '.php';

        if (is_file($file)) {
            require $file;
        }
    }
});

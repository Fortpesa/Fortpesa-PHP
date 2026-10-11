<?php
/**
 * Fallback autoloader for projects that don't use Composer
 * (e.g. a WordPress / WooCommerce plugin):
 *
 *     require_once __DIR__ . '/fortpesa-php/autoload.php';
 */
spl_autoload_register(static function ($class) {
    $prefix = 'Fortpesa\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

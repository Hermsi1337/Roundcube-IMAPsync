<?php

require __DIR__ . '/../../vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    $path = __DIR__ . '/' . $class . '.php';

    if (is_file($path)) {
        require_once $path;
    }
});

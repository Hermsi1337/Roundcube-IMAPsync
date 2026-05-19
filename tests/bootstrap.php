<?php

spl_autoload_register(static function (string $class): void {
    $paths = [
        __DIR__ . '/../lib/' . $class . '.php',
        __DIR__ . '/Fakes/' . $class . '.php',
    ];

    foreach ($paths as $path) {
        if (is_file($path)) {
            require_once $path;

            return;
        }
    }
});

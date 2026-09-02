<?php

$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$root = realpath(__DIR__);
$file = realpath($root . DIRECTORY_SEPARATOR . ltrim($path, '/\\'));
$insideRoot = $file !== false
    && strncasecmp($file, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) === 0;

if ($path !== '/' && $insideRoot && is_file($file)) {
    return false;
}

require __DIR__ . '/index.php';

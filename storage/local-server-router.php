<?php

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = realpath(__DIR__ . '/../public' . $path);
$public = realpath(__DIR__ . '/../public');

if ($file !== false && $public !== false && str_starts_with($file, $public) && is_file($file)) {
    return false;
}

require __DIR__ . '/../public/index.php';

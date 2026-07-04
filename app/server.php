<?php

// Dev static server for the built React bundle (./dist), with SPA fallback.
// Run as: php -S 0.0.0.0:80 -t dist server.php
// (In production this is a static host / CDN — no PHP.)

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

// Serve a real built asset if it exists (docroot is ./dist)
if ($path !== '/' && is_file(__DIR__ . '/dist' . $path)) {
    return false;
}

// Otherwise return the SPA entrypoint
header('Content-Type: text/html; charset=utf-8');
readfile(__DIR__ . '/dist/index.html');

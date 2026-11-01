<?php
// Router for PHP's LOCAL QA server, which otherwise 404s nonexistent *.html paths.
if (PHP_SAPI !== 'cli-server') { exit(1); }
$root = realpath($_SERVER['DOCUMENT_ROOT']);
$expected = realpath(dirname(__DIR__) . '/dist/checks-v1.1.0/site');
if (!$root || !$expected || strcasecmp($root, $expected) !== 0 || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403); exit;
}
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$file = realpath($root . '/' . ltrim($path, '/'));
if ($file && strncasecmp($file, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) === 0 && is_file($file)) {
    return false;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
require $root . '/index.php';

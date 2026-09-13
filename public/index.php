<?php

declare(strict_types=1);

use AIPanel\Http\Request;
use AIPanel\Http\Response;
use AIPanel\Http\Router;
use AIPanel\Support\Config;

/** @var AIPanel\Support\Container $container */
$container = require dirname(__DIR__) . '/src/bootstrap.php';

$config = $container->get(Config::class);
$debug = (bool) $config->get('app.debug', false);

error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');

// Sessions are the only per-request state, and they live in Redis in
// production so any web replica can serve any request. If Redis is configured
// but unreachable, fall back to local file sessions rather than failing every
// request: a single-node deployment keeps working, and a multi-node one
// degrades to sticky sessions instead of an outage.
$redisDsn = (string) $config->get('redis.dsn', '');
if ($redisDsn !== '' && extension_loaded('redis')) {
    $parts = parse_url($redisDsn);
    $probe = @fsockopen(
        ($parts['scheme'] ?? 'tcp') . '://' . ($parts['host'] ?? '127.0.0.1'),
        (int) ($parts['port'] ?? 6379),
        $errno,
        $errstr,
        0.2
    );

    if ($probe !== false) {
        fclose($probe);
        ini_set('session.save_handler', 'redis');
        ini_set('session.save_path', $redisDsn);
    } else {
        error_log("[aipanel] Redis at {$redisDsn} is unreachable ({$errstr}); using file sessions.");
    }
}

session_start([
    'name' => 'aipanel_session',
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_secure' => str_starts_with((string) $config->get('app.url'), 'https://'),
    'use_strict_mode' => true,
    'gc_maxlifetime' => 28800,
]);

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' https://avatars.githubusercontent.com data:; style-src 'self' 'unsafe-inline'");

$request = Request::fromGlobals();

try {
    $response = $container->get(Router::class)->dispatch($request, $container);
} catch (Throwable $e) {
    error_log(sprintf('[aipanel] %s: %s in %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));

    $response = $request->wantsJson()
        ? Response::json([
            'error' => 'internal_error',
            'detail' => $debug ? $e->getMessage() : null,
        ], 500)
        : Response::html(
            $debug
                ? '<h1>500</h1><pre>' . htmlspecialchars($e->getMessage() . "\n\n" . $e->getTraceAsString(), ENT_QUOTES) . '</pre>'
                : '<h1>500 — something went wrong</h1>',
            500
        );
}

$response->send();

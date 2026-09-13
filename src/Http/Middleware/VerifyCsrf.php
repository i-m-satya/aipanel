<?php

declare(strict_types=1);

namespace AIPanel\Http\Middleware;

use AIPanel\Http\Request;
use AIPanel\Http\Response;

final class VerifyCsrf
{
    public function handle(Request $request): ?Response
    {
        if (in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return null;
        }

        $expected = (string) ($_SESSION['csrf_token'] ?? '');
        $provided = $request->input('_token') ?? ($request->headers['x-csrf-token'] ?? '');

        if ($expected === '' || !hash_equals($expected, (string) $provided)) {
            return $request->wantsJson()
                ? Response::json(['error' => 'csrf_token_mismatch'], 419)
                : Response::html('<h1>419 — your session expired. Reload and try again.</h1>', 419);
        }

        return null;
    }

    public static function token(): string
    {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION['csrf_token'];
    }
}

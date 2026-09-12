<?php

declare(strict_types=1);

namespace AIPanel\Http\Middleware;

use AIPanel\Domain\UserRepository;
use AIPanel\Http\Request;
use AIPanel\Http\Response;

/**
 * Every authenticated route resolves the session's user id into a live user row
 * and hangs the account id off the request, so controllers never take an
 * account id from user input.
 */
final class RequireAuth
{
    public function __construct(private UserRepository $users)
    {
    }

    public function handle(Request $request): ?Response
    {
        $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
        if ($userId === 0) {
            return $request->wantsJson()
                ? Response::json(['error' => 'unauthenticated'], 401)
                : Response::redirect('/login');
        }

        $user = $this->users->find($userId);
        if ($user === null || !$this->users->isActive($user)) {
            // Access revoked or user disabled since the session was created.
            $_SESSION = [];

            return $request->wantsJson()
                ? Response::json(['error' => 'unauthenticated'], 401)
                : Response::redirect('/login?reason=revoked');
        }

        $request->attributes['user_id'] = (string) $user['id'];
        $request->attributes['account_id'] = (string) $user['account_id'];
        $request->attributes['role'] = (string) $user['role'];

        return null;
    }
}

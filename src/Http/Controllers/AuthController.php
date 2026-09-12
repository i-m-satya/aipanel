<?php

declare(strict_types=1);

namespace AIPanel\Http\Controllers;

use AIPanel\Auth\GitHubOAuth;
use AIPanel\Domain\UserRepository;
use AIPanel\Git\GitHubException;
use AIPanel\Http\Request;
use AIPanel\Http\Response;
use AIPanel\Http\View;

/**
 * Login is GitHub-only. There is no password form, no reset flow, and no local
 * credential to steal.
 */
final class AuthController
{
    public function __construct(
        private GitHubOAuth $oauth,
        private UserRepository $users,
        private View $view,
    ) {
    }

    public function loginPage(Request $request): Response
    {
        if (isset($_SESSION['user_id'])) {
            return Response::redirect('/');
        }

        return Response::html($this->view->render('auth/login', [
            'reason' => $request->input('reason'),
        ]));
    }

    public function start(Request $request): Response
    {
        ['url' => $url, 'state' => $state] = $this->oauth->authorizeUrl();

        // The state is bound to this session and compared on return, so a
        // callback replayed from elsewhere cannot log anyone in.
        $_SESSION['oauth_state'] = $state;

        return Response::redirect($url);
    }

    public function callback(Request $request): Response
    {
        $code = $request->input('code');
        $state = $request->input('state', '');
        $expected = (string) ($_SESSION['oauth_state'] ?? '');
        unset($_SESSION['oauth_state']);

        if ($code === null) {
            return Response::html($this->view->render('auth/login', [
                'reason' => 'denied',
            ]), 400);
        }

        try {
            $token = $this->oauth->exchangeCode($code, (string) $state, $expected);
            $profile = $this->oauth->user($token);
        } catch (GitHubException $e) {
            return Response::html($this->view->render('auth/login', [
                'reason' => 'error',
                'detail' => $e->getMessage(),
            ]), 400);
        }

        $user = $this->users->upsertFromGithub($profile);

        if (!$this->users->isActive($user)) {
            return Response::html($this->view->render('auth/login', ['reason' => 'disabled']), 403);
        }

        // New session id on privilege change: no session fixation.
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['github_login'] = (string) $user['github_login'];

        return Response::redirect('/');
    }

    public function logout(Request $request): Response
    {
        $_SESSION = [];
        session_destroy();

        return Response::redirect('/login');
    }
}

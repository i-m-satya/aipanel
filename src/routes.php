<?php

declare(strict_types=1);

use AIPanel\Http\Controllers\AssistantController;
use AIPanel\Http\Controllers\AuthController;
use AIPanel\Http\Controllers\DashboardController;
use AIPanel\Http\Controllers\SiteController;
use AIPanel\Http\Controllers\WebhookController;
use AIPanel\Http\Middleware\RequireAuth;
use AIPanel\Http\Middleware\VerifyCsrf;
use AIPanel\Http\Router;

$auth = [RequireAuth::class];
$authed = [RequireAuth::class, VerifyCsrf::class];

/** @return Router */
$routes = static function (Router $router) use ($auth, $authed): Router {
    // Public: health, GitHub login, GitHub webhook.
    $router->get('/health', [DashboardController::class, 'health']);
    $router->get('/login', [AuthController::class, 'loginPage']);
    $router->get('/auth/github', [AuthController::class, 'start']);
    $router->get('/auth/github/callback', [AuthController::class, 'callback']);
    $router->post('/logout', [AuthController::class, 'logout'], [VerifyCsrf::class]);

    // Webhooks authenticate by HMAC signature, not by session, so no CSRF here.
    $router->post('/webhooks/github', [WebhookController::class, 'github']);

    // Panel
    $router->get('/', [DashboardController::class, 'index'], $auth);
    $router->get('/sites', [SiteController::class, 'index'], $auth);
    $router->post('/sites', [SiteController::class, 'create'], $authed);
    $router->get('/sites/{id}', [SiteController::class, 'show'], $auth);
    $router->post('/sites/{id}/deploy', [SiteController::class, 'deploy'], $authed);
    $router->post('/sites/{id}/rollback', [SiteController::class, 'rollback'], $authed);
    $router->post('/sites/{id}/ssh-keys', [SiteController::class, 'addSshKey'], $authed);
    $router->post('/sites/{id}/changes', [AssistantController::class, 'requestChange'], $authed);

    // AI
    $router->get('/assistant', [AssistantController::class, 'page'], $auth);
    $router->post('/assistant/chat', [AssistantController::class, 'chat'], $authed);
    $router->post('/assistant/plans/{plan_id}/approve', [AssistantController::class, 'approvePlan'], $authed);
    $router->post('/assistant/plans/{plan_id}/reject', [AssistantController::class, 'rejectPlan'], $authed);

    return $router;
};

return $routes;

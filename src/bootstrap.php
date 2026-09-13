<?php

declare(strict_types=1);

use AIPanel\Auth\GitHubOAuth;
use AIPanel\Deploy\DeployService;
use AIPanel\Deploy\PromotionService;
use AIPanel\Domain\NodeRepository;
use AIPanel\Domain\SiteRepository;
use AIPanel\Domain\UserRepository;
use AIPanel\Git\GitHubApp;
use AIPanel\Http\Controllers\AuthController;
use AIPanel\Http\Controllers\DashboardController;
use AIPanel\Http\Controllers\SiteController;
use AIPanel\Http\Controllers\WebhookController;
use AIPanel\Http\Middleware\RequireAuth;
use AIPanel\Http\Middleware\VerifyCsrf;
use AIPanel\Http\Router;
use AIPanel\Http\View;
use AIPanel\Infra\Database;
use AIPanel\Infra\Migrator;
use AIPanel\Jobs\JobQueue;
use AIPanel\Jobs\JobRunner;
use AIPanel\Nodes\AgentClient;
use AIPanel\Support\Config;
use AIPanel\Support\Container;
use AIPanel\Support\Crypto;
use AIPanel\Support\Env;
use AIPanel\Tasks\TaskValidator;

require dirname(__DIR__) . '/vendor/autoload.php';

Env::load(dirname(__DIR__) . '/.env');

$config = Config::fromEnv();
$container = new Container();
$root = dirname(__DIR__);

$container->set(Config::class, static fn (): Config => $config);

$container->set(Database::class, static fn (): Database => new Database(
    (string) $config->get('db.dsn'),
    (string) $config->get('db.user'),
    (string) $config->get('db.pass'),
));

$container->set(Crypto::class, static fn (): Crypto => new Crypto((string) $config->get('app.key')));

$container->set(Migrator::class, static fn (Container $c): Migrator => new Migrator(
    $c->get(Database::class),
    $root . '/db/migrations',
));

$container->set(View::class, static fn (): View => new View($root . '/views'));
$container->set(TaskValidator::class, static fn (): TaskValidator => new TaskValidator());

$container->set(AgentClient::class, static fn (): AgentClient => new AgentClient(
    (int) $config->get('agent.timeout', 60),
));

$container->set(UserRepository::class, static fn (Container $c): UserRepository => new UserRepository(
    $c->get(Database::class),
));

$container->set(NodeRepository::class, static fn (Container $c): NodeRepository => new NodeRepository(
    $c->get(Database::class),
    $c->get(Crypto::class),
));

$container->set(SiteRepository::class, static fn (Container $c): SiteRepository => new SiteRepository(
    $c->get(Database::class),
));

$container->set(JobQueue::class, static fn (Container $c): JobQueue => new JobQueue(
    $c->get(Database::class),
    $c->get(TaskValidator::class),
    (int) $config->get('worker.lease_seconds', 60),
    (int) $config->get('worker.max_attempts', 5),
    (int) $config->get('worker.node_max_concurrency', 4),
));

$container->set(JobRunner::class, static fn (Container $c): JobRunner => new JobRunner(
    $c->get(Database::class),
    $c->get(JobQueue::class),
    $c->get(NodeRepository::class),
    $c->get(SiteRepository::class),
    $c->get(AgentClient::class),
));

$container->set(GitHubApp::class, static fn (): GitHubApp => new GitHubApp(
    (string) Env::get('GITHUB_APP_ID', ''),
    (string) (@file_get_contents((string) Env::get('GITHUB_APP_PRIVATE_KEY_PATH', '')) ?: ''),
));

$container->set(GitHubOAuth::class, static fn (): GitHubOAuth => new GitHubOAuth(
    (string) Env::get('GITHUB_OAUTH_CLIENT_ID', ''),
    (string) Env::get('GITHUB_OAUTH_CLIENT_SECRET', ''),
    rtrim((string) $config->get('app.url'), '/') . '/auth/github/callback',
));

$container->set(DeployService::class, static fn (Container $c): DeployService => new DeployService(
    $c->get(Database::class),
    $c->get(SiteRepository::class),
    $c->get(JobQueue::class),
    $c->get(GitHubApp::class),
));

$container->set(PromotionService::class, static fn (Container $c): PromotionService => new PromotionService(
    $c->get(Database::class),
    $c->get(SiteRepository::class),
    $c->get(GitHubApp::class),
));

// Middleware
$container->set(RequireAuth::class, static fn (Container $c): RequireAuth => new RequireAuth(
    $c->get(UserRepository::class),
));
$container->set(VerifyCsrf::class, static fn (): VerifyCsrf => new VerifyCsrf());

// Controllers
$container->set(AuthController::class, static fn (Container $c): AuthController => new AuthController(
    $c->get(GitHubOAuth::class),
    $c->get(UserRepository::class),
    $c->get(View::class),
));

$container->set(DashboardController::class, static fn (Container $c): DashboardController => new DashboardController(
    $c->get(Database::class),
    $c->get(SiteRepository::class),
    $c->get(NodeRepository::class),
    $c->get(JobQueue::class),
    $c->get(View::class),
));

$container->set(SiteController::class, static fn (Container $c): SiteController => new SiteController(
    $c->get(Database::class),
    $c->get(SiteRepository::class),
    $c->get(NodeRepository::class),
    $c->get(JobQueue::class),
    $c->get(DeployService::class),
    $c->get(PromotionService::class),
    $c->get(GitHubApp::class),
    $c->get(View::class),
));

$container->set(WebhookController::class, static fn (Container $c): WebhookController => new WebhookController(
    $c->get(Database::class),
    $c->get(DeployService::class),
    $c->get(Crypto::class),
));

$container->set(Router::class, static function (): Router {
    $routes = require __DIR__ . '/routes.php';

    return $routes(new Router());
});

return $container;

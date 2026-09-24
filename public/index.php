<?php
declare(strict_types=1);

use TripleR\Controllers\AuthController;
use TripleR\Controllers\MagicLinkController;
use TripleR\Controllers\SmsWebhookController;
use TripleR\Controllers\StaffNotificationController;
use TripleR\Database;
use TripleR\Http\Request;
use TripleR\Http\Response;
use TripleR\Http\Router;
use TripleR\Repositories\InboundSmsEventRepository;
use TripleR\Repositories\MagicLinkRepository;
use TripleR\Repositories\NotificationRepository;
use TripleR\Repositories\StaffUserRepository;
use TripleR\Security\Csrf;
use TripleR\Security\StaffAuth;
use TripleR\Services\RateLimiter;
use TripleR\Services\MagicLinkService;
use TripleR\Services\NotificationService;
use TripleR\Services\SmsMessageCipher;

require dirname(__DIR__) . '/app/bootstrap.php';

$request = Request::fromGlobals();
$isSmsWebhook = $request->method === 'POST' && in_array($request->path, ['/webhooks/sms/inbound', '/webhooks/sms/delivery'], true);
if ($isSmsWebhook) {
    if (!SmsWebhookController::validSignature($request)) {
        Response::json(['received' => true])->send();
    }
    try {
        $db = Database::connection();
        $webhooks = new SmsWebhookController(new InboundSmsEventRepository($db), new NotificationRepository($db));
        $response = $request->path === '/webhooks/sms/inbound'
            ? $webhooks->inbound($request)
            : $webhooks->delivery($request);
        $response->send();
    } catch (\Throwable $error) {
        error_log('SMS callback could not be persisted: ' . get_class($error));
        Response::json(['received' => true])->send();
    }
}

Csrf::startSession();

try {
    $db = Database::connection();
    $users = new StaffUserRepository($db);
    $auth = new StaffAuth($users);
    $notificationRepository = new NotificationRepository($db);
    $inboundRepository = new InboundSmsEventRepository($db);
    $authController = new AuthController($users, $auth, new RateLimiter($db));
    $messageCipher = new SmsMessageCipher();
    $staffNotifications = new StaffNotificationController($auth, $notificationRepository, $messageCipher);
    $webhooks = new SmsWebhookController($inboundRepository, $notificationRepository);
    $magicLinkService = new MagicLinkService(
        new MagicLinkRepository($db),
        new RateLimiter($db),
        new NotificationService($notificationRepository, $inboundRepository, $messageCipher),
    );
    $magicLinks = new MagicLinkController($magicLinkService);

    $router = new Router();
    $router->get('/', static fn (Request $request): Response => Response::redirect('/staff/notifications'));
    $router->get('/staff/login', static fn (): Response => $authController->showLogin());
    $router->post('/staff/login', static fn (Request $request): Response => $authController->login($request));
    $router->post('/staff/logout', static fn (Request $request): Response => $authController->logout($request));
    $router->get('/staff', static fn (): Response => Response::redirect('/staff/notifications'));
    $router->get('/staff/notifications', static fn (): Response => $staffNotifications->index());
    $router->get('/api/staff/notifications', static fn (Request $request): Response => $staffNotifications->history($request));
    $router->get('/magic-link', static fn (): Response => $magicLinks->page());
    $router->post('/api/magic-links/redeem', static fn (Request $request): Response => $magicLinks->redeem($request));
    $router->get('/api/magic-links/session', static fn (Request $request): Response => $magicLinks->sessionContext($request));
    $router->post('/webhooks/sms/inbound', static fn (Request $request): Response => $webhooks->inbound($request));
    $router->post('/webhooks/sms/delivery', static fn (Request $request): Response => $webhooks->delivery($request));

    $router->dispatch($request)->send();
} catch (\Throwable $error) {
    error_log('Application request failed: ' . get_class($error));
    Response::json(['error' => 'The request could not be completed.'], 500)->send();
}

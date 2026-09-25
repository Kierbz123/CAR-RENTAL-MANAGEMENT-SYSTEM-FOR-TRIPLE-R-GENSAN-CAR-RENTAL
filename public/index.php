<?php
declare(strict_types=1);

use TripleR\Controllers\AuthController;
use TripleR\Controllers\Admin\SessionController;
use TripleR\Controllers\Admin\UserController;
use TripleR\Controllers\MagicLinkController;
use TripleR\Controllers\SmsWebhookController;
use TripleR\Controllers\StaffNotificationController;
use TripleR\Controllers\StaffHomeController;
use TripleR\Controllers\Fleet\VehicleController;
use TripleR\Controllers\Fleet\DriverController;
use TripleR\Controllers\Customers\CustomerController;
use TripleR\Controllers\Rentals\AgreementController;
use TripleR\Controllers\Api\RentalApiController;
use TripleR\Database;
use TripleR\Http\Request;
use TripleR\Http\Response;
use TripleR\Http\Router;
use TripleR\Http\AuthMiddleware;
use TripleR\Repositories\InboundSmsEventRepository;
use TripleR\Repositories\MagicLinkRepository;
use TripleR\Repositories\NotificationRepository;
use TripleR\Repositories\SecurityLogRepository;
use TripleR\Repositories\SessionRepository;
use TripleR\Repositories\StaffUserRepository;
use TripleR\Repositories\VehicleLocationRepository;
use TripleR\Repositories\VehicleRepository;
use TripleR\Repositories\VehicleStatusLogRepository;
use TripleR\Repositories\DriverRepository;
use TripleR\Repositories\CustomerRepository;
use TripleR\Repositories\RentalRepository;
use TripleR\Repositories\ChargeRepository;
use TripleR\Repositories\RulesAcceptanceRepository;
use TripleR\Security\Csrf;
use TripleR\Security\StaffAuth;
use TripleR\Services\RateLimiter;
use TripleR\Services\AuthService;
use TripleR\Services\MagicLinkService;
use TripleR\Services\NotificationService;
use TripleR\Services\SmsMessageCipher;
use TripleR\Services\VehiclePhotoService;
use TripleR\Services\VehicleService;
use TripleR\Services\DriverPiiCipher;
use TripleR\Services\DriverService;
use TripleR\Services\CustomerPiiCipher;
use TripleR\Services\CustomerService;
use TripleR\Services\BookingOverlapService;
use TripleR\Services\RentalService;

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
    $sessions = new SessionRepository($db);
    $securityLogs = new SecurityLogRepository($db);
    $authService = new AuthService($db, $users, $sessions, $securityLogs, new RateLimiter($db));
    $auth = new StaffAuth($authService);
    $authMiddleware = new AuthMiddleware($auth);
    $notificationRepository = new NotificationRepository($db);
    $inboundRepository = new InboundSmsEventRepository($db);
    $authController = new AuthController($authService, $auth);
    $messageCipher = new SmsMessageCipher();
    $staffNotifications = new StaffNotificationController($authMiddleware, $notificationRepository, $messageCipher);
    $staffHome = new StaffHomeController($authMiddleware);
    $userAdmin = new UserController($db, $authMiddleware, $users, $sessions, $securityLogs);
    $sessionAdmin = new SessionController($db, $authMiddleware, $users, $sessions, $securityLogs);
    $webhooks = new SmsWebhookController($inboundRepository, $notificationRepository);
    $rulesAcceptanceRepository = new RulesAcceptanceRepository($db);
    $notificationService = new NotificationService($notificationRepository, $inboundRepository, $messageCipher, $rulesAcceptanceRepository);
    $magicLinkService = new MagicLinkService(
        new MagicLinkRepository($db),
        new RateLimiter($db),
        $notificationService,
    );
    $magicLinks = new MagicLinkController($magicLinkService);
    $vehicleRepository = new VehicleRepository($db);
    $vehicleLocations = new VehicleLocationRepository($db);
    $vehicleService = new VehicleService($db, $vehicleRepository, new VehicleStatusLogRepository($db));
    $vehiclePhotos = new VehiclePhotoService($db);
    $fleetVehicles = new VehicleController($authMiddleware, $vehicleRepository, $vehicleLocations, $vehicleService, $vehiclePhotos);
    $driverRepository = new DriverRepository($db);
    $driverPiiCipher = new DriverPiiCipher();
    $driverService = new DriverService($db, $driverRepository, $driverPiiCipher);
    $fleetDrivers = new DriverController($authMiddleware, $driverRepository, $driverService, $driverPiiCipher);
    $customerRepository = new CustomerRepository($db);
    $customerPiiCipher = new CustomerPiiCipher();
    $customerService = new CustomerService($db, $customerRepository, $customerPiiCipher);
    $customerController = new CustomerController($authMiddleware, $customerRepository, $customerService, $customerPiiCipher);
    $rentalRepository = new RentalRepository($db, new BookingOverlapService($db));
    $chargeRepository = new ChargeRepository($db);
    $rentalService = new RentalService($db, $rentalRepository, $chargeRepository, $vehicleRepository, $customerRepository, $customerPiiCipher, $vehicleService, $notificationService, $magicLinkService);
    $agreements = new AgreementController($authMiddleware, $rentalRepository, $chargeRepository, $rentalService);
    $rentalApi = new RentalApiController($authMiddleware, $rentalService, $magicLinkService);

    $router = new Router();
    $router->get('/', static fn (Request $request): Response => Response::redirect('/staff'));
    $router->get('/staff/login', static fn (): Response => $authController->showLogin());
    $router->post('/staff/login', static fn (Request $request): Response => $authController->login($request));
    $router->post('/staff/logout', static fn (Request $request): Response => $authController->logout($request));
    $router->get('/auth/change-password', static fn (): Response => $authController->showChangePassword());
    $router->post('/auth/change-password', static fn (Request $request): Response => $authController->changePassword($request));
    $router->get('/staff', static fn (): Response => $staffHome->index());
    $router->get('/admin/users', static fn (): Response => $userAdmin->index());
    $router->post('/admin/users/create', static fn (Request $request): Response => $userAdmin->create($request));
    $router->get('/admin/users/edit', static fn (Request $request): Response => $userAdmin->edit($request));
    $router->post('/admin/users/update', static fn (Request $request): Response => $userAdmin->update($request));
    $router->post('/admin/users/role', static fn (Request $request): Response => $userAdmin->changeRole($request));
    $router->post('/admin/users/deactivate', static fn (Request $request): Response => $userAdmin->deactivate($request));
    $router->post('/admin/users/reactivate', static fn (Request $request): Response => $userAdmin->reactivate($request));
    $router->post('/admin/users/unlock', static fn (Request $request): Response => $userAdmin->unlock($request));
    $router->post('/admin/users/reset-password', static fn (Request $request): Response => $userAdmin->resetPassword($request));
    $router->get('/admin/sessions', static fn (Request $request): Response => $sessionAdmin->index($request));
    $router->post('/admin/sessions/invalidate', static fn (Request $request): Response => $sessionAdmin->invalidate($request));
    $router->get('/staff/notifications', static fn (): Response => $staffNotifications->index());
    $router->get('/api/staff/notifications', static fn (Request $request): Response => $staffNotifications->history($request));
    $router->get('/magic-link', static fn (): Response => $magicLinks->page());
    $router->post('/api/magic-links/redeem', static fn (Request $request): Response => $magicLinks->redeem($request));
    $router->get('/api/magic-links/session', static fn (Request $request): Response => $magicLinks->sessionContext($request));
    $router->post('/webhooks/sms/inbound', static fn (Request $request): Response => $webhooks->inbound($request));
    $router->post('/webhooks/sms/delivery', static fn (Request $request): Response => $webhooks->delivery($request));
    $router->get('/fleet/vehicles', static fn (Request $request): Response => $fleetVehicles->index($request));
    $router->get('/fleet/vehicles/new', static fn (): Response => $fleetVehicles->createForm());
    $router->get('/fleet/vehicles/edit', static fn (Request $request): Response => $fleetVehicles->editForm($request));
    $router->get('/fleet/vehicles/detail', static fn (Request $request): Response => $fleetVehicles->detail($request));
    $router->post('/fleet/vehicles/create', static fn (Request $request): Response => $fleetVehicles->create($request));
    $router->post('/fleet/vehicles/update', static fn (Request $request): Response => $fleetVehicles->update($request));
    $router->post('/fleet/vehicles/status', static fn (Request $request): Response => $fleetVehicles->status($request));
    $router->post('/fleet/vehicles/mileage', static fn (Request $request): Response => $fleetVehicles->mileage($request));
    $router->post('/fleet/vehicles/photos/upload', static fn (Request $request): Response => $fleetVehicles->uploadPhoto($request));
    $router->get('/fleet/vehicles/photos/show', static fn (Request $request): Response => $fleetVehicles->photo($request));
    $router->get('/fleet/locations', static fn (): Response => $fleetVehicles->locations());
    $router->post('/fleet/locations/create', static fn (Request $request): Response => $fleetVehicles->createLocation($request));
    $router->post('/fleet/locations/retire', static fn (Request $request): Response => $fleetVehicles->retireLocation($request));
    $router->post('/fleet/locations/remove', static fn (Request $request): Response => $fleetVehicles->removeLocation($request));
    $router->get('/fleet/drivers', static fn (Request $request): Response => $fleetDrivers->index($request));
    $router->get('/fleet/drivers/new', static fn (): Response => $fleetDrivers->newForm());
    $router->get('/fleet/drivers/edit', static fn (Request $request): Response => $fleetDrivers->editForm($request));
    $router->get('/fleet/drivers/detail', static fn (Request $request): Response => $fleetDrivers->detail($request));
    $router->post('/fleet/drivers/create', static fn (Request $request): Response => $fleetDrivers->create($request));
    $router->post('/fleet/drivers/update', static fn (Request $request): Response => $fleetDrivers->update($request));
    $router->post('/fleet/drivers/status', static fn (Request $request): Response => $fleetDrivers->status($request));
    $router->post('/fleet/drivers/delete', static fn (Request $request): Response => $fleetDrivers->delete($request));
    $router->post('/fleet/drivers/contacts/add', static fn (Request $request): Response => $fleetDrivers->addContact($request));
    $router->post('/fleet/drivers/contacts/update', static fn (Request $request): Response => $fleetDrivers->updateContact($request));
    $router->post('/fleet/drivers/contacts/remove', static fn (Request $request): Response => $fleetDrivers->removeContact($request));
    $router->post('/fleet/drivers/reveal', static fn (Request $request): Response => $fleetDrivers->reveal($request));
    $router->get('/customers', static fn (Request $request): Response => $customerController->index($request));
    $router->get('/customers/new', static fn (): Response => $customerController->newForm());
    $router->get('/customers/edit', static fn (Request $request): Response => $customerController->editForm($request));
    $router->get('/customers/detail', static fn (Request $request): Response => $customerController->detail($request));
    $router->post('/customers/create', static fn (Request $request): Response => $customerController->create($request));
    $router->post('/customers/update', static fn (Request $request): Response => $customerController->update($request));
    $router->post('/customers/contacts/add', static fn (Request $request): Response => $customerController->addContact($request));
    $router->post('/customers/contacts/update', static fn (Request $request): Response => $customerController->updateContact($request));
    $router->post('/customers/contacts/remove', static fn (Request $request): Response => $customerController->removeContact($request));
    $router->post('/customers/documents/add', static fn (Request $request): Response => $customerController->addDocument($request));
    $router->post('/customers/documents/update', static fn (Request $request): Response => $customerController->updateDocument($request));
    $router->post('/customers/notes/add', static fn (Request $request): Response => $customerController->addNote($request));
    $router->post('/customers/blacklist', static fn (Request $request): Response => $customerController->blacklist($request));
    $router->post('/customers/unblacklist', static fn (Request $request): Response => $customerController->unblacklist($request));
    $router->post('/customers/delete', static fn (Request $request): Response => $customerController->softDelete($request));
    $router->post('/customers/reveal', static fn (Request $request): Response => $customerController->reveal($request));
    $router->get('/rentals', static fn (Request $request): Response => $agreements->index($request));
    $router->get('/rentals/new', static fn (): Response => $agreements->newForm());
    $router->get('/rentals/detail', static fn (Request $request): Response => $agreements->detail($request));
    $router->post('/rentals/reserve', static fn (Request $request): Response => $agreements->create($request));
    $router->post('/rentals/action', static fn (Request $request): Response => $agreements->action($request));
    $router->post('/rentals/charge', static fn (Request $request): Response => $agreements->addCharge($request));
    $router->post('/rentals/charge/reverse', static fn (Request $request): Response => $agreements->reverseCharge($request));
    $router->post('/rentals/deposit', static fn (Request $request): Response => $agreements->deposit($request));
    $router->post('/rentals/link', static fn (Request $request): Response => $agreements->issueLink($request));
    $router->post('/api/rentals', static fn (Request $request): Response => $rentalApi->create($request));
    $router->get('/api/rentals/booking-context', static fn (Request $request): Response => $rentalApi->bookingContext($request));
    $router->get('/customer/booking', static function (): Response { ob_start(); require APP_ROOT . '/app/Views/customer/booking.php'; return Response::html((string) ob_get_clean()); });

    $router->dispatch($request)->send();
} catch (\Throwable $error) {
    error_log('Application request failed: ' . get_class($error));
    Response::json(['error' => 'The request could not be completed.'], 500)->send();
}

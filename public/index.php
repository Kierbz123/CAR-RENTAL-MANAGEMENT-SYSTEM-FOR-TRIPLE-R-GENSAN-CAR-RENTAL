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
use TripleR\Controllers\Fleet\TrackingController;
use TripleR\Controllers\TrackerAppController;
use TripleR\Controllers\Fleet\DriverController;
use TripleR\Controllers\Customers\CustomerController;
use TripleR\Controllers\CustomerBookingController;
use TripleR\Controllers\DemoCheckoutController;
use TripleR\Controllers\PublicBookingController;
use TripleR\Controllers\Rentals\PaymentController;
use TripleR\Repositories\PaymentProofRepository;
use TripleR\Repositories\PaymentRepository;
use TripleR\Services\OnlineBookingService;
use TripleR\Services\PaymentProofService;
use TripleR\Services\PaymentService;
use TripleR\Services\Payments\PaymentGatewayFactory;
use TripleR\Services\Payments\SimulatedGateway;
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
use TripleR\Repositories\DashboardRepository;
use TripleR\Support\Navigation;
use TripleR\Repositories\RentalRepository;
use TripleR\Repositories\ChargeRepository;
use TripleR\Repositories\DamageReportRepository;
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
use TripleR\Services\VehicleTrackingService;
use TripleR\Repositories\VehicleTrackingRepository;
use TripleR\Services\DriverPiiCipher;
use TripleR\Services\DriverService;
use TripleR\Services\CustomerPiiCipher;
use TripleR\Services\CustomerService;
use TripleR\Services\BookingOverlapService;
use TripleR\Services\ChauffeurService;
use TripleR\Services\RentalService;
use TripleR\Services\DamageService;
use TripleR\Services\TelegramLinkService;

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

// Past post_max_size PHP drops the whole form, which would otherwise fail the CSRF check and
// show "Invalid request token" for what is really a file that is too large.
$postLimit = (static function (string $setting): int {
    $value = (int) $setting;
    return match (strtolower(substr(trim($setting), -1))) { 'g' => $value * 1073741824, 'm' => $value * 1048576, 'k' => $value * 1024, default => $value };
})((string) ini_get('post_max_size'));
if ($request->method === 'POST' && $postLimit > 0 && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $postLimit) {
    $tooLarge = 'The file is too large to upload. Photos and screenshots can be at most 8 MB. Go back and choose a smaller file.';
    (str_starts_with($request->path, '/api/') ? Response::json(['error' => $tooLarge], 413) : Response::html($tooLarge, 413))->send();
}

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
    $userAdmin = new UserController($db, $authMiddleware, $users, $sessions, $securityLogs);
    $sessionAdmin = new SessionController($db, $authMiddleware, $users, $sessions, $securityLogs);
    $webhooks = new SmsWebhookController($inboundRepository, $notificationRepository);
    $rulesAcceptanceRepository = new RulesAcceptanceRepository($db);
    $telegramLinks = TelegramLinkService::create($db);
    $notificationService = new NotificationService($notificationRepository, $inboundRepository, $messageCipher, $rulesAcceptanceRepository, $telegramLinks);
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
    $driverRepository = new DriverRepository($db);
    $driverPiiCipher = new DriverPiiCipher();
    $driverService = new DriverService($db, $driverRepository, $driverPiiCipher);
    $fleetDrivers = new DriverController($authMiddleware, $driverRepository, $driverService, $driverPiiCipher);
    $customerRepository = new CustomerRepository($db);
    $customerPiiCipher = new CustomerPiiCipher();
    $customerService = new CustomerService($db, $customerRepository, $customerPiiCipher);
    $customerController = new CustomerController($authMiddleware, $customerRepository, $customerService, $customerPiiCipher, $telegramLinks);
    $bookingOverlapService = new BookingOverlapService($db);
    $rentalRepository = new RentalRepository($db, $bookingOverlapService);
    $chargeRepository = new ChargeRepository($db);
    $vehicleTracking = new VehicleTrackingService(new VehicleTrackingRepository($db), $rentalRepository, new RateLimiter($db), $securityLogs);
    $fleetVehicles = new VehicleController($authMiddleware, $vehicleRepository, $vehicleLocations, $vehicleService, $vehiclePhotos, $vehicleTracking);
    $fleetTracking = new TrackingController($authMiddleware, $vehicleTracking, $rentalRepository);
    $trackerApp = new TrackerAppController($vehicleTracking);
    $chauffeurService = new ChauffeurService($db, $rentalRepository, $chargeRepository, $vehicleRepository, $bookingOverlapService);
    $paymentRepository = new PaymentRepository($db);
    $rentalService = new RentalService($db, $rentalRepository, $chargeRepository, $vehicleRepository, $customerRepository, $customerPiiCipher, $vehicleService, $notificationService, $magicLinkService, $chauffeurService, $paymentRepository);
    $damageService = new DamageService($db, new DamageReportRepository($db), $rentalRepository, $vehiclePhotos, $rentalService);
    $paymentProofs = new PaymentProofRepository($db);
    $paymentProofService = new PaymentProofService($db, $paymentProofs, $rentalRepository, $rentalService, $vehiclePhotos, new RateLimiter($db), $paymentRepository);
    // Online payment goes through the gateway named by PAYMENT_GATEWAY; null switches it off.
    $paymentGateway = PaymentGatewayFactory::create();
    $paymentService = new PaymentService($db, $paymentRepository, $rentalRepository, $rentalService, $paymentProofs, $rulesAcceptanceRepository, new RateLimiter($db), $securityLogs, $paymentGateway);
    $payments = new PaymentController($authMiddleware, $paymentProofs, $paymentProofService, $paymentRepository);
    $demoCheckout = new DemoCheckoutController($magicLinkService, $paymentService, $paymentGateway instanceof SimulatedGateway ? $paymentGateway : null);
    $onlineBookings = new PublicBookingController(new OnlineBookingService($db, $rentalService, $rentalRepository, $vehicleRepository, $bookingOverlapService, $customerService, $rulesAcceptanceRepository, new RateLimiter($db), $notificationService));
    $customerBooking = new CustomerBookingController($magicLinkService, $rentalRepository, $rentalService, $paymentProofs, $paymentProofService, $rulesAcceptanceRepository, $paymentRepository, $paymentService);
    $staffHome = new StaffHomeController($authMiddleware, new DashboardRepository($db), $paymentProofs);
    $damageController = new \TripleR\Controllers\Rentals\DamageController($authMiddleware, $damageService);
    $agreements = new AgreementController($authMiddleware, $rentalRepository, $chargeRepository, $rentalService, $chauffeurService, $driverService, $damageService, $paymentProofs, $rulesAcceptanceRepository, $paymentRepository, $paymentService);
    $driverAssignments = new \TripleR\Controllers\Rentals\DriverAssignmentController($authMiddleware, $chauffeurService);
    $rentalApi = new RentalApiController($authMiddleware, $rentalService, $magicLinkService);

    $router = new Router();
    $router->get('/', static function () use ($auth): Response {
        if ($auth->user() !== null) {
            return Response::redirect('/staff');
        }
        ob_start();
        require APP_ROOT . '/app/Views/public/landing.php';
        return Response::html((string) ob_get_clean());
    });
    $router->get('/api/staff/navigation', static function () use ($authMiddleware): Response {
        $user = $authMiddleware->requireAuthenticated(true);
        if ($user instanceof Response) {
            return $user;
        }
        $role = (string) $user['role'];
        return Response::json([
            'user' => ['email' => (string) $user['email'], 'role' => $role],
            'csrf' => Csrf::token(),
            'items' => Navigation::flatFor($role),
        ]);
    });
    $router->get('/staff/login', static fn (): Response => $authController->showLogin());
    $router->post('/staff/login', static fn (Request $request): Response => $authController->login($request));
    $router->post('/staff/logout', static fn (Request $request): Response => $authController->logout($request));
    $router->get('/auth/change-password', static fn (): Response => $authController->showChangePassword());
    $router->post('/auth/change-password', static fn (Request $request): Response => $authController->changePassword($request));
    $router->get('/staff', static fn (): Response => $staffHome->index());
    $router->get('/staff/booking-qr', static fn (): Response => $staffHome->bookingQr());
    $router->get('/book', static fn (Request $request): Response => $onlineBookings->form($request));
    $router->post('/book', static fn (Request $request): Response => $onlineBookings->submit($request));
    $router->post('/book/verify', static fn (Request $request): Response => $onlineBookings->verify($request));
    $router->get('/book/find', static fn (): Response => $onlineBookings->findForm());
    $router->post('/book/find', static fn (Request $request): Response => $onlineBookings->find($request));
    $router->get('/payments', static fn (): Response => $payments->index());
    $router->post('/payments/verify', static fn (Request $request): Response => $payments->verify($request));
    $router->post('/payments/reject', static fn (Request $request): Response => $payments->reject($request));
    $router->get('/payments/proof', static fn (Request $request): Response => $payments->screenshot($request));
    $router->get('/payments/receipt', static fn (Request $request): Response => $payments->receipt($request));
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
    $router->get('/api/fleet/positions', static fn (): Response => $fleetTracking->positions());
    $router->get('/fleet/tracking/connect', static fn (Request $request): Response => $fleetTracking->connectForm($request));
    $router->post('/fleet/tracking/connect', static fn (Request $request): Response => $fleetTracking->connect($request));
    $router->post('/fleet/tracking/disconnect', static fn (Request $request): Response => $fleetTracking->disconnect($request));
    $router->get('/track', static fn (): Response => $trackerApp->page());
    $router->get('/track/manifest.json', static fn (): Response => $trackerApp->manifest());
    $router->get('/api/tracking/session', static fn (Request $request): Response => $trackerApp->session($request));
    $router->post('/api/tracking/report', static fn (Request $request): Response => $trackerApp->report($request));
    $router->get('/fleet/drivers', static fn (Request $request): Response => $fleetDrivers->index($request));
    $router->get('/fleet/drivers/new', static fn (): Response => $fleetDrivers->newForm());
    $router->get('/fleet/drivers/edit', static fn (Request $request): Response => $fleetDrivers->editForm($request));
    $router->get('/fleet/drivers/detail', static fn (Request $request): Response => $fleetDrivers->detail($request));
    $router->post('/fleet/drivers/create', static fn (Request $request): Response => $fleetDrivers->create($request));
    $router->post('/fleet/drivers/update', static fn (Request $request): Response => $fleetDrivers->update($request));
    $router->post('/fleet/drivers/status', static fn (Request $request): Response => $fleetDrivers->status($request));
    $router->post('/fleet/drivers/delete', static fn (Request $request): Response => $fleetDrivers->delete($request));
    $router->post('/fleet/drivers/restore', static fn (Request $request): Response => $fleetDrivers->restore($request));
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
    $router->post('/customers/restore', static fn (Request $request): Response => $customerController->restore($request));
    $router->post('/customers/reveal', static fn (Request $request): Response => $customerController->reveal($request));
    $router->post('/customers/telegram/code', static fn (Request $request): Response => $customerController->telegramCode($request));
    $router->post('/customers/telegram/disconnect', static fn (Request $request): Response => $customerController->telegramDisconnect($request));
    $router->get('/api/customers/telegram/status', static fn (Request $request): Response => $customerController->telegramStatus($request));
    $router->get('/rentals', static fn (Request $request): Response => $agreements->index($request));
    $router->get('/rentals/new', static fn (): Response => $agreements->newForm());
    $router->get('/rentals/detail', static fn (Request $request): Response => $agreements->detail($request));
    $router->post('/rentals/reserve', static fn (Request $request): Response => $agreements->create($request));
    $router->post('/rentals/action', static fn (Request $request): Response => $agreements->action($request));
    $router->post('/rentals/driver/assign', static fn (Request $request): Response => $driverAssignments->assign($request));
    $router->post('/rentals/driver/remove', static fn (Request $request): Response => $driverAssignments->remove($request));
    $router->post('/rentals/charge', static fn (Request $request): Response => $agreements->addCharge($request));
    $router->post('/rentals/charge/reverse', static fn (Request $request): Response => $agreements->reverseCharge($request));
    $router->post('/rentals/deposit', static fn (Request $request): Response => $agreements->deposit($request));
    $router->post('/rentals/downpayment', static fn (Request $request): Response => $agreements->downpayment($request));
    $router->post('/rentals/payment', static fn (Request $request): Response => $agreements->balance($request));
    $router->post('/rentals/damage/report', static fn (Request $request): Response => $damageController->record($request));
    $router->post('/rentals/damage/liability', static fn (Request $request): Response => $damageController->decide($request));
    $router->post('/rentals/damage/charge', static fn (Request $request): Response => $damageController->postCharge($request));
    $router->get('/rentals/damage/photo', static fn (Request $request): Response => $damageController->photo($request));
    $router->get('/rentals/damage/detail', static fn (Request $request): Response => $damageController->detail($request));
    $router->post('/rentals/link', static fn (Request $request): Response => $agreements->issueLink($request));
    $router->post('/api/rentals', static fn (Request $request): Response => $rentalApi->create($request));
    $router->get('/api/rentals/booking-context', static fn (Request $request): Response => $rentalApi->bookingContext($request));
    $router->get('/customer/booking', static fn (): Response => $customerBooking->page());
    $router->post('/customer/booking/proof', static fn (Request $request): Response => $customerBooking->submitProof($request));
    $router->post('/customer/booking/pay', static fn (Request $request): Response => $customerBooking->pay($request));
    $router->get('/customer/booking/payment', static fn (Request $request): Response => $customerBooking->payment($request));
    $router->get('/pay/demo', static fn (Request $request): Response => $demoCheckout->page($request));
    $router->post('/pay/demo', static fn (Request $request): Response => $demoCheckout->submit($request));
    $router->post('/webhooks/payments', static fn (Request $request): Response => $demoCheckout->webhook($request));

    $router->dispatch($request)->send();
} catch (\Throwable $error) {
    error_log('Application request failed: ' . get_class($error));
    $wantsPage = $request->method === 'GET' && !str_starts_with($request->path, '/api/');
    ($wantsPage
        ? Response::html('Something went wrong on our side and the page could not be loaded. Please try again in a moment.', 500)
        : Response::json(['error' => 'The request could not be completed.'], 500)
    )->send();
}

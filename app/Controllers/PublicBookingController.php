<?php
declare(strict_types=1);

namespace TripleR\Controllers;

use RuntimeException;
use TripleR\Http\Request;
use TripleR\Http\Response;
use TripleR\Security\Csrf;
use TripleR\Security\CustomerBookingAccess;
use TripleR\Services\OnlineBookingService;

/** The public booking pages: choose dates and a vehicle, enter details, accept the policy. No sign-in. */
final class PublicBookingController
{
    public function __construct(private readonly OnlineBookingService $bookings)
    {
    }

    public function form(Request $request): Response
    {
        $start = is_string($request->query['start_date'] ?? null) ? trim($request->query['start_date']) : '';
        $end = is_string($request->query['end_date'] ?? null) ? trim($request->query['end_date']) : '';
        return $this->render(['start_date' => $start, 'end_date' => $end], null);
    }

    public function submit(Request $request): Response
    {
        if (!Csrf::valid($request)) {
            return $this->render($request->form, 'This page was open for a long time. Please check your details and send the booking again.', 403);
        }
        try {
            $agreementId = $this->bookings->book($request->form, $request->ip, $request->userAgent);
        } catch (RuntimeException $error) {
            return $this->render($request->form, $error->getMessage(), 422);
        }
        CustomerBookingAccess::grant($agreementId);
        $_SESSION['_booking_notice'] = 'Your vehicle is reserved. Pay the downpayment within 24 hours to keep it.';
        return Response::redirect('/customer/booking');
    }

    public function findForm(): Response
    {
        return $this->renderFind([], null);
    }

    public function find(Request $request): Response
    {
        if (!Csrf::valid($request)) {
            return $this->renderFind($request->form, 'This page was open for a long time. Please try again.', 403);
        }
        try {
            $agreementId = $this->bookings->findBooking((string) ($request->form['reference'] ?? ''), (string) ($request->form['phone'] ?? ''), $request->ip);
        } catch (RuntimeException $error) {
            return $this->renderFind($request->form, $error->getMessage(), 429);
        }
        if ($agreementId === null) {
            // One message whatever was wrong, so the page never confirms that a reference exists.
            return $this->renderFind($request->form, 'We couldn’t find a booking with that reference and mobile number. Check both and try again.', 422);
        }
        CustomerBookingAccess::grant($agreementId);
        return Response::redirect('/customer/booking');
    }

    private function render(array $values, ?string $error, int $status = 200): Response
    {
        $start = (string) ($values['start_date'] ?? '');
        $end = (string) ($values['end_date'] ?? '');
        $vehicles = null;
        $period = null;
        $policy = null;
        try {
            $policy = $this->bookings->policy();
            if ($start !== '' || $end !== '') {
                $period = $this->bookings->dates($start, $end);
                $vehicles = $this->bookings->availableVehicles($start, $end);
            }
        } catch (RuntimeException $problem) {
            $error ??= $problem->getMessage();
        }
        $pickupTimes = $this->bookings->pickupTimes();
        $csrfToken = Csrf::token();
        ob_start();
        require APP_ROOT . '/app/Views/public/book.php';
        return Response::html((string) ob_get_clean(), $status);
    }

    private function renderFind(array $values, ?string $error, int $status = 200): Response
    {
        $csrfToken = Csrf::token();
        ob_start();
        require APP_ROOT . '/app/Views/public/book-find.php';
        return Response::html((string) ob_get_clean(), $status);
    }
}

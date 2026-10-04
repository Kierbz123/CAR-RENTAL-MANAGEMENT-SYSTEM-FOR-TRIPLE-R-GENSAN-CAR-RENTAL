<?php
declare(strict_types=1);

namespace TripleR\Controllers;

use RuntimeException;
use TripleR\Http\Request;
use TripleR\Http\Response;
use TripleR\Security\Csrf;
use TripleR\Security\BookingPhoneVerification;
use TripleR\Security\CustomerBookingAccess;
use TripleR\Services\OnlineBookingService;
use TripleR\Services\PhoneVerificationRequired;
use TripleR\Support\SiteProfile;

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
        $class = is_string($request->query['class'] ?? null) ? trim($request->query['class']) : '';
        return $this->render(['start_date' => $start, 'end_date' => $end, 'class' => $class], null);
    }

    public function submit(Request $request): Response
    {
        if (!Csrf::valid($request)) {
            return $this->render(self::fields($request), 'This page was open for a long time. Please check your details and send the booking again.', 403);
        }
        return $this->complete(self::fields($request), $request);
    }

    /** The code texted to the visitor's mobile number: confirm it and finish the booking, or send a new one. */
    public function verify(Request $request): Response
    {
        $form = BookingPhoneVerification::heldForm();
        if ($form === null) {
            return Response::redirect('/book');
        }
        if (!Csrf::valid($request)) {
            return $this->renderVerify(null, 'This page was open for a long time. Please enter the code again.', 403);
        }
        if ((self::fields($request)['resend'] ?? '') === '1') {
            try {
                $this->bookings->sendPhoneCode((string) ($form['phone'] ?? ''), $request->ip);
            } catch (RuntimeException $error) {
                return $this->renderVerify(null, $error->getMessage(), 429);
            }
            return $this->renderVerify('A new code is on its way.', null);
        }
        if (!BookingPhoneVerification::confirm((string) (self::fields($request)['code'] ?? ''))) {
            return $this->renderVerify(null, BookingPhoneVerification::pendingPhone() === null
                ? 'That code has expired or was entered wrong too many times. Ask for a new code.'
                : 'That code is not right. Check the text message and try again.', 422);
        }
        BookingPhoneVerification::clearForm();
        return $this->complete($form, $request);
    }

    private function complete(array $form, Request $request): Response
    {
        try {
            $agreementId = $this->bookings->book($form, $request->ip, $request->userAgent);
        } catch (PhoneVerificationRequired $needed) {
            BookingPhoneVerification::holdForm($form);
            try {
                $this->bookings->sendPhoneCode($needed->phone, $request->ip);
            } catch (RuntimeException $error) {
                return $this->render($form, $error->getMessage(), 429);
            }
            return $this->renderVerify(null, null);
        } catch (RuntimeException $error) {
            return $this->render($form, $error->getMessage(), 422);
        }
        CustomerBookingAccess::grant($agreementId);
        $_SESSION['_booking_notice'] = 'Your vehicle is reserved. Pay the downpayment within 24 hours to keep it.';
        return Response::redirect('/customer/booking');
    }

    private function renderVerify(?string $notice, ?string $error, int $status = 200): Response
    {
        $phone = (string) (BookingPhoneVerification::heldForm()['phone'] ?? '');
        $csrfToken = Csrf::token();
        ob_start();
        require APP_ROOT . '/app/Views/public/book-verify.php';
        return Response::html((string) ob_get_clean(), $status);
    }

    public function findForm(): Response
    {
        return $this->renderFind([], null);
    }

    public function find(Request $request): Response
    {
        if (!Csrf::valid($request)) {
            return $this->renderFind(self::fields($request), 'This page was open for a long time. Please try again.', 403);
        }
        try {
            $agreementId = $this->bookings->findBooking((string) (self::fields($request)['reference'] ?? ''), (string) (self::fields($request)['phone'] ?? ''), $request->ip);
        } catch (RuntimeException $error) {
            return $this->renderFind(self::fields($request), $error->getMessage(), 429);
        }
        if ($agreementId === null) {
            // One message whatever was wrong, so the page never confirms that a reference exists.
            return $this->renderFind(self::fields($request), 'We couldn’t find a booking with that reference and mobile number. Check both and try again.', 422);
        }
        CustomerBookingAccess::grant($agreementId);
        return Response::redirect('/customer/booking');
    }

    /** The posted fields as text: a field sent as a list (name[]=...) counts as empty. */
    private static function fields(Request $request): array
    {
        return array_map(static fn (mixed $value): string => is_string($value) ? $value : '', $request->form);
    }

    private function render(array $values, ?string $error, int $status = 200): Response
    {
        $start = (string) ($values['start_date'] ?? '');
        $end = (string) ($values['end_date'] ?? '');
        // The vehicle class chosen on the landing page (config/site.php 'fleet'), or null for every class.
        $class = null;
        foreach ((array) SiteProfile::get('fleet', []) as $candidate) {
            if (is_string($values['class'] ?? null) && ($candidate['slug'] ?? null) === $values['class']) {
                $class = $candidate;
            }
        }
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
        // Only the times still ahead on the pickup date, so a time that has passed today is never offered.
        $pickupTimes = $this->bookings->pickupTimes($period['start'] ?? null);
        $earliestDate = $this->bookings->earliestDate();
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

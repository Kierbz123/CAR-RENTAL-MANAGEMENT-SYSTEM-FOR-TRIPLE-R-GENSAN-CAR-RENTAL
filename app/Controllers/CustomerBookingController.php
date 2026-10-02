<?php
declare(strict_types=1);

namespace TripleR\Controllers;

use RuntimeException;
use TripleR\Http\Request;
use TripleR\Http\Response;
use TripleR\Repositories\PaymentProofRepository;
use TripleR\Repositories\PaymentRepository;
use TripleR\Repositories\RentalRepository;
use TripleR\Repositories\RulesAcceptanceRepository;
use TripleR\Security\Csrf;
use TripleR\Security\CustomerBookingAccess;
use TripleR\Services\MagicLinkService;
use TripleR\Services\PaymentProofService;
use TripleR\Services\PaymentService;
use TripleR\Services\RentalService;
use TripleR\Support\PaymentMethods;

/**
 * The customer's own booking page: what they booked, what to pay, and the ways to pay it
 * (online, by sending a proof, or in cash at the office).
 * Reached from a secure link, or straight after booking, or by reference and phone number.
 */
final class CustomerBookingController
{
    public function __construct(
        private readonly MagicLinkService $links,
        private readonly RentalRepository $agreements,
        private readonly RentalService $rentals,
        private readonly PaymentProofRepository $proofs,
        private readonly PaymentProofService $proofService,
        private readonly RulesAcceptanceRepository $rules,
        private readonly PaymentRepository $payments,
        private readonly PaymentService $paymentService,
    ) {
    }

    public function page(): Response
    {
        $agreementId = CustomerBookingAccess::agreementId($this->links);
        $agreement = $agreementId === null ? null : $this->agreements->find($agreementId);
        $booking = null;
        $proofs = [];
        $payments = [];
        $paymentInProgress = null;
        $acceptance = null;
        $policyToAccept = null;
        $payOnline = false;
        $payOnlineIsDemo = $this->paymentService->onlineIsDemonstration();
        $checkoutUrl = null;
        if ($agreement !== null) {
            $this->payments->expireStale($agreementId);
            $booking = $this->rentals->bookingContext($agreementId) + ['hold_expires_at' => $agreement['hold_expires_at']];
            $proofs = $this->proofs->forAgreement($agreementId);
            $payments = $this->payments->forAgreement($agreementId);
            $paymentInProgress = $this->payments->pendingFor($agreementId);
            $checkoutUrl = $paymentInProgress === null ? null : $this->paymentService->checkoutUrl($paymentInProgress);
            $acceptance = $this->rules->acceptanceForAgreement($agreementId);
            $payOnline = $this->paymentService->onlineAvailable();
            $policyToAccept = $payOnline ? $this->paymentService->policyToAccept($agreementId) : null;
        }
        $onlineMethods = PaymentMethods::online();
        $notice = $_SESSION['_booking_notice'] ?? null;
        $problem = $_SESSION['_booking_problem'] ?? null;
        unset($_SESSION['_booking_notice'], $_SESSION['_booking_problem']);
        $csrfToken = Csrf::token();
        ob_start();
        require APP_ROOT . '/app/Views/customer/booking.php';
        return Response::html((string) ob_get_clean());
    }

    public function submitProof(Request $request): Response
    {
        $agreementId = CustomerBookingAccess::agreementId($this->links);
        if ($agreementId === null) {
            return Response::redirect('/book/find');
        }
        if (!Csrf::valid($request)) {
            $_SESSION['_booking_problem'] = 'This page was open for a long time. Please send your proof again.';
            return Response::redirect('/customer/booking');
        }
        try {
            $file = is_array($request->files['screenshot'] ?? null) ? $request->files['screenshot'] : [];
            $this->proofService->submit($agreementId, (string) ($request->form['reference'] ?? ''), $file, $request->ip);
            $_SESSION['_booking_notice'] = 'Thank you. Your proof of payment was received and the rental office will check it.';
        } catch (RuntimeException $error) {
            $_SESSION['_booking_problem'] = $error->getMessage();
        }
        return Response::redirect('/customer/booking');
    }

    /** Starts an online payment of the downpayment and sends the customer to the checkout. */
    public function pay(Request $request): Response
    {
        $agreementId = CustomerBookingAccess::agreementId($this->links);
        if ($agreementId === null) {
            return Response::redirect('/book/find');
        }
        if (!Csrf::valid($request)) {
            $_SESSION['_booking_problem'] = 'This page was open for a long time. Please choose how to pay again.';
            return Response::redirect('/customer/booking');
        }
        try {
            return Response::redirect($this->paymentService->startOnline(
                $agreementId,
                (string) ($request->form['method'] ?? ''),
                ($request->form['accept_policy'] ?? '') === '1',
                $request->ip,
                $request->userAgent,
            ));
        } catch (RuntimeException $error) {
            $_SESSION['_booking_problem'] = $error->getMessage();
            return Response::redirect('/customer/booking');
        }
    }

    /** What happened to one payment: the receipt when it was paid, the reason when it was not. */
    public function payment(Request $request): Response
    {
        $agreementId = CustomerBookingAccess::agreementId($this->links);
        if ($agreementId === null) {
            return Response::redirect('/book/find');
        }
        $receipt = is_string($request->query['receipt'] ?? null) ? strtoupper(trim($request->query['receipt'])) : '';
        $payment = preg_match('/^[A-Z0-9]{12}$/', $receipt) === 1 ? $this->paymentService->paymentForBooking($receipt, $agreementId) : null;
        if ($payment === null) {
            return Response::redirect('/customer/booking');
        }
        if ($payment['payment_status'] === 'pending') {
            return Response::redirect('/customer/booking');
        }
        $booking = $this->rentals->bookingContext($agreementId);
        ob_start();
        require APP_ROOT . '/app/Views/customer/payment-result.php';
        return Response::html((string) ob_get_clean());
    }
}

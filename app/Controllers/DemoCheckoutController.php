<?php
declare(strict_types=1);

namespace TripleR\Controllers;

use RuntimeException;
use TripleR\Http\Request;
use TripleR\Http\Response;
use TripleR\Security\Csrf;
use TripleR\Security\CustomerBookingAccess;
use TripleR\Services\MagicLinkService;
use TripleR\Services\Payments\SimulatedGateway;
use TripleR\Services\PaymentService;
use TripleR\Support\PaymentMethods;

/**
 * The simulated gateway's own pages. NO REAL MONEY MOVES.
 *
 * The checkout plays the screen a customer would see at a payment gateway, and lets the
 * presenter choose what happens. Whatever is chosen is turned into the gateway's signed result
 * and handed to PaymentService, the same way a real gateway's webhook message would be.
 *
 * It asks for no wallet PIN, one-time code or bank password, and the card form takes only the
 * listed test numbers. A card number is read once to see which test card it is and is never
 * stored or logged; only "Visa ending 4242" is kept.
 */
final class DemoCheckoutController
{
    /** Where the card's brand and last four digits wait between the card form and the bank's verification step. */
    private const CARD_KEY = '_demo_checkout_card';

    public function __construct(
        private readonly MagicLinkService $links,
        private readonly PaymentService $payments,
        private readonly ?SimulatedGateway $gateway,
    ) {
    }

    public function page(Request $request): Response
    {
        $agreementId = CustomerBookingAccess::agreementId($this->links);
        if ($this->gateway === null || $agreementId === null) {
            return Response::redirect($agreementId === null ? '/book/find' : '/customer/booking');
        }
        $receipt = self::receipt($request->query['receipt'] ?? null);
        $payment = $receipt === null ? null : $this->payments->pendingCheckout($receipt, $agreementId);
        if ($payment === null) {
            // Already settled, timed out, or not this booking's: the result page says which.
            return Response::redirect($receipt === null ? '/customer/booking' : '/customer/booking/payment?receipt=' . $receipt);
        }
        $card = $_SESSION[self::CARD_KEY] ?? null;
        $cardToVerify = is_array($card) && ($card['receipt'] ?? '') === $receipt ? (string) $card['detail'] : null;
        $method = PaymentMethods::all()[$payment['method']];
        $banks = PaymentMethods::config()['banks'];
        $testCards = PaymentMethods::config()['test_cards'];
        $problem = $_SESSION['_checkout_problem'] ?? null;
        unset($_SESSION['_checkout_problem']);
        $csrfToken = Csrf::token();
        ob_start();
        require APP_ROOT . '/app/Views/customer/checkout-demo.php';
        return Response::html((string) ob_get_clean());
    }

    public function submit(Request $request): Response
    {
        $agreementId = CustomerBookingAccess::agreementId($this->links);
        if ($this->gateway === null || $agreementId === null) {
            return Response::redirect($agreementId === null ? '/book/find' : '/customer/booking');
        }
        $receipt = self::receipt($request->form['receipt'] ?? null);
        $back = '/pay/demo?receipt=' . ($receipt ?? '');
        if (!Csrf::valid($request)) {
            $_SESSION['_checkout_problem'] = 'This page was open for a long time. Please try again.';
            return Response::redirect($back);
        }
        $payment = $receipt === null ? null : $this->payments->pendingCheckout($receipt, $agreementId);
        if ($payment === null) {
            return Response::redirect($receipt === null ? '/customer/booking' : '/customer/booking/payment?receipt=' . $receipt);
        }
        try {
            [$outcome, $detail] = $this->outcome($payment, $request->form);
        } catch (RuntimeException $error) {
            $_SESSION['_checkout_problem'] = $error->getMessage();
            return Response::redirect($back);
        }
        if ($outcome === null) {
            // An approved test card: the bank's verification step comes next, on the same page.
            return Response::redirect($back);
        }
        unset($_SESSION[self::CARD_KEY]);
        $message = $this->gateway->result($payment, $outcome, $detail);
        $this->payments->handleGatewayResult($message['payload'], $message['signature'], $request->ip, $request->userAgent);
        return Response::redirect('/customer/booking/payment?receipt=' . $receipt);
    }

    /**
     * Where a real gateway would send its result. The simulated checkout hands its result over
     * directly, so this exists to show the same rule from the outside: a message that is not
     * signed with the shared secret changes nothing and is written to the security log.
     */
    public function webhook(Request $request): Response
    {
        try {
            $this->payments->handleGatewayResult($request->rawBody, (string) ($request->header('X-Payment-Signature') ?? ''), $request->ip, $request->userAgent);
        } catch (\Throwable $error) {
            error_log('Payment result could not be applied: ' . get_class($error));
        }
        // The same answer whatever happened, so the reply tells a stranger nothing.
        return Response::json(['received' => true]);
    }

    /**
     * Turns what was chosen on the checkout into an outcome and the detail kept on the payment.
     * Returns [null, null] when the card form was accepted and the verification step is next.
     *
     * @return array{0:?string,1:?string}
     */
    private function outcome(array $payment, array $form): array
    {
        $choice = (string) ($form['outcome'] ?? '');
        if (in_array($choice, ['cancelled', 'timed_out'], true)) {
            return [$choice, null];
        }
        $receipt = (string) $payment['receipt_number'];
        if ($payment['method'] === 'card') {
            if ($choice === 'card') {
                $card = $this->gateway->testCard((string) ($form['card_number'] ?? ''));
                if ($card === null) {
                    throw new RuntimeException('This is a demonstration checkout. Use one of the test card numbers listed below. Any other number is refused and nothing about it is kept.');
                }
                if (preg_match('/^(0[1-9]|1[0-2])\s*\/\s*\d{2}$/', trim((string) ($form['card_expiry'] ?? ''))) !== 1 || preg_match('/^\d{3}$/', trim((string) ($form['card_cvv'] ?? ''))) !== 1) {
                    throw new RuntimeException('Enter an expiry date as MM/YY and a 3-digit security code. Any values will do: this is a demonstration.');
                }
                if ($card['outcome'] !== 'approved') {
                    return [$card['outcome'], $card['detail']];
                }
                $_SESSION[self::CARD_KEY] = ['receipt' => $receipt, 'detail' => $card['detail']];
                return [null, null];
            }
            $waiting = $_SESSION[self::CARD_KEY] ?? null;
            if (!is_array($waiting) || ($waiting['receipt'] ?? '') !== $receipt || !in_array($choice, ['approved', 'verification_failed'], true)) {
                throw new RuntimeException('Enter a test card first.');
            }
            return [$choice, (string) $waiting['detail']];
        }
        if ($payment['method'] === 'online_banking') {
            $bank = (string) ($form['bank'] ?? '');
            if (!in_array($bank, PaymentMethods::config()['banks'], true)) {
                throw new RuntimeException('Choose your bank.');
            }
            if ($choice !== 'approved') {
                throw new RuntimeException('Choose what happens to the payment.');
            }
            return ['approved', $bank];
        }
        // E-wallets.
        if (!in_array($choice, ['approved', 'insufficient'], true)) {
            throw new RuntimeException('Choose what happens to the payment.');
        }
        return [$choice, null];
    }

    private static function receipt(mixed $value): ?string
    {
        $receipt = is_string($value) ? strtoupper(trim($value)) : '';
        return preg_match('/^[A-Z0-9]{12}$/', $receipt) === 1 ? $receipt : null;
    }
}

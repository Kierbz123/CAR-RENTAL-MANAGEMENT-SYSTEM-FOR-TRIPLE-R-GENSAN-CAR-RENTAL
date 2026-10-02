<?php
declare(strict_types=1);

namespace TripleR\Controllers\Rentals;

use RuntimeException;
use TripleR\Http\AuthMiddleware;
use TripleR\Http\Request;
use TripleR\Http\Response;
use TripleR\Repositories\PaymentProofRepository;
use TripleR\Repositories\PaymentRepository;
use TripleR\Security\Csrf;
use TripleR\Services\PaymentProofService;

/** Staff side of payments: the GCash proofs to check and the decision on each, the money received by method, and receipts. */
final class PaymentController
{
    /** Who may see payments, proofs and their screenshots. */
    public const REVIEW = ['system_admin', 'finance_staff', 'auditor'];
    /** Who may decide them, the same roles that handle every other money step. */
    public const DECIDE = ['system_admin', 'finance_staff'];

    public function __construct(
        private readonly AuthMiddleware $guard,
        private readonly PaymentProofRepository $proofs,
        private readonly PaymentProofService $service,
        private readonly PaymentRepository $payments,
    ) {
    }

    public function index(): Response
    {
        $user = $this->guard->requireRoles(self::REVIEW);
        if ($user instanceof Response) {
            return $user;
        }
        $waiting = $this->proofs->awaitingReview();
        $decided = $this->proofs->recentlyDecided();
        $received = $this->payments->receivedByMethod(30);
        $recent = $this->payments->recent(30);
        $canDecide = in_array($user['role'], self::DECIDE, true);
        $notice = $_SESSION['_payment_notice'] ?? null;
        unset($_SESSION['_payment_notice']);
        $csrfToken = Csrf::token();
        ob_start();
        require APP_ROOT . '/app/Views/rentals/payments.php';
        return Response::html((string) ob_get_clean());
    }

    /** One payment, laid out to print or to read back to the customer. */
    public function receipt(Request $request): Response
    {
        $user = $this->guard->requireRoles(self::REVIEW);
        if ($user instanceof Response) {
            return $user;
        }
        $receipt = is_string($request->query['receipt'] ?? null) ? strtoupper(trim($request->query['receipt'])) : '';
        $payment = preg_match('/^[A-Z0-9]{12}$/', $receipt) === 1 ? $this->payments->findByReceipt($receipt) : null;
        if ($payment === null) {
            return Response::html('Payment not found.', 404);
        }
        ob_start();
        require APP_ROOT . '/app/Views/rentals/payment-receipt.php';
        return Response::html((string) ob_get_clean());
    }

    public function verify(Request $request): Response
    {
        return $this->decide($request, fn (int $proofId, int $actor): string => $this->service->verify($proofId, $actor));
    }

    public function reject(Request $request): Response
    {
        return $this->decide($request, function (int $proofId, int $actor) use ($request): string {
            $this->service->reject($proofId, (string) ($request->form['reason'] ?? ''), $actor);
            return 'Proof rejected. The customer has been told why and can send another.';
        });
    }

    public function screenshot(Request $request): Response
    {
        $user = $this->guard->requireRoles(self::REVIEW);
        if ($user instanceof Response) {
            return $user;
        }
        $proofId = filter_var($request->query['proof_id'] ?? null, FILTER_VALIDATE_INT);
        if ($proofId === false || $proofId < 1) {
            return Response::html('Proof not found.', 404);
        }
        try {
            $image = $this->service->screenshot((int) $proofId);
            return new Response($image['body'], 200, ['Content-Type' => $image['mime'], 'Content-Length' => (string) strlen($image['body']), 'Content-Disposition' => 'inline; filename="payment-proof"', 'Cache-Control' => 'private, no-store']);
        } catch (RuntimeException) {
            return Response::html('Proof not found.', 404);
        }
    }

    private function decide(Request $request, callable $decision): Response
    {
        $user = $this->guard->requireRoles(self::DECIDE);
        if ($user instanceof Response) {
            return $user;
        }
        if (!Csrf::valid($request)) {
            return Response::html('Invalid request token.', 403);
        }
        $proofId = filter_var($request->form['proof_id'] ?? null, FILTER_VALIDATE_INT);
        if ($proofId === false || $proofId < 1) {
            return Response::html('Invalid payment proof.', 422);
        }
        $proof = $this->proofs->find((int) $proofId);
        // Decisions made from an agreement's page return there; the others return to the list.
        $backToAgreement = ($request->form['return'] ?? '') === 'agreement' && $proof !== null;
        try {
            $message = $decision((int) $proofId, (int) $user['id']);
        } catch (RuntimeException $error) {
            $message = $error->getMessage();
        }
        $_SESSION[$backToAgreement ? '_rental_notice' : '_payment_notice'] = $message;
        return Response::redirect($backToAgreement ? '/rentals/detail?agreement_id=' . (int) $proof['agreement_id'] . '#downpayment' : '/payments');
    }
}

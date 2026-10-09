<?php
declare(strict_types=1);

namespace TripleR\Controllers\Rentals;

use RuntimeException;
use TripleR\Http\AuthMiddleware;
use TripleR\Http\Request;
use TripleR\Http\Response;
use TripleR\Security\Access;
use TripleR\Security\Csrf;
use TripleR\Services\ChauffeurService;

final class DriverAssignmentController
{
    public function __construct(private readonly AuthMiddleware $guard, private readonly ChauffeurService $chauffeurs) {}

    public function assign(Request $request): Response
    {
        $user = $this->guard->requireRoles(Access::DRIVER_ASSIGN);
        if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.', 403);
        
        $id = $this->id($request->form['agreement_id'] ?? null);
        $driverId = $this->id($request->form['driver_id'] ?? null);
        
        if (!$id) return Response::html('Invalid rental agreement.', 422);
        if (!$driverId) return Response::html('Invalid driver.', 422);

        try {
            $this->chauffeurs->assignDriver($id, $driverId, (int)$user['id']);
            $_SESSION['_rental_notice'] = 'Driver assigned successfully.';
        } catch (RuntimeException $e) {
            $_SESSION['_rental_notice'] = $e->getMessage();
        }
        return Response::redirect('/rentals/detail?agreement_id=' . $id);
    }

    public function remove(Request $request): Response
    {
        $user = $this->guard->requireRoles(Access::DRIVER_ASSIGN);
        if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.', 403);
        
        $id = $this->id($request->form['agreement_id'] ?? null);
        if (!$id) return Response::html('Invalid rental agreement.', 422);

        try {
            $this->chauffeurs->removeDriver($id, (int)$user['id']);
            $_SESSION['_rental_notice'] = 'Driver removed successfully.';
        } catch (RuntimeException $e) {
            $_SESSION['_rental_notice'] = $e->getMessage();
        }
        return Response::redirect('/rentals/detail?agreement_id=' . $id);
    }

    private function id(mixed $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        return $id !== false && $id !== null && $id > 0 ? (int)$id : null;
    }
}

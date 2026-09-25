<?php
declare(strict_types=1);

namespace TripleR\Controllers\Api;

use TripleR\Http\AuthMiddleware;
use TripleR\Http\Request;
use TripleR\Http\Response;
use TripleR\Security\Csrf;
use TripleR\Services\MagicLinkService;
use TripleR\Services\RentalService;

final class RentalApiController
{
    public function __construct(private readonly AuthMiddleware $guard,private readonly RentalService $rentals,private readonly MagicLinkService $magicLinks){}
    public function create(Request $request): Response
    { $user=$this->guard->requireRoles(['system_admin','front_desk'],true);if($user instanceof Response)return $user;if(!Csrf::valid($request))return Response::json(['error'=>'Invalid request token.'],403);$payload=$request->json();try{$id=$this->rentals->create($payload,(int)$user['id']);return Response::json(['agreement_id'=>$id,'status'=>'reserved'],201);}catch(\RuntimeException $e){return Response::json(['error'=>$e->getMessage()],422);} }
    public function bookingContext(Request $request): Response
    { $context=$this->magicLinks->currentSessionContext('booking_manage');if($context===null||$context['booking_id']===null)return Response::json(['error'=>'Booking context is unavailable or expired.'],401);try{return Response::json(['verified'=>true,'booking'=>$this->rentals->bookingContext((int)$context['booking_id'])]);}catch(\RuntimeException){return Response::json(['error'=>'Booking context is unavailable or expired.'],404);} }
}

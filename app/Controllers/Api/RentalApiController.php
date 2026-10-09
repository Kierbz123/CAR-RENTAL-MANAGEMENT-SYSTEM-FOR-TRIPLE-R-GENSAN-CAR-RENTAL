<?php
declare(strict_types=1);

namespace TripleR\Controllers\Api;

use TripleR\Http\AuthMiddleware;
use TripleR\Http\Request;
use TripleR\Http\Response;
use TripleR\Security\Access;
use TripleR\Security\Csrf;
use TripleR\Security\CustomerBookingAccess;
use TripleR\Services\MagicLinkService;
use TripleR\Services\RentalService;

final class RentalApiController
{
    public function __construct(private readonly AuthMiddleware $guard,private readonly RentalService $rentals,private readonly MagicLinkService $magicLinks){}
    public function create(Request $request): Response
    { $user=$this->guard->requireRoles(Access::CUSTOMERS,true);if($user instanceof Response)return $user;if(!Csrf::valid($request))return Response::json(['error'=>'Invalid request token.'],403);$payload=$request->json();try{$made=$this->rentals->createForStaff($payload,(int)$user['id']);$id=$made['id'];
      // The form goes straight to the agreement page, which shows this notice once.
      if($made['driver_error']!==null)$_SESSION['_rental_notice']='Reservation created, but the driver was not assigned: '.$made['driver_error'];
      return Response::json(['agreement_id'=>$id,'status'=>'reserved'],201);}catch(\RuntimeException $e){return Response::json(['error'=>$e->getMessage()],422);} }
    public function bookingContext(Request $request): Response
    { $bookingId=CustomerBookingAccess::agreementId($this->magicLinks);if($bookingId===null)return Response::json(['error'=>'Booking context is unavailable or expired.'],401);try{return Response::json(['verified'=>true,'booking'=>$this->rentals->bookingContext($bookingId)]);}catch(\RuntimeException){return Response::json(['error'=>'Booking context is unavailable or expired.'],404);} }
}

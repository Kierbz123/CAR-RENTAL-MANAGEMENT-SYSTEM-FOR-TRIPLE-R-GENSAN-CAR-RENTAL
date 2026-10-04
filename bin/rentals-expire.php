<?php
declare(strict_types=1);

use TripleR\Database;
use TripleR\Services\RentalRuntimeFactory;

require dirname(__DIR__).'/app/bootstrap.php';
try{$db=Database::connection();$actor=(new \TripleR\Repositories\StaffUserRepository($db))->systemActorId()??false;if($actor===false)throw new RuntimeException('The system account (migration 028) or an active system_admin is required for automated audit events.');$stale=(new \TripleR\Repositories\PaymentRepository($db))->expireStale();$ids=$db->query("SELECT agreement_id FROM rental_agreements WHERE status='reserved' AND downpayment_status<>'received' AND hold_expires_at<=UTC_TIMESTAMP(6) AND NOT EXISTS(SELECT 1 FROM payment_proofs p WHERE p.agreement_id=rental_agreements.agreement_id AND p.proof_status='submitted') AND NOT EXISTS(SELECT 1 FROM payments y WHERE y.pending_agreement_id=rental_agreements.agreement_id) ORDER BY hold_expires_at LIMIT 200")->fetchAll(PDO::FETCH_COLUMN);$service=RentalRuntimeFactory::service($db);$count=0;foreach($ids as $id)if($service->expireReservation((int)$id,(int)$actor))$count++;fwrite(STDOUT,'Expired reservations: '.$count.PHP_EOL.'Checkouts timed out: '.$stale.PHP_EOL);}catch(Throwable $e){fwrite(STDERR,'Reservation expiry failed: '.get_class($e).PHP_EOL);exit(1);}

<?php
declare(strict_types=1);

use TripleR\Database;
use TripleR\Services\RentalRuntimeFactory;

require dirname(__DIR__).'/app/bootstrap.php';
try{$db=Database::connection();$actor=$db->query("SELECT id FROM users WHERE role='system_admin' AND is_active=1 ORDER BY id LIMIT 1")->fetchColumn();if($actor===false)throw new RuntimeException('An active system_admin is required for automated audit events.');$ids=$db->query("SELECT agreement_id FROM rental_agreements WHERE status='reserved' AND hold_expires_at<=UTC_TIMESTAMP(6) ORDER BY hold_expires_at LIMIT 200")->fetchAll(PDO::FETCH_COLUMN);$service=RentalRuntimeFactory::service($db);$count=0;foreach($ids as $id)if($service->expireReservation((int)$id,(int)$actor))$count++;fwrite(STDOUT,'Expired reservations: '.$count.PHP_EOL);}catch(Throwable $e){fwrite(STDERR,'Reservation expiry failed: '.get_class($e).PHP_EOL);exit(1);}

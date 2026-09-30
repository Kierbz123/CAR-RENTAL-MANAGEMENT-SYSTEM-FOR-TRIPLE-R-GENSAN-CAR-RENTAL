<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use TripleR\Database;
use TripleR\Repositories\VehicleRepository;
use TripleR\Repositories\VehicleStatusLogRepository;
use TripleR\Services\RentalRuntimeFactory;
use TripleR\Services\VehicleService;

$db=(new Database())->connection();
$rentals=RentalRuntimeFactory::service($db);
$vehicles=new VehicleRepository($db);
$vehicleService=new VehicleService($db,$vehicles,new VehicleStatusLogRepository($db));
$actorStmt=$db->query("SELECT id FROM users WHERE role='system_admin' AND is_active=1 AND deleted_at IS NULL ORDER BY id LIMIT 1");
$actor=(int)$actorStmt->fetchColumn();
if($actor<1)throw new RuntimeException('Seed an active system_admin before running M5 reconciliation checks.');

$suffix=bin2hex(random_bytes(4));
$customer=$db->prepare("INSERT INTO customers (full_name,customer_type) VALUES (:name,'walk_in')");
$customer->execute(['name'=>'M5 reconciliation '.$suffix]);
$customerId=(int)$db->lastInsertId();
$outcomes=['cancel'=>'cancelled','no_show'=>'no-showed'];
$preservedStatuses=['out_of_service','cleaning','maintenance','retired'];
$cases=0;

foreach($preservedStatuses as $status){
    foreach($outcomes as $action=>$label){
        $plate='M5R-'.$suffix.'-'.strtoupper(substr($status,0,3)).'-'.strtoupper(substr($action,0,2));
        $insert=$db->prepare("INSERT INTO vehicles (plate_number,make,model,model_year,color,body_type,transmission,fuel_type,seating_capacity,daily_rate,current_status) VALUES (:plate,'Test','Regression',2026,'White','sedan','automatic','gasoline',5,1.00,'available')");
        $insert->execute(['plate'=>$plate]);
        $vehicleId=(int)$db->lastInsertId();

        $manila=new DateTimeZone('Asia/Manila');
        if($action==='no_show'){
            $pickup=(new DateTimeImmutable('now',$manila))->modify('-2 days')->setTime(10,0);
            $return=$pickup->modify('+2 hours');
        }else{
            $pickup=(new DateTimeImmutable('now',$manila))->modify('+2 days')->setTime(10,0);
            $return=$pickup->modify('+2 hours');
        }
        $agreementId=$rentals->create([
            'customer_id'=>$customerId,
            'vehicle_id'=>$vehicleId,
            'rental_type'=>'self_drive',
            'start_date'=>$pickup->format('Y-m-d'),
            'end_date'=>$return->format('Y-m-d'),
            'scheduled_pickup_at'=>$pickup->format('Y-m-d\TH:i'),
            'scheduled_return_at'=>$return->format('Y-m-d\TH:i'),
            'deposit_amount'=>'0',
            'hold_minutes'=>60,
        ],$actor);
        $rentals->transition($agreementId,'confirm',$actor);
        $vehicleService->transitionStatus($vehicleId,$status,$actor);
        $rentals->transition($agreementId,$action,$actor,'M5 non-agreement status preservation regression.');

        $read=$db->prepare('SELECT current_status FROM vehicles WHERE vehicle_id=:id');
        $read->execute(['id'=>$vehicleId]);
        $actual=$read->fetchColumn();
        if($actual!==$status)throw new RuntimeException("M5 reconciliation changed manually assigned status {$status} to {$actual} after {$label}.");
        echo "PASS: {$status} is preserved when a confirmed agreement is {$label}.\n";
        $cases++;
    }
}
echo "M5 reconciliation guard passed {$cases} cases.\n";

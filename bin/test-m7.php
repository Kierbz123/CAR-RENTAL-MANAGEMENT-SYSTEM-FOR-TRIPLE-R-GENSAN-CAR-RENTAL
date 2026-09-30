<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

use TripleR\Database;
use TripleR\Repositories\DamageReportRepository;
use TripleR\Repositories\RentalRepository;
use TripleR\Repositories\VehicleRepository;
use TripleR\Services\BookingOverlapService;
use TripleR\Services\DamageService;
use TripleR\Services\RentalRuntimeFactory;
use TripleR\Services\VehiclePhotoService;

$db=Database::connection();$migrationDb=Database::migrationConnection();$actor=1;$rentals=RentalRuntimeFactory::service($db);$overlaps=new BookingOverlapService($db);$rentalRepo=new RentalRepository($db,$overlaps);$damage=new DamageService($db,new DamageReportRepository($db),$rentalRepo,new VehiclePhotoService($db),$rentals);$suffix=bin2hex(random_bytes(4));$today=(new DateTimeImmutable('today',new DateTimeZone('Asia/Manila')))->format('Y-m-d');
$passed=0;
function pass(string $name):void{global $passed;$passed++;echo "PASS: $name\n";}
function reject(callable $fn,string $needle,string $name):void{try{$fn();throw new RuntimeException('Expected rejection: '.$name);}catch(\PDOException $e){if(str_contains(strtolower($e->getMessage()),strtolower($needle))){pass($name);return;}throw $e;}catch(RuntimeException $e){if(str_contains(strtolower($e->getMessage()),strtolower($needle))){pass($name);return;}throw $e;}}
function fixture(string $suffix,string $date):array{global $db,$rentals,$actor;$plate='M7-'.strtoupper(bin2hex(random_bytes(4)));$q=$db->prepare("INSERT INTO vehicles(plate_number,make,model,model_year,color,body_type,transmission,fuel_type,seating_capacity,daily_rate,current_status) VALUES(?, 'Test','Damage',2024,'White','sedan','automatic','gasoline',5,1000,'available')");$q->execute([$plate]);$vehicle=(int)$db->lastInsertId();$q=$db->prepare("INSERT INTO customers(full_name,customer_type) VALUES(?, 'walk_in')");$q->execute(['M7 Test '.$suffix]);$customer=(int)$db->lastInsertId();$agreement=$rentals->create(['customer_id'=>$customer,'vehicle_id'=>$vehicle,'rental_type'=>'self_drive','start_date'=>$date,'end_date'=>$date,'scheduled_pickup_at'=>$date.'T10:00','scheduled_return_at'=>$date.'T18:00','deposit_amount'=>'0'], $actor);return [$agreement,$vehicle,$customer];}

echo "M7 damage reporting acceptance\n";
$preDate=$today;$nextDate=(new DateTimeImmutable($today,new DateTimeZone('Asia/Manila')))->modify('+1 day')->format('Y-m-d');
[$agreement]=fixture($suffix.'a',$preDate);$rentals->transition($agreement,'confirm',$actor);
$clean=$damage->record($agreement,'pre',false,[],[],$actor);pass('Clean pre-inspection may have no photos or damage details');
reject(fn()=>$damage->record($agreement,'pre',false,[],[],$actor),'uq_damage_phase_slot','Only one pre report per agreement');
[$liabilityAgreement]=fixture($suffix.'c',$preDate);$rentals->transition($liabilityAgreement,'confirm',$actor);
$liabilityReport=$damage->record($liabilityAgreement,'pre',true,['location'=>'left rear door','damage_type'=>'dent','severity'=>'minor','repair_cost_suggestion'=>'1250.00'],[],$actor);pass('Damage facts and suggested repair cost recorded');
$first=$damage->decide($liabilityReport,true,'1200.00','Inspection evidence supports customer liability',$actor);pass('Liability decision recorded');
$second=$damage->decide($liabilityReport,true,'1100.00','Corrected after panel-shop estimate',$actor,$first);pass('Correction appends a superseding decision with reason');
reject(fn()=>$damage->decide($liabilityReport,true,'1000.00','Attempt to branch',$actor,$first),'changed','Superseded decision cannot be corrected again');
$damage->postCharge($second,'1000.00','Finance negotiated repair amount',$actor);pass('Finance adjustment uses RentalService damage charge path with audit');
reject(fn()=>$damage->postCharge($second,'1000.00','Duplicate click',$actor),'already been posted','A liability decision cannot produce duplicate charges');
$q=$db->prepare('SELECT COUNT(*) FROM damage_charge_postings p JOIN rental_charges c ON c.charge_id=p.charge_id WHERE p.decision_id=:decision AND c.charge_type=\'damage\' AND c.amount=1000.00');$q->execute(['decision'=>$second]);if((int)$q->fetchColumn()!==1)throw new RuntimeException('Damage charge/posting audit mismatch.');
reject(fn()=>$migrationDb->exec("UPDATE damage_liability_decisions SET liable_amount=1 WHERE decision_id=".(int)$second),'append-only','Liability decisions reject direct UPDATE');
reject(fn()=>$migrationDb->exec("DELETE FROM damage_liability_decisions WHERE decision_id=".(int)$second),'append-only','Liability decisions reject direct DELETE');
reject(fn()=>$migrationDb->exec("UPDATE damage_reports SET notes='changed' WHERE report_id=".(int)$liabilityReport),'append-only','Damage reports reject direct UPDATE');
reject(fn()=>$migrationDb->exec("DELETE FROM damage_reports WHERE report_id=".(int)$liabilityReport),'append-only','Damage reports reject direct DELETE');
reject(fn()=>$migrationDb->exec("UPDATE damage_charge_postings SET approved_amount=1 WHERE decision_id=".(int)$second),'append-only','Damage charge postings reject direct UPDATE');
reject(fn()=>$migrationDb->exec("DELETE FROM damage_charge_postings WHERE decision_id=".(int)$second),'append-only','Damage charge postings reject direct DELETE');
reject(fn()=>$migrationDb->exec("DELETE FROM rental_agreements WHERE agreement_id=".(int)$liabilityAgreement),'foreign key','Damage history restricts agreement deletion');

[$activeAgreement]=fixture($suffix.'b',$nextDate);$rentals->transition($activeAgreement,'confirm',$actor);$rentals->transition($activeAgreement,'pickup',$actor,null,5000);
reject(fn()=>$damage->record($activeAgreement,'during',true,['location'=>'hood','damage_type'=>'scratch','severity'=>'minor'],[],$actor),'at least one photo','During damage requires photo evidence');
$duringClean=$damage->record($activeAgreement,'during',false,[],[],$actor);$duringClean2=$damage->record($activeAgreement,'during',false,[],[],$actor);pass('Multiple during-rental reports are allowed');
$rentals->transition($activeAgreement,'return',$actor,null,5100);
reject(fn()=>$damage->record($activeAgreement,'post',true,['location'=>'bumper','damage_type'=>'crack','severity'=>'severe'],[],$actor),'at least one photo','Post-return damage requires photo evidence');
$damage->record($activeAgreement,'post',false,[],[],$actor);pass('Clean post inspection may have no photos');

echo "M7 checks passed: {$passed}\n";

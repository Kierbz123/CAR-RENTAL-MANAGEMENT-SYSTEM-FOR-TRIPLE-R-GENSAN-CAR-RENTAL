<?php
// Service wiring for audit tests (mirrors public/index.php).
require '/home/user/CAR-RENTAL-MANAGEMENT-SYSTEM-FOR-TRIPLE-R-GENSAN-CAR-RENTAL/app/bootstrap.php';
use TripleR\Database; use TripleR\Repositories as R; use TripleR\Services as S;
$db=Database::connection();
$customers=new S\CustomerService($db,new R\CustomerRepository($db),new S\CustomerPiiCipher());
$drivers=new S\DriverService($db,new R\DriverRepository($db),new S\DriverPiiCipher());
$vehicles=new S\VehicleService($db,new R\VehicleRepository($db),new R\VehicleStatusLogRepository($db));
$rentals=S\RentalRuntimeFactory::service($db);
$overlaps=new S\BookingOverlapService($db); $rentalRepo=new R\RentalRepository($db,$overlaps);
$chauffeur=new S\ChauffeurService($db,$rentalRepo,new R\ChargeRepository($db),new R\VehicleRepository($db),$overlaps);
$ADMIN=(int)$db->query("SELECT id FROM users WHERE email='qa_system_admin@audit.test'")->fetchColumn();
if(!function_exists("check")){function check(string $label,bool $ok,string $detail=''){ echo ($ok?'PASS':'FAIL').": $label".($detail!==''?" [$detail]":'')."\n"; }}
function attempt(callable $f): string { try { $r=$f(); return 'OK:'.json_encode($r); } catch (\Throwable $e) { return 'ERR:'.get_class($e).': '.substr($e->getMessage(),0,140); } }
function newVehicle(int $actor,string $rate='2000.00',?string $chauffeurRate='1000.00'): int { global $vehicles; $p='QA'.strtoupper(bin2hex(random_bytes(3))); return $vehicles->register($vehicles->validate(['plate_number'=>$p,'make'=>'Toyota','model'=>'Vios','model_year'=>2022,'color'=>'White','body_type'=>'sedan','transmission'=>'automatic','fuel_type'=>'gasoline','seating_capacity'=>5,'daily_rate'=>$rate,'chauffeur_daily_rate'=>$chauffeurRate??'','current_mileage'=>1000]),$actor); }
function newCustomer(int $actor,array $extra=[]): int { global $customers; $db=\TripleR\Database::connection(); $db->exec('SET @triple_r_actor_user_id='.$actor); return $customers->create(array_merge(['customer_type'=>'walk_in','full_name'=>'QA Customer '.bin2hex(random_bytes(2)),'phone'=>'0917'.random_int(1000000,9999999)],$extra),$actor); }
function newDriver(int $actor,array $extra=[]): int { global $drivers; return $drivers->create(array_merge(['full_name'=>'QA Driver','license_number'=>'N'.random_int(10,99).'-'.random_int(10,99).'-'.random_int(100000,999999),'license_expiry'=>'2030-01-01'],$extra),$actor); }

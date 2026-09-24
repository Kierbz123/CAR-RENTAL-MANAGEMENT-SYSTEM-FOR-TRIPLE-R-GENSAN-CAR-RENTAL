<?php
declare(strict_types=1);

namespace TripleR\Services;

use PDO;
use RuntimeException;
use TripleR\Repositories\VehicleRepository;
use TripleR\Repositories\VehicleStatusLogRepository;

final class VehicleService
{
    public const STATUSES = ['available','rented','maintenance','reserved','cleaning','out_of_service','retired'];
    private const BODY_TYPES = ['sedan','SUV','van','pickup','hatchback'];

    public function __construct(private readonly PDO $db, private readonly VehicleRepository $vehicles, private readonly VehicleStatusLogRepository $statusLogs) {}

    public function validate(array $input): array
    {
        $text = static fn(string $key, int $max, bool $required = true): ?string => self::text($input[$key] ?? null, $max, $required, $key);
        $plate = strtoupper((string) $text('plate_number',20));
        $engine = $text('engine_number',80,false); $chassis = $text('chassis_number',80,false);
        $make = $text('make',60); $model = $text('model',80); $color = $text('color',40);
        $year = filter_var($input['model_year'] ?? null, FILTER_VALIDATE_INT);
        $body = (string) ($input['body_type'] ?? ''); $trans = (string) ($input['transmission'] ?? ''); $fuel = (string) ($input['fuel_type'] ?? '');
        $seats = filter_var($input['seating_capacity'] ?? null, FILTER_VALIDATE_INT);
        $initialMileage = filter_var($input['current_mileage'] ?? 0, FILTER_VALIDATE_INT);
        $daily = self::money($input['daily_rate'] ?? null, 'daily_rate');
        $chauffeur = trim((string) ($input['chauffeur_daily_rate'] ?? '')) === '' ? null : self::money($input['chauffeur_daily_rate'], 'chauffeur_daily_rate');
        if ($year === false || $year < 1 || $year > 32767 || !in_array($body,self::BODY_TYPES,true) || !in_array($trans,['manual','automatic'],true) || !in_array($fuel,['gasoline','diesel','hybrid'],true) || $seats === false || $seats < 1 || $seats > 255 || $initialMileage === false || $initialMileage < 0 || $initialMileage > 4294967295) {
            throw new RuntimeException('Check the vehicle year, type, transmission, fuel type, and seating capacity.');
        }
        $date = static function(string $key) use ($input): ?string { $value = trim((string)($input[$key] ?? '')); if ($value === '') return null; $dt = \DateTimeImmutable::createFromFormat('!Y-m-d',$value); if (!$dt || $dt->format('Y-m-d') !== $value) throw new RuntimeException('Enter a valid ' . str_replace('_',' ',$key) . '.'); return $value; };
        return ['plate_number'=>$plate,'engine_number'=>$engine,'chassis_number'=>$chassis,'make'=>$make,'model'=>$model,'model_year'=>$year,'color'=>$color,'body_type'=>$body,'transmission'=>$trans,'fuel_type'=>$fuel,'seating_capacity'=>$seats,'daily_rate'=>$daily,'chauffeur_daily_rate'=>$chauffeur,'current_status'=>'available','current_mileage'=>$initialMileage,'current_location_id'=>null,'registration_expiry'=>$date('registration_expiry'),'insurance_expiry'=>$date('insurance_expiry'),'insurance_provider'=>$text('insurance_provider',80,false),'notes'=>trim((string)($input['notes'] ?? '')) ?: null];
    }

    public function register(array $data, int $actor): int
    {
        $this->db->beginTransaction();
        try {
            $id = $this->vehicles->create($data);
            $this->statusLogs->append($id,null,'available',null,(int)$data['current_mileage'],$actor);
            $this->appendReading($id,(int)$data['current_mileage'],null,$actor,null,null);
            $this->db->commit(); return $id;
        } catch (\Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }

    public function update(int $id, array $data): void
    {
        if ($this->vehicles->find($id) === null) throw new RuntimeException('Vehicle not found.');
        $this->vehicles->update($id,$data);
    }

    public function transitionStatus(int $id, string $next, int $actor): void
    {
        if (!in_array($next,self::STATUSES,true)) throw new RuntimeException('Invalid vehicle status.');
        $this->db->beginTransaction();
        try {
            $v = $this->vehicles->find($id,true);
            if (!$v || $v['current_status'] === 'retired' || $v['current_status'] === $next) throw new RuntimeException('Vehicle status cannot be changed from its current state.');
            $stmt = $this->db->prepare('UPDATE vehicles SET current_status = :status' . ($next === 'retired' ? ', deleted_at = UTC_TIMESTAMP(6)' : '') . ' WHERE vehicle_id = :id AND deleted_at IS NULL');
            $stmt->execute(['status'=>$next,'id'=>$id]);
            $this->statusLogs->append($id,$v['current_status'],$next,$v['current_location_id'] === null ? null : (int)$v['current_location_id'],(int)$v['current_mileage'],$actor);
            $this->db->commit();
        } catch (\Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }

    public function recordMileage(int $id, int $mileage, ?int $locationId, int $actor, ?int $corrects = null, ?string $reason = null): void
    {
        if ($mileage < 0) throw new RuntimeException('Mileage cannot be negative.');
        $reason = $reason === null ? null : trim($reason);
        if ($corrects !== null && ($reason === null || $reason === '')) throw new RuntimeException('A correction reason is required.');
        if ($reason !== null && mb_strlen($reason)>500) throw new RuntimeException('Correction reasons are limited to 500 characters.');
        if ($mileage>4294967295) throw new RuntimeException('Mileage exceeds the supported whole-kilometer range.');
        $this->db->beginTransaction();
        try {
            $vehicle = $this->vehicles->find($id,true);
            if (!$vehicle) throw new RuntimeException('Vehicle not found.');
            if ($locationId !== null) {
                $q = $this->db->prepare('SELECT location_status, deleted_at FROM vehicle_locations WHERE location_id=:id FOR UPDATE'); $q->execute(['id'=>$locationId]); $loc=$q->fetch();
                if (!$loc || $loc['location_status'] !== 'active' || $loc['deleted_at'] !== null) throw new RuntimeException('Choose an active location.');
            }
            if ($corrects === null) {
                $effective = $this->effectiveReadings($id);
                if ($effective !== [] && $mileage < (int) end($effective)['mileage']) throw new RuntimeException('Mileage must not decrease; use an admin correction for an entry error.');
            } else {
                $this->assertCorrection($id,$corrects,$mileage);
            }
            $this->appendReading($id,$mileage,$locationId,$actor,$corrects,$reason);
            $latest = $this->effectiveReadings($id);
            $current = $latest === [] ? 0 : (int) end($latest)['mileage'];
            if ($corrects === null) {
                $this->db->prepare('UPDATE vehicles SET current_mileage=:mileage, current_location_id=COALESCE(:location,current_location_id) WHERE vehicle_id=:id')->execute(['mileage'=>$current,'location'=>$locationId,'id'=>$id]);
            } else {
                $this->db->prepare('UPDATE vehicles SET current_mileage=:mileage WHERE vehicle_id=:id')->execute(['mileage'=>$current,'id'=>$id]);
            }
            $this->db->commit();
        } catch (\Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }

    public function retireLocation(int $id): void
    {
        $stmt = $this->db->prepare("UPDATE vehicle_locations SET location_status='retired' WHERE location_id=:id AND deleted_at IS NULL"); $stmt->execute(['id'=>$id]);
        if ($stmt->rowCount() !== 1) throw new RuntimeException('Location not found.');
    }

    private function effectiveReadings(int $vehicleId): array
    {
        $s=$this->db->prepare('SELECT * FROM vehicle_mileage_logs WHERE vehicle_id=:id ORDER BY recorded_at, mileage_log_id'); $s->execute(['id'=>$vehicleId]); $rows=$s->fetchAll();
        $latest=[]; foreach ($rows as $row) if ($row['correction_of_log_id'] !== null) $latest[(int)$row['correction_of_log_id']]=$row;
        $effective=[]; foreach ($rows as $row) {
            if ($row['correction_of_log_id'] !== null) continue;
            $node=$row; while (isset($latest[(int)$node['mileage_log_id']])) $node=$latest[(int)$node['mileage_log_id']];
            $effective[]=['root'=>$row,'leaf'=>$node,'mileage'=>(int)$node['mileage']];
        }
        return $effective;
    }

    private function assertCorrection(int $vehicleId, int $target, int $replacement): void
    {
        $q=$this->db->prepare('SELECT * FROM vehicle_mileage_logs WHERE mileage_log_id=:target AND vehicle_id=:vehicle FOR UPDATE'); $q->execute(['target'=>$target,'vehicle'=>$vehicleId]); $targetRow=$q->fetch();
        if (!$targetRow) throw new RuntimeException('The reading to correct was not found for this vehicle.');
        $rootId=$target; $node=$targetRow;
        while ($node['correction_of_log_id']!==null) { $rootId=(int)$node['correction_of_log_id']; $q->execute(['target'=>$rootId,'vehicle'=>$vehicleId]); $node=$q->fetch(); if (!$node) throw new RuntimeException('The correction chain is invalid.'); }
        $all=$this->effectiveReadings($vehicleId); $index=null;
        foreach ($all as $i=>$item) if ((int)$item['root']['mileage_log_id']===$rootId) { $index=$i; break; }
        if ($index===null) throw new RuntimeException('The reading is not part of the effective mileage history.');
        $effectiveId=(int)$all[$index]['leaf']['mileage_log_id'];
        if ($target !== $effectiveId) throw new RuntimeException('Correct the current effective entry in this reading’s correction chain.');
        $before=$index>0 ? (int)$all[$index-1]['mileage'] : null; $after=isset($all[$index+1]) ? (int)$all[$index+1]['mileage'] : null;
        if (($before!==null && $replacement<$before) || ($after!==null && $replacement>$after)) throw new RuntimeException('That correction would make recorded mileage decrease between readings.');
    }

    private function appendReading(int $id,int $mileage,?int $location,int $actor,?int $corrects,?string $reason): void
    {
        $stmt=$this->db->prepare('INSERT INTO vehicle_mileage_logs (vehicle_id,mileage,location_id,actor_user_id,correction_of_log_id,correction_reason) VALUES (:vehicle,:mileage,:location,:actor,:corrects,:reason)');
        $stmt->execute(['vehicle'=>$id,'mileage'=>$mileage,'location'=>$location,'actor'=>$actor,'corrects'=>$corrects,'reason'=>$reason]);
    }

    private static function text(mixed $value,int $max,bool $required,string $field): ?string
    {
        $value=trim((string)($value??'')); if ($value==='' && !$required) return null;
        if ($value==='' || mb_strlen($value)>$max) throw new RuntimeException('Enter a valid ' . str_replace('_',' ',$field) . '.'); return $value;
    }

    private static function money(mixed $value,string $field): string
    {
        $value=trim((string)$value); if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/',$value)) throw new RuntimeException('Enter a valid ' . str_replace('_',' ',$field) . '.');
        [$whole,$fraction]=array_pad(explode('.',$value,2),2,''); return $whole . '.' . str_pad($fraction,2,'0');
    }
}

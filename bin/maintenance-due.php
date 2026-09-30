<?php
declare(strict_types=1);

use TripleR\Database;
use TripleR\Repositories\MaintenanceRepository;

require dirname(__DIR__).'/app/bootstrap.php';

try {
    $daysRaw=\TripleR\Config::get('MAINTENANCE_DUE_SOON_DAYS','30');$kmRaw=\TripleR\Config::get('MAINTENANCE_DUE_SOON_KM','500');
    if($daysRaw===null||!ctype_digit($daysRaw)||(int)$daysRaw<1||(int)$daysRaw>36500||$kmRaw===null||!ctype_digit($kmRaw)||(int)$kmRaw<1||(float)$kmRaw>4294967295)throw new RuntimeException('Invalid maintenance due-soon configuration.');
    $rows=(new MaintenanceRepository(Database::connection()))->dueSoon((int)$daysRaw,(int)$kmRaw);$csv=in_array('--csv',$argv,true);
    if($csv){$out=fopen('php://output','wb');fputcsv($out,['Plate','Vehicle','Schedule','State','Next due date','Next due mileage','Current mileage','Effective days warning','Effective km warning']);foreach($rows as $r)fputcsv($out,[$r['plate_number'],trim($r['make'].' '.$r['model']),$r['schedule_name'],$r['due_state'],$r['next_due_date'],$r['next_due_mileage'],$r['current_mileage'],$r['effective_due_soon_days'],$r['effective_due_soon_mileage']]);fclose($out);}
    else{if(!$rows){fwrite(STDOUT,"No maintenance schedules are due or within their warning windows.\n");}else{foreach($rows as $r){fwrite(STDOUT,sprintf("%s | %s %s | %s | %s | due %s / %s km | current %s km | warning %s days / %s km\n",$r['plate_number'],$r['make'],$r['model'],$r['schedule_name'],$r['due_state'],$r['next_due_date']??'—',$r['next_due_mileage']??'—',$r['current_mileage'],$r['effective_due_soon_days'],$r['effective_due_soon_mileage']));}}}
}catch(Throwable $e){fwrite(STDERR,'Maintenance due report failed: '.$e->getMessage()."\n");exit(1);}

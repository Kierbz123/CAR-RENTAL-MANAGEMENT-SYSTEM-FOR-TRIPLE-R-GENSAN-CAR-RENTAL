<?php
declare(strict_types=1);

use TripleR\Database;
use TripleR\Services\RentalRuntimeFactory;

require dirname(__DIR__).'/app/bootstrap.php';
try{$count=RentalRuntimeFactory::service(Database::connection())->enqueueDueReminders();fwrite(STDOUT,'Rental reminders queued: '.$count.PHP_EOL);}catch(Throwable $e){fwrite(STDERR,'Rental reminders failed: '.get_class($e).PHP_EOL);exit(1);}

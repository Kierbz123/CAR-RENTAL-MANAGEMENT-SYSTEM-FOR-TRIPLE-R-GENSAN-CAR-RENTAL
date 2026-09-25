<?php
declare(strict_types=1);

use TripleR\Database;
use TripleR\Repositories\RulesAcceptanceRepository;

require dirname(__DIR__).'/app/bootstrap.php';
try{$count=(new RulesAcceptanceRepository(Database::connection()))->consumeStopEvents();fwrite(STDOUT,'Imported STOP events: '.$count.PHP_EOL);}catch(Throwable $e){fwrite(STDERR,'STOP-event import failed: '.get_class($e).PHP_EOL);exit(1);}

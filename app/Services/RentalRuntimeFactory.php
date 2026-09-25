<?php
declare(strict_types=1);

namespace TripleR\Services;

use PDO;
use TripleR\Repositories\CustomerRepository;
use TripleR\Repositories\ChargeRepository;
use TripleR\Repositories\InboundSmsEventRepository;
use TripleR\Repositories\MagicLinkRepository;
use TripleR\Repositories\NotificationRepository;
use TripleR\Repositories\RentalRepository;
use TripleR\Repositories\RulesAcceptanceRepository;
use TripleR\Repositories\VehicleRepository;
use TripleR\Repositories\VehicleStatusLogRepository;

/** Shared dependency wiring for the rental web and CLI entry points. */
final class RentalRuntimeFactory
{
    public static function service(PDO $db): RentalService
    {
        $notifications=new NotificationService(new NotificationRepository($db),new InboundSmsEventRepository($db),new SmsMessageCipher(),new RulesAcceptanceRepository($db));
        $magicLinks=new MagicLinkService(new MagicLinkRepository($db),new RateLimiter($db),$notifications);
        $vehicles=new VehicleRepository($db);$vehicleService=new VehicleService($db,$vehicles,new VehicleStatusLogRepository($db));$customers=new CustomerRepository($db);
        return new RentalService($db,new RentalRepository($db,new BookingOverlapService($db)),new ChargeRepository($db),$vehicles,$customers,new CustomerPiiCipher(),$vehicleService,$notifications,$magicLinks);
    }
}

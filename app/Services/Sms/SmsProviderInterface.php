<?php
declare(strict_types=1);

namespace TripleR\Services\Sms;

interface SmsProviderInterface
{
    /** @return array{message_id:string,status:string} */
    public function send(string $recipient, string $message, string $priority): array;
}

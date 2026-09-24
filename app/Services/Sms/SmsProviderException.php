<?php
declare(strict_types=1);

namespace TripleR\Services\Sms;

final class SmsProviderException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable)
    {
        parent::__construct($message);
    }
}

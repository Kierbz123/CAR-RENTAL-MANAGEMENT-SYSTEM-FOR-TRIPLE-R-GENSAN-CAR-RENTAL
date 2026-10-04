<?php
declare(strict_types=1);

namespace TripleR\Services;

/** An online booking reached the point where the visitor must confirm the mobile number with a texted code. */
final class PhoneVerificationRequired extends \RuntimeException
{
    public function __construct(public readonly string $phone)
    {
        parent::__construct('Confirm your mobile number with the code we text you.');
    }
}

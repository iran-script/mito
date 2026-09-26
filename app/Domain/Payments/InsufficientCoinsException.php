<?php

namespace App\Domain\Payments;

class InsufficientCoinsException extends \DomainException
{
    public function __construct(public readonly int $required, public readonly int $balance)
    {
        parent::__construct("This action requires {$required} coins. Your balance is {$balance}.");
    }
}

<?php

namespace App\Domain\Payments;

interface PaidActionGate
{
    public function authorize(string $action, int $actorId, array $context = []): void;
}

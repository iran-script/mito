<?php

namespace App\Domain\Payments;

class FreePaidActionGate implements PaidActionGate
{
    public function authorize(string $action, int $actorId, array $context = []): void {}
}

<?php

namespace App\Domain\Memberships;

enum MembershipStatus: string
{
    case Active = 'active';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
}

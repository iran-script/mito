<?php

namespace App\Domain\Chat;

enum ChatRequestStatus: string
{
    case Pending = 'pending';
    case Seen = 'seen';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}

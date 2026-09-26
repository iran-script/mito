<?php

namespace App\Domain\Events;

enum EventParticipantStatus: string
{
    case Joined = 'joined';
    case Left = 'left';
    case Removed = 'removed';
}

<?php

namespace App\Domain\Games;

enum GameStatus: string
{
    case Waiting = 'waiting';
    case Accepted = 'accepted';
    case Active = 'active';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}

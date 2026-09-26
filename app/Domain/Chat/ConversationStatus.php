<?php

namespace App\Domain\Chat;

enum ConversationStatus: string
{
    case Active = 'active';
    case Closed = 'closed';
    case Blocked = 'blocked';
}

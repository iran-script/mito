<?php

namespace App\Domain\Direct;

enum DirectMessageStatus: string
{
    case Sent = 'sent';
    case Seen = 'seen';
}

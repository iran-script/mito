<?php

namespace App\Domain\Chat;

enum MessageType: string
{
    case Text = 'text';
    case Photo = 'photo';
    case Voice = 'voice';
}

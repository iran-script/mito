<?php

namespace App\Domain\Users;

enum UserStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Banned = 'banned';
    case Deleted = 'deleted';
}

<?php

namespace App\Domain\Profiles;

enum ProfileStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
}

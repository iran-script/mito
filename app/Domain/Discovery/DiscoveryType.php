<?php

namespace App\Domain\Discovery;

enum DiscoveryType: string
{
    case City = 'city';
    case Age = 'age';
    case NewUsers = 'new';
    case Interest = 'interest';
    case Distance = 'distance';
    case Anonymous = 'anonymous';
}

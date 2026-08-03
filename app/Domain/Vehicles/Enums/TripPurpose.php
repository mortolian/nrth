<?php

namespace App\Domain\Vehicles\Enums;

enum TripPurpose: string
{
    case Business = 'business';
    case Private = 'private';
}

<?php

namespace App\Enums;

enum SourceEventStatus: string
{
    case Cancelled = 'cancelled';
    case Postponed = 'postponed';
    case Suspended = 'suspended';
    case Rescheduled = 'rescheduled';
    case MovedOnline = 'moved-online';
    case SoldOut = 'sold-out';
    case FullyBooked = 'fully-booked';
}

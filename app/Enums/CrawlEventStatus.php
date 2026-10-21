<?php

namespace App\Enums;

enum CrawlEventStatus: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Unchanged = 'unchanged';
    case Skipped = 'skipped';
    case Failed = 'failed';
}

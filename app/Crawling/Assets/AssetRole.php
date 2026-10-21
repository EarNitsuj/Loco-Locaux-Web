<?php

namespace App\Crawling\Assets;

enum AssetRole: string
{
    case EventMedia = 'event_media';

    case Accessibility = 'accessibility';

    case PracticalInfo = 'practical_info';

    case OrganizerBranding = 'organizer_branding';

    case UiChrome = 'ui_chrome';

    case Unknown = 'unknown';
}

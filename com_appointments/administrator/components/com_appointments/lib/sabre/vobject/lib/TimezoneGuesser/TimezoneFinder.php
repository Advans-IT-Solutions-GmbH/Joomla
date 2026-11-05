<?php

namespace Advans\Component\Appointments\Vendor\Sabre\VObject\TimezoneGuesser;
\defined('_JEXEC') or die;

use DateTimeZone;
interface TimezoneFinder
{
    public function find(string $tzid, bool $failIfUncertain = \false): ?DateTimeZone;
}

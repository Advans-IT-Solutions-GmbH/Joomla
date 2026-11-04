<?php

namespace Advans\Component\Appointments\Vendor\Sabre\VObject\TimezoneGuesser;

use DateTimeZone;
use Advans\Component\Appointments\Vendor\Sabre\VObject\Component\VTimeZone;
interface TimezoneGuesser
{
    public function guess(VTimeZone $vtimezone, bool $failIfUncertain = \false): ?DateTimeZone;
}

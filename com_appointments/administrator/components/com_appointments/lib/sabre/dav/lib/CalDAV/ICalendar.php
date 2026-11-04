<?php

declare (strict_types=1);
namespace Advans\Component\Appointments\Vendor\Sabre\CalDAV;
\defined('_JEXEC') or die;

use Advans\Component\Appointments\Vendor\Sabre\DAVACL;
/**
 * Calendar interface.
 *
 * Implement this interface to allow a node to be recognized as an calendar.
 *
 * @copyright Copyright (C) fruux GmbH (https://fruux.com/)
 * @author Evert Pot (http://evertpot.com/)
 * @license http://sabre.io/license/ Modified BSD License
 */
interface ICalendar extends ICalendarObjectContainer, DAVACL\IACL
{
}

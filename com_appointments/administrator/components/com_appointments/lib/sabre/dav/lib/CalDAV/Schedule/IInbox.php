<?php

declare (strict_types=1);
namespace Advans\Component\Appointments\Vendor\Sabre\CalDAV\Schedule;
\defined('_JEXEC') or die;

/**
 * Implement this interface to have a node be recognized as a CalDAV scheduling
 * inbox.
 *
 * @copyright Copyright (C) fruux GmbH (https://fruux.com/)
 * @author Evert Pot (http://evertpot.com/)
 * @license http://sabre.io/license/ Modified BSD License
 */
interface IInbox extends \Advans\Component\Appointments\Vendor\Sabre\CalDAV\ICalendarObjectContainer, \Advans\Component\Appointments\Vendor\Sabre\DAVACL\IACL
{
}

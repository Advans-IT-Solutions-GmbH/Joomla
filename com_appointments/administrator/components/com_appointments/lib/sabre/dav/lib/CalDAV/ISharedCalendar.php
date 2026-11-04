<?php

declare (strict_types=1);
namespace Advans\Component\Appointments\Vendor\Sabre\CalDAV;
\defined('_JEXEC') or die;

use Advans\Component\Appointments\Vendor\Sabre\DAV\Sharing\ISharedNode;
/**
 * This interface represents a Calendar that is shared by a different user.
 *
 * @copyright Copyright (C) fruux GmbH (https://fruux.com/)
 * @author Evert Pot (http://evertpot.com/)
 * @license http://sabre.io/license/ Modified BSD License
 */
interface ISharedCalendar extends ISharedNode
{
    /**
     * Marks this calendar as published.
     *
     * Publishing a calendar should automatically create a read-only, public,
     * subscribable calendar.
     *
     * @param bool $value
     */
    public function setPublishStatus($value);
}

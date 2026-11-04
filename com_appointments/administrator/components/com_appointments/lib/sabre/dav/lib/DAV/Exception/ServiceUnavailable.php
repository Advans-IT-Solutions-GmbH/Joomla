<?php

declare (strict_types=1);
namespace Advans\Component\Appointments\Vendor\Sabre\DAV\Exception;

use Advans\Component\Appointments\Vendor\Sabre\DAV;
/**
 * ServiceUnavailable.
 *
 * This exception is thrown in case the service
 * is currently not available (e.g. down for maintenance).
 *
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @copyright Copyright (C) fruux GmbH (https://fruux.com/)
 * @license http://sabre.io/license/ Modified BSD License
 */
class ServiceUnavailable extends DAV\Exception
{
    /**
     * Returns the HTTP statuscode for this exception.
     *
     * @return int
     */
    public function getHTTPCode()
    {
        return 503;
    }
}

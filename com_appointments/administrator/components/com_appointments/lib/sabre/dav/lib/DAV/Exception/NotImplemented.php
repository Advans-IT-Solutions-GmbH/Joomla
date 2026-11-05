<?php

declare (strict_types=1);
namespace Advans\Component\Appointments\Vendor\Sabre\DAV\Exception;
\defined('_JEXEC') or die;

use Advans\Component\Appointments\Vendor\Sabre\DAV;
/**
 * NotImplemented.
 *
 * This exception is thrown when the client tried to call an unsupported HTTP method or other feature
 *
 * @copyright Copyright (C) fruux GmbH (https://fruux.com/)
 * @author Evert Pot (http://evertpot.com/)
 * @license http://sabre.io/license/ Modified BSD License
 */
class NotImplemented extends DAV\Exception
{
    /**
     * Returns the HTTP statuscode for this exception.
     *
     * @return int
     */
    public function getHTTPCode()
    {
        return 501;
    }
}

<?php

declare (strict_types=1);
namespace Advans\Component\Appointments\Vendor\Sabre\CalDAV\Principal;
\defined('_JEXEC') or die;

use Advans\Component\Appointments\Vendor\Sabre\DAVACL;
/**
 * ProxyWrite principal interface.
 *
 * Any principal node implementing this interface will be picked up as a 'proxy
 * principal group'.
 *
 * @copyright Copyright (C) fruux GmbH (https://fruux.com/)
 * @author Evert Pot (http://evertpot.com/)
 * @license http://sabre.io/license/ Modified BSD License
 */
interface IProxyWrite extends DAVACL\IPrincipal
{
}

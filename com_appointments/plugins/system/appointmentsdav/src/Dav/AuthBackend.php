<?php
/**
 * @package     Appointments CalDAV Plugin
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Plugin\System\AppointmentsDav\Dav;

use Advans\Component\Appointments\Vendor\Sabre\DAV\Auth\Backend\BackendInterface;
use Advans\Component\Appointments\Vendor\Sabre\HTTP\RequestInterface;
use Advans\Component\Appointments\Vendor\Sabre\HTTP\ResponseInterface;

defined('_JEXEC') or die;

/**
 * The request is authenticated before sabre/dav sees it ({@see DavGateway}): app
 * password, lock-out and second factor. This backend only names the principal
 * of that device session for sabre's ACL plugin.
 *
 * @since  1.4.0
 */
final class AuthBackend implements BackendInterface
{
    public function __construct(private readonly DavContext $context)
    {
    }

    public function check(RequestInterface $request, ResponseInterface $response)
    {
        return [true, $this->context->principal()];
    }

    public function challenge(RequestInterface $request, ResponseInterface $response)
    {
    }
}

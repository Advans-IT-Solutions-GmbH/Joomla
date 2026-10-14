<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Helper\Conference;

defined('_JEXEC') or die;

final class OutboundHttpRequest
{
    public readonly string $url;
    public readonly string $method;
    public readonly string $body;
    public readonly array $headers;

    public function __construct(string $url, string $method = 'POST', string $body = '', array $headers = [])
    {
        $this->url = ConferenceUrl::validate($url);

        if (!in_array($method, ['GET', 'POST', 'DELETE'], true)) {
            throw new ConferenceException('configuration');
        }

        foreach ($headers as $header) {
            if (!is_string($header) || preg_match('/[\x00-\x1f\x7f]/', $header)
                || !preg_match('/\A[A-Za-z0-9-]+:[^\r\n]*\z/', $header)
            ) {
                throw new ConferenceException('configuration');
            }
        }

        $this->method = $method;
        $this->body = $body;
        $this->headers = array_values($headers);
    }
}

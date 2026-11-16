<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service\Close;

use Advans\Component\Appointments\Administrator\Helper\Conference\BoundedHttpClient;
use Advans\Component\Appointments\Administrator\Helper\Conference\OutboundHttpRequest;

defined('_JEXEC') or die;

final class Transport
{
    /**
     * The key stored for the booking's event type wins; without one, the profile's server environment
     * variable applies. Errors never carry the key: callers only see a static message, and the response
     * body of a failed request is reduced to its status code by the client.
     */
    public static function forProfile(Profile $profile, ?string $key = null): \Closure
    {
        $key ??= $profile->credential === '' ? false : getenv($profile->credential);
        if (!is_string($key) || $key === '' || strlen($key) > 1024 || preg_match('/[\s\x00-\x1f\x7f:]/', $key)
            || !extension_loaded('curl')) {
            throw new \RuntimeException('Close credential or HTTPS transport is unavailable.');
        }
        return static function (string $method, string $path, ?array $payload) use ($profile, $key): array {
            if (!str_starts_with($path, '/api/v1/') || str_contains($path, '..') || !in_array($method, ['GET', 'POST', 'PUT'], true)) {
                throw new \InvalidArgumentException('Invalid Close request.');
            }
            $request = new OutboundHttpRequest($profile->origin . $path, $method,
                $payload === null ? '' : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ['Authorization: Basic ' . base64_encode($key . ':'), 'Content-Type: application/json']);
            $handle = curl_init($request->url);
            if ($handle === false) {
                throw new \RuntimeException('Close transport unavailable.');
            }
            $body = '';
            $retry = 300;
            try {
                curl_setopt_array($handle, BoundedHttpClient::options($request) + [
                    CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                        if (strlen($body) + strlen($chunk) > BoundedHttpClient::MAX_RESPONSE_BYTES) {
                            return 0;
                        }
                        $body .= $chunk;
                        return strlen($chunk);
                    },
                    CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$retry): int {
                        if (preg_match('/^Retry-After:\s*(\d+)\s*$/iD', trim($line), $match)) {
                            $retry = (int) $match[1];
                        }
                        return strlen($line);
                    },
                ]);
                if (curl_exec($handle) === false) {
                    // curl_error() is not passed on: it could echo request details.
                    throw new \RuntimeException('Close transport did not complete.');
                }
                return ['code' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'body' => $body, 'retry_after' => $retry];
            } finally {
                curl_close($handle);
            }
        };
    }
}

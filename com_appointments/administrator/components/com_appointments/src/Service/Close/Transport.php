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
    public static function forSettings(Settings $settings): \Closure
    {
        $key = getenv($settings->credential);
        if (!is_string($key) || $key === '' || strlen($key) > 1024 || preg_match('/[\s\x00-\x1f\x7f:]/', $key)
            || !extension_loaded('curl')) {
            throw new \RuntimeException('Close credential or HTTPS transport is unavailable.');
        }
        return static function (string $method, string $path, ?array $payload) use ($settings, $key): array {
            if (!str_starts_with($path, '/api/v1/') || str_contains($path, '..')) {
                throw new \InvalidArgumentException('Invalid Close request path.');
            }
            $request = new OutboundHttpRequest($settings->origin . $path, $method,
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
                    throw new \RuntimeException('Close transport did not complete.');
                }
                return ['code' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'body' => $body, 'retry_after' => $retry];
            } finally {
                curl_close($handle);
            }
        };
    }
}

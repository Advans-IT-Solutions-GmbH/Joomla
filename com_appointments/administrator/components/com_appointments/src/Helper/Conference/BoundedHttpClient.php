<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Helper\Conference;

defined('_JEXEC') or die;

/**
 * An optional adapter cannot supply cURL options or replace this transport.
 * Five seconds per attempt, one retry on failure, no redirects, verified TLS.
 */
final class BoundedHttpClient
{
    public const TIMEOUT_SECONDS = 5;
    public const RETRIES = 1;
    public const MAX_RESPONSE_BYTES = 1048576;

    public function request(OutboundHttpRequest $request): string
    {
        if (!extension_loaded('curl')) {
            throw new ConferenceException('outbound_failed');
        }

        for ($attempt = 0; $attempt <= self::RETRIES; $attempt++) {
            $handle = curl_init($request->url);

            if ($handle === false) {
                throw new ConferenceException('outbound_failed');
            }

            $response = '';

            try {
                curl_setopt_array($handle, self::options($request) + [
                    CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response): int {
                        if (strlen($response) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                            return 0;
                        }

                        $response .= $chunk;

                        return strlen($chunk);
                    },
                ]);
                $ok = curl_exec($handle);
                $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

                if ($ok !== false && $status >= 200 && $status < 300) {
                    return $response;
                }
            } catch (\Throwable $error) {
                // Never expose the URL, headers, response body or cURL diagnostic.
            } finally {
                curl_close($handle);
            }
        }

        throw new ConferenceException('outbound_failed');
    }

    /** The fixed transport policy is public for inspection, not configurable. */
    public static function options(OutboundHttpRequest $request): array
    {
        return [
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_HTTPHEADER => $request->headers,
            CURLOPT_POSTFIELDS => $request->body,
            CURLOPT_TIMEOUT_MS => self::TIMEOUT_SECONDS * 1000,
            CURLOPT_CONNECTTIMEOUT_MS => self::TIMEOUT_SECONDS * 1000,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROXY => '',
            CURLOPT_NETRC => CURL_NETRC_IGNORED,
        ];
    }
}

<?php
/**
 * Shared helpers for the AJAX Forms HTTP test scripts.
 */

/**
 * The plugin may answer directly with {"success":false,...} or through the
 * com_ajax envelope that carries the plugin JSON string in data[0].
 *
 * @return array<string, mixed>|null
 */
function ajaxforms_decode_response(string $body): ?array
{
    $outer = json_decode($body, true);

    if (!is_array($outer)) {
        return null;
    }

    if (array_key_exists('success', $outer)) {
        return $outer;
    }

    if (isset($outer['data'][0]) && is_string($outer['data'][0])) {
        $inner = json_decode($outer['data'][0], true);

        if (is_array($inner) && array_key_exists('success', $inner)) {
            return $inner;
        }
    }

    if (isset($outer['data'][0]) && is_array($outer['data'][0]) && array_key_exists('success', $outer['data'][0])) {
        return $outer['data'][0];
    }

    return null;
}

/**
 * True when ajaxforms_decode_response() finds a plugin payload whose top-level
 * success flag is false, whether it came directly from the plugin or from the
 * com_ajax envelope.
 */
function ajaxforms_is_json_rejection(string $body): bool
{
    $data = ajaxforms_decode_response($body);

    return is_array($data) && (($data['success'] ?? null) === false);
}

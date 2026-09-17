<?php
/**
 * Shared helpers for the AJAX Forms HTTP test scripts.
 */

/**
 * The plugin may answer directly with {"success":false,...} or through the
 * com_ajax envelope that carries the plugin JSON string in data[0].
 *
 * The envelope itself always has "success":true (com_ajax dispatched the
 * request), so the plugin payload in data[0] is checked first; the outer
 * object only counts when it carries no plugin payload.
 *
 * @return array<string, mixed>|null
 */
function ajaxforms_decode_response(string $body): ?array
{
    $outer = json_decode($body, true);

    if (!is_array($outer)) {
        return null;
    }

    $inner = $outer['data'][0] ?? null;

    if (is_string($inner)) {
        $inner = json_decode($inner, true);
    }

    if (is_array($inner) && array_key_exists('success', $inner)) {
        return $inner;
    }

    return array_key_exists('success', $outer) ? $outer : null;
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

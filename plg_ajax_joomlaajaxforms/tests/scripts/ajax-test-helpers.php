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

/**
 * The selectors the shipped script uses to find a com_users form.
 *
 * Read straight from media/js/joomlaajaxforms.js (userFormSelectors), so the
 * test can never drift from the code it is meant to protect. The older shape,
 * one inline querySelector() list per init function, is read as well, so the
 * checks judge the detection itself and not the name of a function.
 *
 * @return string[]  Concrete selectors for this view, in the order the script tries them.
 */
function ajaxforms_form_selectors(string $view, string $task, string $scriptPath = ''): array
{
    $scriptPath = $scriptPath ?: '/var/www/html/plugins/ajax/joomlaajaxforms/media/js/joomlaajaxforms.js';
    $source     = (string) @file_get_contents($scriptPath);

    if (!preg_match('/userFormSelectors:\s*function\s*\([^)]*\)\s*\{\s*return\s*\[(.*?)\];/s', $source, $m)) {
        $init = 'init' . ucfirst($view) . 'Form';

        if (preg_match('/' . $init . ':\s*function[^{]*\{.*?document\.querySelector\(\s*\'([^\']*)\'/s', $source, $inline)) {
            return array_values(array_filter(array_map('trim', explode(',', $inline[1]))));
        }

        return [];
    }

    $selectors = [];

    foreach (preg_split('/,\s*\R/', trim($m[1])) as $entry) {
        $entry = trim($entry, " \t\r\n,");

        if ($entry === '') {
            continue;
        }

        // Rebuild the runtime value of an expression like
        // 'form.com-users-' + view + '__form'  ->  form.com-users-reset__form
        $value = '';

        foreach (preg_split('/\s*\+\s*/', $entry) as $part) {
            $part = trim($part);

            if ($part === 'view') {
                $value .= $view;
            } elseif ($part === 'task') {
                $value .= $task;
            } elseif (preg_match('/^\'(.*)\'$/s', $part, $lit)) {
                $value .= $lit[1];
            } else {
                return [];   // an unexpected shape must not pass silently
            }
        }

        $selectors[] = $value;
    }

    return $selectors;
}

/**
 * True when the selector addresses one element on its own: no descendant
 * combinator, so it does not depend on any wrapper around the form.
 */
function ajaxforms_selector_is_standalone(string $selector): bool
{
    return strpos(trim($selector), ' ') === false;
}

/**
 * True when the selector looks at the form action, which SEF routing rewrites.
 */
function ajaxforms_selector_uses_action(string $selector): bool
{
    return strpos($selector, 'action') !== false;
}

/**
 * Does a standalone selector match a <form> in this HTML?
 *
 * Supports exactly the shapes the script uses: an optional tag name followed by
 * any number of .class, #id and [attr], [attr="v"] or [attr*="v"] parts. An
 * unsupported shape returns null, so the caller can fail instead of passing.
 */
function ajaxforms_selector_matches(string $html, string $selector): ?bool
{
    if (!preg_match('/^([a-z][a-z0-9]*)?((?:[.#][A-Za-z0-9_-]+|\[[^\]]+\])*)$/', trim($selector), $m)) {
        return null;
    }

    $tag        = $m[1] !== '' ? $m[1] : '*';
    $conditions = [];

    if (preg_match_all('/[.#][A-Za-z0-9_-]+|\[[^\]]+\]/', $m[2], $parts)) {
        foreach ($parts[0] as $part) {
            if ($part[0] === '.') {
                $conditions[] = "contains(concat(' ', normalize-space(@class), ' '), ' " . substr($part, 1) . " ')";
            } elseif ($part[0] === '#') {
                $conditions[] = "@id='" . substr($part, 1) . "'";
            } elseif (preg_match('/^\[([A-Za-z0-9_:-]+)\*=["\']([^"\']*)["\']\]$/', $part, $a)) {
                $conditions[] = "contains(@{$a[1]}, '{$a[2]}')";
            } elseif (preg_match('/^\[([A-Za-z0-9_:-]+)=["\']([^"\']*)["\']\]$/', $part, $a)) {
                $conditions[] = "@{$a[1]}='{$a[2]}'";
            } elseif (preg_match('/^\[([A-Za-z0-9_:-]+)\]$/', $part, $a)) {
                $conditions[] = "@{$a[1]}";
            } else {
                return null;
            }
        }
    }

    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $xpath = '//' . $tag . ($conditions ? '[' . implode(' and ', $conditions) . ']' : '');
    $nodes = (new DOMXPath($document))->query($xpath);

    if ($nodes === false) {
        return null;
    }

    foreach ($nodes as $node) {
        if (strtolower($node->nodeName) === 'form') {
            return true;
        }
    }

    return false;
}

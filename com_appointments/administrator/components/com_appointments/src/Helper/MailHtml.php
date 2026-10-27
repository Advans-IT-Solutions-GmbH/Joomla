<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Helper;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Layout\FileLayout;
use Joomla\CMS\Mail\MailHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\Filesystem\Path;

defined('_JEXEC') or die;

/**
 * HTML part of an appointment mail in the site's own mail layout.
 *
 * Appointment mails are composed as plain text. A site that switched Joomla's mail templates
 * (com_mails) to HTML gets every system mail in its HTML layout, with its logo and fonts. The
 * appointment mails used to bypass that and arrived as bare text. This class follows the rules of
 * Joomla\CMS\Mail\MailTemplate::send(): mail_style «plaintext» keeps the text mail, «html» and
 * «both» send HTML with the text as alternative part, and with the HTML layout switched on
 * (disable_htmllayout, the option name is inverted in core) the body is wrapped in
 * joomla.mail.<layout>, including a template override given as «template:layout».
 */
final class MailHtml
{
    /**
     * @param   string  $plain     Plain-text body as composed.
     * @param   string  $language  Language tag of the recipient.
     * @param   string  $siteName  Site name for the layout.
     * @param   array   $config    com_mails options, null reads them from the component.
     * @param   object  $mailer    Mailer of the message. With a logo file set in com_mails the logo is
     *                             attached inline as «site-logo» and handed to the layout, like core.
     * @param   string[]  $known   URLs the mail carries (conference and management links), linked exactly.
     * @param   string    $base    Public site URL (com_appointments public_site_url or live_site). Relative
     *                             URLs of the layout resolve against it, also in a CLI reminder run.
     *                             Null keeps Joomla's own conversion against the request URI.
     *
     * @return  string|null  HTML body, or null when the site sends plain-text mails.
     */
    public static function render(
        string $plain,
        string $language,
        string $siteName,
        ?array $config = null,
        ?object $mailer = null,
        array $known = [],
        ?string $base = null
    ): ?string {
        $config ??= ComponentHelper::getParams('com_mails')->toArray();

        if (!\in_array((string) ($config['mail_style'] ?? 'plaintext'), ['html', 'both'], true)) {
            return null;
        }

        $html = self::textToHtml($plain, $known);

        if ((string) ($config['disable_htmllayout'] ?? '1') === '1') {
            $layout = (string) ($config['mail_htmllayout'] ?? 'mailtemplate');
            $parts  = explode(':', $layout);

            if (\count($parts) === 2) {
                $layout = $parts[1];
            }

            // Joomla's layout selector offers hyphenated file names too. No dot or slash: no path traversal.
            if (preg_match('/\A[a-z0-9_-]+\z/i', $layout) !== 1) {
                $layout = 'mailtemplate';
            }

            $file = new FileLayout('joomla.mail.' . $layout, null, ['client' => 'site']);

            if (\count($parts) === 2 && preg_match('/\A[a-z0-9_-]+\z/i', $parts[0]) === 1) {
                $file->addIncludePaths([
                    JPATH_SITE . '/templates/' . $parts[0] . '/html/layouts',
                    JPATH_SITE . '/templates/' . $parts[0] . '/html/layouts/com_mails',
                ]);
            }

            $extra = ['siteName' => $siteName, 'lang' => substr($language, 0, 2)];
            $logo  = (string) ($config['mail_logofile'] ?? '');

            if ($logo !== '' && $mailer !== null && method_exists($mailer, 'addAttachment')) {
                try {
                    // Decode once, then check and attach the same filesystem path (core checks the decoded
                    // name but attaches the encoded one, so «site%20logo.png» would be lost).
                    $path = Path::check(JPATH_ROOT . '/' . rawurldecode(HTMLHelper::_('cleanImageURL', $logo)->url));

                    if (is_file($path)) {
                        $mailer->addAttachment($path, 'site-logo', 'base64', mime_content_type($path), 'inline');
                        $extra['logo'] = 'site-logo';
                    }
                } catch (\Throwable $e) {
                    // No logo is better than no mail.
                }
            }

            // The layout is rendered around a marker, rebased, and only then gets the composed body:
            // a layout builds its own links from Uri::root(), which in a CLI reminder run is the
            // CLI-derived address, while the body carries the exact links of the mail (conference,
            // management) that must reach the recipient unchanged. Joomla's URI state stays untouched.
            $marker   = '<!--appointments-mail-body-' . bin2hex(random_bytes(6)) . '-->';
            $rendered = $file->render(['mail' => $marker, 'extra' => $extra]);

            if (\is_string($rendered) && trim($rendered) !== '' && substr_count($rendered, $marker) === 1) {
                return self::assemble($rendered, $marker, $html, $base, self::requestRoot());
            }
        }

        return $html;
    }

    /**
     * Put the composed body into the rendered layout. Only the layout's URLs are rebased from the
     * request root to the public site URL and made absolute, the body stays exactly as composed.
     *
     * @param   string       $layout  Layout output with the marker in place of the body.
     * @param   string       $marker  Marker the layout was rendered with.
     * @param   string       $body    Composed HTML body.
     * @param   string|null  $base    Public site URL, null for Joomla's own conversion.
     * @param   string       $root    Request root the layout was built with.
     */
    public static function assemble(string $layout, string $marker, string $body, ?string $base, string $root): string
    {
        if ($base !== null && preg_match('#\Ahttps?://[^/]+#i', $base) === 1) {
            $layout = self::absolutize(self::rebase($layout, $root, $base), $base);
        } else {
            $layout = MailHelper::convertRelativeToAbsoluteUrls($layout);
        }

        return str_replace($marker, $body, $layout);
    }

    /**
     * Escaped plain text as HTML paragraphs, with links clickable.
     *
     * @param   string    $plain  Plain text.
     * @param   string[]  $known  URLs the mail is known to carry (conference, reschedule, cancel,
     *                            erase). They are linked exactly as given, an apostrophe or a final
     *                            «!» included. Other URLs go through the prose heuristic.
     */
    public static function textToHtml(string $plain, array $known = []): string
    {
        $known = array_values(array_unique(array_filter(
            array_map('strval', $known),
            static fn (string $url): bool => preg_match('#\Ahttps?://\S+\z#i', $url) === 1
        )));
        usort($known, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));
        $pattern = $known === [] ? null
            // A known URL only counts as a whole: followed by the end, white space, a bracket or quote,
            // or prose punctuation that ends there. A longer URL sharing the prefix is not cut.
            // ... and not inside another URL (?redirect=...): no URL character right before it. A label
            // such as «Join:» is fine.
            : '#(?<![\w=/?&%\#.~+-])(' . implode('|', array_map(static fn (string $url): string => preg_quote($url, '#'), $known)) . ')'
                . '(?=\z|[\s<>"\']|[.,;:!?)\]}]+(?:\z|[\s<>"\']))#u';

        $blocks = preg_split('/\R{2,}/u', trim(str_replace(["\r\n", "\r"], "\n", $plain))) ?: [];
        $out    = '';

        foreach ($blocks as $block) {
            $parts  = $pattern === null ? [$block] : (preg_split($pattern, $block, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$block]);
            $linked = '';

            foreach ($parts as $i => $part) {
                if ($pattern !== null && $i % 2 === 1) {
                    $escaped = htmlspecialchars($part, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $linked .= '<a href="' . $escaped . '">' . $escaped . '</a>';
                    continue;
                }

                $linked .= self::linkify($part);
            }

            $out .= '<p>' . nl2br($linked, false) . "</p>\n";
        }

        return $out;
    }

    /**
     * Escape text and link the URLs found in it.
     */
    private static function linkify(string $text): string
    {
        // Find the URLs in the original text, then escape each piece: escaping first would let
        // the match run into an entity («&gt;») and change a signed link.
        $pieces = preg_split('#(\bhttps?://[^\s<>"\']+)#u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        $linked = '';

        foreach ($pieces as $i => $piece) {
            if ($i % 2 === 0) {
                $linked .= htmlspecialchars($piece, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                continue;
            }

            [$url, $tail] = self::splitTrailing($piece);
            $escaped        = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $linked        .= '<a href="' . $escaped . '">' . $escaped . '</a>'
                . htmlspecialchars($tail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return $linked;
    }

    /**
     * Replace absolute URLs on the request root by the same paths on the public site URL. An equal
     * root, an empty root or an invalid public URL leaves the HTML unchanged.
     */
    public static function rebase(string $html, string $from, string $to): string
    {
        $from = rtrim($from, '/');
        $to   = rtrim($to, '/');

        if ($from === '' || $from === $to || preg_match('#\Ahttps?://[^/]+#i', $from) !== 1 || preg_match('#\Ahttps?://[^/]+#i', $to) !== 1) {
            return $html;
        }

        $html = preg_replace_callback(
            '/\b(href|src)(\s*=\s*)(["\'])' . preg_quote($from, '/') . '(\/[^"\']*|)\3/i',
            static fn (array $m): string => $m[1] . $m[2] . $m[3] . $to . ($m[4] === '' ? '/' : $m[4]) . $m[3],
            $html
        ) ?? $html;

        // The visible address of the layout footer, as link text
        return preg_replace_callback(
            '/>\s*' . preg_quote($from, '/') . '(\/[^<\s]*|)\s*</i',
            static fn (array $m): string => '>' . $to . $m[1] . '<',
            $html
        ) ?? $html;
    }

    /** Root of the current request as the layouts see it, '' when no URI is available. */
    private static function requestRoot(): string
    {
        try {
            return Uri::root();
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Relative href and src values against the public site URL: «/x» against its origin, «x» against
     * the URL itself (which may hold a subdirectory). Absolute, protocol-relative, anchor, mailto:,
     * tel:, data: and cid: values stay.
     */
    public static function absolutize(string $html, string $base): string
    {
        $base = rtrim($base, '/') . '/';
        preg_match('#\Ahttps?://[^/]+#i', $base, $origin);

        return preg_replace_callback(
            '/\b(href|src)(\s*=\s*)(["\'])(.*?)\3/is',
            static function (array $m) use ($base, $origin): string {
                $url = trim($m[4]);

                if ($url === '' || preg_match('#^(?:[a-z][a-z0-9+.\-]*:|//|\#)#i', $url) === 1) {
                    return $m[0];
                }

                return $m[1] . $m[2] . $m[3] . ($url[0] === '/' ? $origin[0] . $url : $base . $url) . $m[3];
            },
            $html
        ) ?? $html;
    }

    /**
     * Split prose punctuation and unmatched closing brackets off the end of a URL found in text:
     * «(https://x.test/a?t=1).» links «https://x.test/a?t=1» and keeps «).» as text.
     *
     * @return  array{0: string, 1: string}  URL and trailing text.
     */
    public static function splitTrailing(string $url): array
    {
        $tail  = '';
        $pairs = [')' => '(', ']' => '[', '}' => '{'];

        while ($url !== '') {
            $last = substr($url, -1);

            if (strpbrk($last, '.,;:!?') !== false
                || (isset($pairs[$last]) && substr_count($url, $pairs[$last]) < substr_count($url, $last))
            ) {
                $tail = $last . $tail;
                $url  = substr($url, 0, -1);
                continue;
            }

            break;
        }

        return [$url, $tail];
    }
}

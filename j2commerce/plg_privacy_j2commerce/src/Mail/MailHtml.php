<?php
/**
 * @package     Privacy - J2Commerce
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Plugin\Privacy\J2Commerce\Mail;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Layout\FileLayout;
use Joomla\CMS\Mail\MailHelper;
use Joomla\Filesystem\Path;

defined('_JEXEC') or die;

/**
 * Puts a plain-text mail of this plugin into the site's own mail layout.
 *
 * The notices to customers and administrators are composed as plain text. A site that switched
 * Joomla's mail templates (com_mails) to HTML gets every system mail in its HTML layout; these
 * mails used to bypass that. This class follows MailTemplate::send(): mail_style «plaintext» keeps
 * the text mail, «html» and «both» send HTML with the original text as alternative part, and with
 * the HTML layout switched on (disable_htmllayout, the option name is inverted in core) the body
 * is wrapped in joomla.mail.<layout>, including a template override given as «template:layout».
 */
final class MailHtml
{
    /**
     * Set body and type of the mail: HTML in the site's layout with the text as AltBody, or the text.
     *
     * @param   object      $mailer    Joomla mailer. With a logo file set in com_mails the logo is
     *                                 attached inline as «site-logo» and handed to the layout, like core.
     * @param   string      $plain     Plain-text body as composed.
     * @param   string      $language  Language tag of the recipient.
     * @param   string      $siteName  Site name for the layout.
     * @param   array|null  $config    com_mails options, null reads them from the component.
     *
     * @return  bool  True when the mail goes out as HTML.
     */
    public static function apply(object $mailer, string $plain, string $language, string $siteName, ?array $config = null): bool
    {
        $config ??= ComponentHelper::getParams('com_mails')->toArray();

        if (!self::wantsHtml($config)) {
            $mailer->isHtml(false);
            $mailer->setBody($plain);

            return false;
        }

        $mailer->isHtml(true);
        $mailer->setBody(self::render($plain, $language, $siteName, $config, $mailer));
        $mailer->AltBody = $plain;

        return true;
    }

    /** Whether the mail style of com_mails asks for an HTML part. */
    public static function wantsHtml(array $config): bool
    {
        return \in_array((string) ($config['mail_style'] ?? 'plaintext'), ['html', 'both'], true);
    }

    /** HTML body: the text as escaped paragraphs, inside the layout when the site uses one. */
    public static function render(string $plain, string $language, string $siteName, array $config, ?object $mailer = null): string
    {
        $html = self::textToHtml($plain);

        if ((string) ($config['disable_htmllayout'] ?? '1') !== '1') {
            return $html;
        }

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
                $path = Path::check(JPATH_ROOT . '/' . rawurldecode(HTMLHelper::_('cleanImageURL', $logo)->url));

                if (is_file($path)) {
                    $mailer->addAttachment($path, 'site-logo', 'base64', mime_content_type($path), 'inline');
                    $extra['logo'] = 'site-logo';
                }
            } catch (\Throwable $e) {
                // No logo is better than no mail.
            }
        }

        // The layout is rendered around a marker and only then gets the body, so a layout can
        // neither alter nor re-escape it.
        $marker   = '<!--privacy-mail-body-' . bin2hex(random_bytes(6)) . '-->';
        $rendered = $file->render(['mail' => $marker, 'extra' => $extra]);

        if (!\is_string($rendered) || trim($rendered) === '' || substr_count($rendered, $marker) !== 1) {
            return $html;
        }

        return str_replace($marker, $html, MailHelper::convertRelativeToAbsoluteUrls($rendered));
    }

    /** Escaped plain text as HTML paragraphs, with URLs clickable. */
    public static function textToHtml(string $plain): string
    {
        $blocks = preg_split('/\R{2,}/u', trim(str_replace(["\r\n", "\r"], "\n", $plain))) ?: [];
        $out    = '';

        foreach ($blocks as $block) {
            // Find the URLs in the original text, then escape each piece: escaping first would let
            // the match run into an entity.
            $pieces = preg_split('#(\bhttps?://[^\s<>"\']+)#u', $block, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$block];
            $linked = '';

            foreach ($pieces as $i => $piece) {
                if ($i % 2 === 0) {
                    $linked .= self::escape($piece);
                    continue;
                }

                $tail = '';

                while ($piece !== '' && strpbrk(substr($piece, -1), '.,;:!?)]}') !== false) {
                    $tail  = substr($piece, -1) . $tail;
                    $piece = substr($piece, 0, -1);
                }

                $linked .= '<a href="' . self::escape($piece) . '">' . self::escape($piece) . '</a>' . self::escape($tail);
            }

            $out .= '<p>' . nl2br($linked, false) . "</p>\n";
        }

        return $out;
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

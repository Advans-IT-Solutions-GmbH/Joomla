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
     *
     * @return  string|null  HTML body, or null when the site sends plain-text mails.
     */
    public static function render(string $plain, string $language, string $siteName, ?array $config = null, ?object $mailer = null): ?string
    {
        $config ??= ComponentHelper::getParams('com_mails')->toArray();

        if (!\in_array((string) ($config['mail_style'] ?? 'plaintext'), ['html', 'both'], true)) {
            return null;
        }

        $html = self::textToHtml($plain);

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
                    $path = Path::check(JPATH_ROOT . '/' . HTMLHelper::_('cleanImageURL', $logo)->url);

                    if (is_file(urldecode($path))) {
                        $mailer->addAttachment($path, 'site-logo', 'base64', mime_content_type($path), 'inline');
                        $extra['logo'] = 'site-logo';
                    }
                } catch (\Throwable $e) {
                    // No logo is better than no mail.
                }
            }

            $rendered = $file->render(['mail' => $html, 'extra' => $extra]);

            if (\is_string($rendered) && trim($rendered) !== '') {
                $html = $rendered;
            }
        }

        return MailHelper::convertRelativeToAbsoluteUrls($html);
    }

    /**
     * Escaped plain text as HTML paragraphs, with links clickable.
     */
    public static function textToHtml(string $plain): string
    {
        $blocks = preg_split('/\R{2,}/u', trim(str_replace(["\r\n", "\r"], "\n", $plain))) ?: [];
        $out    = '';

        foreach ($blocks as $block) {
            // Find the URLs in the original text, then escape each piece: escaping first would let
            // the match run into an entity («&gt;») and change a signed link.
            $pieces = preg_split('#(\bhttps?://[^\s<>"\']+)#u', $block, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$block];
            $linked = '';

            foreach ($pieces as $i => $piece) {
                $escaped = htmlspecialchars($piece, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $linked .= $i % 2 === 1 ? '<a href="' . $escaped . '">' . $escaped . '</a>' : $escaped;
            }

            $out .= '<p>' . nl2br($linked, false) . "</p>\n";
        }

        return $out;
    }
}

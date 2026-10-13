<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Administrator\Helper;

defined('_JEXEC') or die;

/**
 * An IMAP transport or protocol failure carrying a coarse category so the
 * controller can tell the operator what went wrong — a connection problem, a
 * TLS failure, a login rejection or a misconfiguration — without exposing the
 * raw technical detail and, above all, without ever echoing a credential. The
 * category is a fixed enumeration the controller maps to a localized reason; the
 * English message is for logs and developers only and is never shown to users.
 *
 * @since  1.0.1
 */
class ImapException extends \RuntimeException
{
    public const CATEGORY_CONNECTION = 'connection';
    public const CATEGORY_TLS = 'tls';
    public const CATEGORY_LOGIN = 'login';
    public const CATEGORY_CONFIG = 'config';

    /**
     * @var string  One of the CATEGORY_* constants.
     */
    private string $category;

    /**
     * @param   string           $message   Developer/log message (never shown to users).
     * @param   string           $category  One of the CATEGORY_* constants.
     * @param   \Throwable|null  $previous  Previous throwable for chaining.
     */
    public function __construct(string $message, string $category = self::CATEGORY_CONNECTION, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);

        $this->category = \in_array(
            $category,
            [self::CATEGORY_CONNECTION, self::CATEGORY_TLS, self::CATEGORY_LOGIN, self::CATEGORY_CONFIG],
            true
        ) ? $category : self::CATEGORY_CONNECTION;
    }

    /**
     * @return  string  One of the CATEGORY_* constants.
     */
    public function getCategory(): string
    {
        return $this->category;
    }
}

<?php
/**
 * @package     DMARC MTA-STS Policy System Plugin
 * @subpackage  Extension
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Plugin\System\DmarcMtaSts\Extension;

defined('_JEXEC') or die;

use Advans\Component\DmarcReports\Administrator\Helper\DomainName;
use Advans\Component\DmarcReports\Administrator\Helper\MtaStsPolicy;
use Advans\Plugin\System\DmarcMtaSts\Support\PolicyRequest;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;

/**
 * Serves the MTA-STS policy (RFC 8461) for the configured receiving domains at
 * https://mta-sts.<domain>/.well-known/mta-sts.txt, generated dynamically from
 * the com_dmarcreports parameters so there is no hand-maintained static file.
 *
 * The plugin only serves the policy document; publishing the mta-sts subdomain
 * (with valid TLS) and the _mta-sts/_smtp._tls DNS records remains a manual DNS
 * step, which the component's Action Center spells out.
 *
 * @since  1.0.0
 */
final class DmarcMtaSts extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;

    /**
     * @return string[]
     *
     * @since 1.0.0
     */
    public static function getSubscribedEvents(): array
    {
        return ['onAfterInitialise' => 'servePolicy'];
    }

    /**
     * Emit the policy document when the current request targets the MTA-STS
     * well-known endpoint on an `mta-sts.` host, otherwise do nothing.
     *
     * @return  void
     *
     * @since 1.0.0
     */
    public function servePolicy(): void
    {
        $app = Factory::getApplication();

        if (!$app->isClient('site')) {
            return;
        }

        if (!class_exists(MtaStsPolicy::class)) {
            return;
        }

        $uri  = Uri::getInstance();

        $domain = PolicyRequest::domainFor($uri->getHost(), $uri->getPath());

        if ($domain === null) {
            return;
        }

        $params = ComponentHelper::getParams('com_dmarcreports');

        if ((int) $params->get('mtasts_enable', 0) !== 1) {
            return;
        }

        // The policy is generated from global component parameters, so without
        // this check the plugin would serve it for the mta-sts. host of any
        // domain pointed at this site, including ones the administrator never
        // configured. Only a domain that is present and published in the
        // monitored-domain table may receive a policy.
        if (!$this->isMonitoredDomain($domain)) {
            return;
        }

        $policy = MtaStsPolicy::generate($params, $domain);

        if (!$policy['enabled'] || $policy['body'] === '') {
            return;
        }

        $app->setHeader('Content-Type', 'text/plain; charset=utf-8', true);
        $app->setHeader('Cache-Control', 'public, max-age=3600', true);
        $app->sendHeaders();

        echo $policy['body'];

        $app->close();
    }

    /**
     * Whether the host-derived base domain is a published monitored domain.
     *
     * The policy must only be served for domains the administrator actually
     * monitors, so the derived domain is canonicalized to match the stored form
     * and looked up in #__dmarc_domains with published = 1. A missing table
     * (component not installed) or any database error fails closed: no policy is
     * served.
     *
     * @since 1.0.0
     */
    private function isMonitoredDomain(string $domain): bool
    {
        $canonical = DomainName::canonicalize($domain);

        if ($canonical === null) {
            return false;
        }

        try {
            $db    = $this->getDatabase();
            $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
            $query->select($db->quoteName('id'))
                ->from($db->quoteName('#__dmarc_domains'))
                ->where($db->quoteName('domain') . ' = :domain')
                ->where($db->quoteName('published') . ' = 1')
                ->bind(':domain', $canonical)
                ->setLimit(1);

            return (int) $db->setQuery($query)->loadResult() > 0;
        } catch (\Throwable) {
            return false;
        }
    }
}

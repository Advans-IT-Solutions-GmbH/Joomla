<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Model;

use Advans\Component\Appointments\Administrator\Service\FeedService;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\Uri\Uri;

defined('_JEXEC') or die;

class FeedsModel extends BaseDatabaseModel
{
    public function getItems(): array
    {
        $app = Factory::getApplication();
        $user = $app->getIdentity();
        if (!$user->authorise('core.manage', 'com_appointments') || !$user->authorise('core.options', 'com_appointments')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $db = $this->getDatabase();
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName(['id', 'host_user_id']))->from($db->quoteName('#__appointments_eventtypes'));
        $ids = [];
        $hosts = [];
        $deniedHosts = [];
        foreach ($db->setQuery($query)->loadAssocList() as $event) {
            $host = (int) $event['host_user_id'];
            if ($user->authorise('core.edit', 'com_appointments.eventtype.' . (int) $event['id'])) {
                $ids[] = (int) $event['id'];
                $hosts[$host] = $host;
            } else {
                $deniedHosts[$host] = true;
            }
        }
        $hosts = array_values(array_diff_key($hosts, $deniedHosts));
        $service = new FeedService($db);
        $items = [];
        foreach ($service->eventCredentials($ids) as $row) {
            $items[] = ['scope' => 'event', 'id' => (int) $row['id'], 'title' => $row['title'],
                'url' => $this->url('event', (int) $row['id'], $row['feed_token'])];
        }
        foreach ($service->hostCredentials(new \DateTimeImmutable('now', new \DateTimeZone('UTC')), $hosts) as $row) {
            $items[] = ['scope' => 'host', 'id' => (int) $row['user_id'], 'title' => $row['title'],
                'url' => $this->url('host', (int) $row['user_id'], $row['feed_token'])];
        }

        return $items;
    }

    private function url(string $scope, int $id, string $token): string
    {
        $root = new Uri(Uri::root());
        if (Uri::getInstance()->isSSL() || (int) Factory::getApplication()->get('force_ssl') === 2) {
            $root->setScheme('https');
        }

        return rtrim($root->toString(['scheme', 'host', 'port', 'path']), '/') . '/index.php?'
            . http_build_query(['option' => 'com_appointments', 'task' => 'feed.download',
                'scope' => $scope, 'id' => $id, 'token' => $token], '', '&', PHP_QUERY_RFC3986);
    }
}

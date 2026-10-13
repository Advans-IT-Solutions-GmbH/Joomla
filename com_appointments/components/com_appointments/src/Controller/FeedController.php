<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Site\Controller;

use Advans\Component\Appointments\Administrator\Helper\FeedCalendar;
use Advans\Component\Appointments\Administrator\Helper\KeyProvider;
use Advans\Component\Appointments\Administrator\Service\FeedService;
use Advans\Component\Appointments\Administrator\Service\RateLimiter;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\Database\DatabaseInterface;

defined('_JEXEC') or die;

class FeedController extends BaseController
{
    public function download(): void
    {
        $this->app->setHeader('X-Robots-Tag', 'noindex, nofollow', true);
        $this->app->setHeader('Cache-Control', 'private, no-store', true);
        $this->app->setHeader('Referrer-Policy', 'no-referrer', true);
        $this->app->setHeader('X-Content-Type-Options', 'nosniff', true);
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $keys = new KeyProvider((string) $this->app->get('secret'));
        $ip = $this->input->server->getString('REMOTE_ADDR');
        if (!(new RateLimiter($db, $keys))->allow('feed', $ip, $now, 60, 300)) {
            $this->app->setHeader('Retry-After', '300', true);
            $this->respond(429, Text::_('COM_APPOINTMENTS_FEEDS_ERROR_RATE'));
            return;
        }
        $token = $this->input->get('token', '', 'raw');
        try {
            $calendar = (new FeedService($db))->download(
                $this->input->getString('scope'), $this->input->getInt('id'),
                is_string($token) ? $token : '', $now
            );
        } catch (\DomainException $e) {
            $this->respond(500, Text::_('COM_APPOINTMENTS_FEEDS_ERROR_DATA'));
            return;
        }
        if ($calendar === null) {
            $this->respond(404, Text::_('COM_APPOINTMENTS_FEEDS_ERROR_NOT_FOUND'));
            return;
        }
        $this->app->setHeader('Content-Type', 'text/calendar; charset=UTF-8; method=PUBLISH', true);
        $this->app->setHeader('Content-Disposition', 'inline; filename="' . $calendar['filename'] . '"', true);
        $this->app->setHeader('ETag', $calendar['etag'], true);
        if (FeedCalendar::matches($this->input->server->getString('HTTP_IF_NONE_MATCH'), $calendar['etag'])) {
            $this->respond(304, '', false);
            return;
        }
        $this->respond(200, $calendar['body'], false);
    }

    private function respond(int $status, string $body, bool $plain = true): void
    {
        $this->app->setHeader('status', (string) $status, true);
        if ($plain) {
            $this->app->setHeader('Content-Type', 'text/plain; charset=UTF-8', true);
        }
        $this->app->sendHeaders();
        if ($this->input->getMethod() !== 'HEAD') {
            echo $body;
        }
        $this->app->close();
    }
}

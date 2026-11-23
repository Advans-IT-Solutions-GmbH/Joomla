<?php
/**
 * @copyright (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\AppointmentsRecovery\Administrator\Dispatcher;

defined('_JEXEC') or die;

use Advans\Installer\AppointmentsConflict\V2\Guard;
use Joomla\CMS\Dispatcher\ComponentDispatcher;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Toolbar\ToolbarHelper;

final class Dispatcher extends ComponentDispatcher
{
    private const PAGE = 'index.php?option=com_appointmentsrecovery';

    public function dispatch()
    {
        $app = $this->getApplication();
        if (!$app->isClient('administrator') || !$app->getIdentity()->authorise('core.admin')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $root = dirname(__DIR__, 2);
        foreach (['FileGuard', 'Knowledge', 'State'] as $name) {
            if (!class_exists('Advans\\Installer\\V3\\' . $name, false)) {
                require_once $root . '/support/' . $name . '.php';
            }
        }
        if (!class_exists(Guard::class, false)) {
            require_once $root . '/support/AppointmentsConflict.php';
        }
        $app->getLanguage()->load('com_appointmentsrecovery', JPATH_ADMINISTRATOR);
        $input = $app->getInput();
        $state = JPATH_ADMINISTRATOR . '/advans-installer-state/com_appointments';
        $page = self::PAGE;

        if ($input->getMethod() === 'GET' && $input->getCmd('view') === 'download') {
            $lock = null;
            try {
                $lock = Guard::lock($state);
                $download = Guard::download($state, $input->getString('plan'), $input->getString('file'));
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename*=UTF-8\'\'' . rawurlencode($download['filename']));
                header('Content-Length: ' . strlen($download['bytes']));
                header('Cache-Control: private, no-store');
                header('X-Content-Type-Options: nosniff');
                echo $download['bytes'];
                exit;
            } catch (\RuntimeException | \JsonException $error) {
                Log::add($error->getMessage(), Log::WARNING, 'advans-installer');
                $app->enqueueMessage(Text::_('COM_APPOINTMENTSRECOVERY_REFUSED'), 'error');
                $app->redirect($page, 303);

                return;
            } finally {
                if (is_resource($lock)) {
                    fclose($lock);
                }
            }
        }

        if ($input->getMethod() === 'POST') {
            if (!Session::checkToken('post')) {
                throw new \RuntimeException(Text::_('JINVALID_TOKEN'), 403);
            }
            $lock = null;
            try {
                $lock = Guard::lock($state);
                $post = $input->post;
                $plan = $post->getString('plan');
                $id = $post->getString('file');
                switch ($post->getCmd('action')) {
                    case 'decide':
                        Guard::decide(
                            $state,
                            $plan,
                            $id,
                            $post->getCmd('choice'),
                            $post->getInt('confirm') === 1
                        );
                        $app->enqueueMessage(Text::_('COM_APPOINTMENTSRECOVERY_SAVED'));
                        break;
                    case 'restore':
                        $upload = $_FILES['restore'] ?? null;
                        if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                            || !is_string($upload['tmp_name'] ?? null) || !is_uploaded_file($upload['tmp_name'])) {
                            throw new \RuntimeException('A local recovery file upload is required.');
                        }
                        Guard::restoreUpload(
                            $state,
                            $plan,
                            $id,
                            $post->getString('expected', 'invalid'),
                            $upload['tmp_name']
                        );
                        $app->enqueueMessage(Text::_('COM_APPOINTMENTSRECOVERY_RESTORED'));
                        break;
                    default:
                        throw new \RuntimeException('Unknown Appointments recovery action.');
                }
            } catch (\RuntimeException | \JsonException $error) {
                Log::add($error->getMessage(), Log::WARNING, 'advans-installer');
                $app->enqueueMessage(Text::_('COM_APPOINTMENTSRECOVERY_REFUSED'), 'error');
            } finally {
                if (is_resource($lock)) {
                    fclose($lock);
                }
            }
            $app->redirect($page, 303);

            return;
        }

        ToolbarHelper::title(Text::_('COM_APPOINTMENTSRECOVERY'));
        ToolbarHelper::help('', false, $page . '&view=help&tmpl=component');
        if ($input->getCmd('view') === 'help') {
            require $root . '/tmpl/help/default.php';

            return;
        }

        // Let Atum's title yield space to the header controls without hiding either.
        $app->getDocument()->getWebAssetManager()->addInline('style', <<<'CSS'
            body.com_appointmentsrecovery #header .header-title,
            body.com_appointmentsrecovery #header .container-title {
                min-inline-size: 0;
            }
            body.com_appointmentsrecovery #header .page-title {
                white-space: normal;
                overflow-wrap: anywhere;
            }
            CSS);

        $review = Guard::current($state);
        $attempt = Guard::attempt($state);
        require $root . '/tmpl/review/default.php';
    }
}

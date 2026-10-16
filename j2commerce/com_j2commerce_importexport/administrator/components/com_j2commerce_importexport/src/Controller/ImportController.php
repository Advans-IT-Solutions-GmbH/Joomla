<?php
/**
 * @package     J2Commerce Import/Export Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\J2CommerceImportExport\Administrator\Controller;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Factory;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Language\Text;

class ImportController extends BaseController
{
    private function checkAccess(): void
    {
        $user = Factory::getApplication()->getIdentity();
        if (!$user->authorise('core.manage', 'com_j2commerce_importexport')) {
            throw new \Exception(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
    }

    public function upload()
    {
        Session::checkToken() or jexit(Text::_('JINVALID_TOKEN'));
        $this->checkAccess();
        
        $app = Factory::getApplication();
        $input = $app->getInput();

        $file = $input->files->get('import_file');

        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            $this->sendJson(['success' => false, 'message' => Text::_('COM_J2COMMERCE_IMPORTEXPORT_ERROR_UPLOAD_FAILED')]);
        }

        // Keep upload formats aligned with the model's parser.
        $allowedExtensions = ['csv', 'json'];
        $allowedMimeTypes  = [
            'text/csv',
            'text/plain',
            'application/csv',
            'application/json',
        ];

        // Enforce 10 MB upload limit
        $maxBytes = 10 * 1024 * 1024;
        if (($file['size'] ?? 0) > $maxBytes) {
            $this->sendJson(['success' => false, 'message' => Text::_('COM_J2COMMERCE_IMPORTEXPORT_ERROR_FILE_TOO_LARGE')]);
        }

        $originalName  = $file['name'] ?? '';
        $fileExtension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $detectedMime  = mime_content_type($file['tmp_name']);

        if (!in_array($fileExtension, $allowedExtensions, true) || !in_array($detectedMime, $allowedMimeTypes, true)) {
            $this->sendJson(['success' => false, 'message' => Text::_('COM_J2COMMERCE_IMPORTEXPORT_ERROR_INVALID_FILE_TYPE')]);
        }

        $session = $app->getSession();
        // Use cryptographically random token instead of predictable uniqid()
        $tmpPath = JPATH_ROOT . '/tmp/j2commerce_import_' . bin2hex(random_bytes(16)) . '.' . $fileExtension;
        
        if (move_uploaded_file($file['tmp_name'], $tmpPath)) {
            $session->set('import_file', $tmpPath, 'j2commerce_import');
            $this->sendJson(['success' => true, 'file' => basename($tmpPath)]);
        } else {
            $this->sendJson(['success' => false, 'message' => Text::_('COM_J2COMMERCE_IMPORTEXPORT_ERROR_SAVE_FAILED')]);
        }

        $app->close();
    }

    public function preview()
    {
        Session::checkToken() or jexit(Text::_('JINVALID_TOKEN'));
        $this->checkAccess();
        
        $app = Factory::getApplication();
        $filePath = $this->getImportFile();

        try {
            $model = $this->getModel('Import', 'Administrator');
            $preview = $model->previewFile($filePath);

            if ($preview['total'] === 0) {
                throw new \InvalidArgumentException(Text::_('COM_J2COMMERCE_IMPORTEXPORT_ERROR_NO_DATA'));
            }
        } catch (\InvalidArgumentException $e) {
            $this->sendJson(['success' => false, 'message' => $e->getMessage()]);
        } catch (\Exception $e) {
            \Joomla\CMS\Log\Log::add('Import preview error: ' . $e->getMessage(), \Joomla\CMS\Log\Log::ERROR, 'com_j2commerce_importexport');
            $this->sendJson(['success' => false, 'message' => Text::_('COM_J2COMMERCE_IMPORTEXPORT_ERROR_PREVIEW_FAILED')]);
        }

        $this->sendJson(['success' => true, 'preview' => $preview]);
    }

    public function process()
    {
        Session::checkToken() or jexit(Text::_('JINVALID_TOKEN'));
        $this->checkAccess();
        
        $app = Factory::getApplication();
        $session = $app->getSession();
        $input = $app->getInput();
        
        $filePath = $this->getImportFile();
        $mapping = $input->get('mapping', [], 'array');
        $type = $input->get('type', 'products_full', 'string');
        $options = $input->get('options', [], 'array');
        // Unchecked HTML checkboxes are absent, not model defaults.
        $options += ['update_existing' => false, 'create_menu' => false, 'menu_published' => false];

        try {
            $model = $this->getModel('Import', 'Administrator');
            $result = $model->importData($filePath, $type, $mapping, $options);

            // Clean up
            @unlink($filePath);
            $session->clear('import_file', 'j2commerce_import');

            $this->sendJson([
                'success' => $result['imported'] > 0,
                'message' => $result['imported'] > 0 ? '' : Text::_('COM_J2COMMERCE_IMPORTEXPORT_ERROR_IMPORT_FAILED'),
                'imported' => $result['imported'],
                'failed' => $result['failed'],
                'errors' => $result['errors']
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->sendJson(['success' => false, 'message' => $e->getMessage()]);
        } catch (\Exception $e) {
            \Joomla\CMS\Log\Log::add('Import process error: ' . $e->getMessage(), \Joomla\CMS\Log\Log::ERROR, 'com_j2commerce_importexport');
            $this->sendJson(['success' => false, 'message' => Text::_('COM_J2COMMERCE_IMPORTEXPORT_ERROR_IMPORT_FAILED')]);
        }
    }

    private function getImportFile(): string
    {
        $app = Factory::getApplication();
        $filePath = $app->getSession()->get('import_file', '', 'j2commerce_import');
        if (!is_file($filePath)) {
            $this->sendJson(['success' => false, 'message' => Text::_('COM_J2COMMERCE_IMPORTEXPORT_ERROR_FILE_NOT_FOUND')]);
        }
        $file = $app->getInput()->get('file', '', 'string');
        if ($file === '' || !hash_equals(basename($filePath), $file)) {
            $this->sendJson(['success' => false, 'message' => Text::_('COM_J2COMMERCE_IMPORTEXPORT_ERROR_UPLOAD_CHANGED')]);
        }
        return $filePath;
    }

    /**
     * Emit a flat JSON response and terminate the request.
     *
     * The dashboard JS and the HTTP tests read success/imported/failed/message
     * at the top level. JsonResponse would nest the payload under a "data" key,
     * so we encode a flat structure and set the JSON content type explicitly for
     * both success and error cases to keep the shape and headers consistent.
     *
     * @param   array  $payload  The flat response payload.
     *
     * @return  void
     */
    private function sendJson(array $payload): void
    {
        $app = Factory::getApplication();
        $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $app->sendHeaders();
        echo json_encode($payload);
        $app->close();
    }
}

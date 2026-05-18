<?php
/**
 * @package     J2Commerce Import/Export Component
 * @subpackage  Administrator
 * @copyright   Copyright (C) 2026 Advans IT Solutions GmbH. All rights reserved.
 * @license     Proprietary
 */

namespace Advans\Component\J2CommerceImportExport\Administrator\Controller;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Response\JsonResponse;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;

class ExportController extends BaseController
{
    private function checkAccess(): void
    {
        $user = Factory::getApplication()->getIdentity();
        if (!$user->authorise('core.admin', 'com_j2commerce_importexport')) {
            throw new \Exception(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
    }

    /**
     * Field descriptions for export documentation
     */
    protected function getFieldDescriptions(): array
    {
        $baseUrl = Uri::root();
        
        return [
            // Article fields
            'article_id' => 'Joomla Article ID (leave empty for new articles, used for updates)',
            'title' => 'Product/Article title (required)',
            'alias' => 'URL-safe alias (auto-generated from title if empty)',
            'introtext' => 'Short description (HTML allowed)',
            'fulltext' => 'Full description (HTML allowed)',
            'article_state' => 'Published state: 1=published, 0=unpublished, -2=trashed',
            'catid' => 'Joomla Category ID',
            'category_title' => 'Category name (creates category if not exists)',
            'category_path' => 'Category path like "shop/electronics/phones"',
            'access' => 'Access level: 1=Public, 2=Registered, 3=Special',
            'language' => 'Language code: * for all, de-DE, en-GB, etc.',
            'featured' => 'Featured: 1=yes, 0=no',
            'metakey' => 'Meta keywords (comma-separated)',
            'metadesc' => 'Meta description for SEO',
            
            // J2Store product fields
            'j2store_product_id' => 'J2Store Product ID (leave empty for new products)',
            'product_type' => 'Product type: simple, configurable, variable, downloadable',
            'visibility' => 'Visibility: 1=visible, 0=hidden',
            'enabled' => 'Enabled: 1=yes, 0=no',
            'taxprofile_id' => 'Tax profile ID (0 for no tax)',
            'manufacturer_id' => 'Manufacturer ID',
            'vendor_id' => 'Vendor ID',
            'has_options' => 'Has options: 1=yes, 0=no',
            
            // Variant fields
            'sku' => 'Stock Keeping Unit - unique product identifier (used for duplicate detection)',
            'price' => 'Product price (decimal, e.g., 99.95)',
            'quantity' => 'Stock quantity (integer)',
            'manage_stock' => 'Manage stock: 1=yes (track inventory), 0=no (unlimited)',
            'weight' => 'Weight in configured unit (decimal)',
            'length' => 'Length in configured unit (decimal)',
            'width' => 'Width in configured unit (decimal)',
            'height' => 'Height in configured unit (decimal)',
            
            // Image fields
            'main_image' => "Image path relative to Joomla root. Example: 'images/products/phone.jpg'. Upload images via FTP/Media Manager first, then reference the path here.",
            'thumb_image' => 'Thumbnail image path (optional). Example: images/products/phone_thumb.jpg',
            'additional_images' => 'JSON array of additional image paths. Example: ["images/products/img1.jpg","images/products/img2.jpg"]',
            
            // Options
            'options' => 'JSON array of product options with values and price adjustments',
            
            // Tags
            'tags' => 'JSON array of tags: [{"title":"New","alias":"new"}]',
            
            // Custom fields
            'custom_fields' => 'JSON array of Joomla custom fields: [{"name":"material","value":"Cotton"}]',
        ];
    }

    public function export()
    {
        Session::checkToken() or jexit(Text::_('JINVALID_TOKEN'));
        $this->checkAccess();

        $app = Factory::getApplication();
        $input = $app->input;

        $allowedTypes   = ['products_full', 'products', 'categories', 'variants', 'prices'];
        $allowedFormats = ['csv', 'xml', 'json'];
        $type           = $input->get('type', 'products', 'string');
        $format         = $input->get('format', 'csv', 'string');
        $includeHelp    = $input->get('include_help', 1, 'int');

        if (!in_array($type, $allowedTypes, true)) {
            $type = 'products';
        }
        if (!in_array($format, $allowedFormats, true)) {
            $format = 'csv';
        }

        try {
            $model    = $this->getModel('Export', 'Administrator');
            $data     = $model->exportData($type);
            $filename = $type . '_' . date('Y-m-d_H-i-s') . '.' . $format;

            switch ($format) {
                case 'csv':
                    $this->exportCSV($data, $filename, $includeHelp);
                    break;
                case 'xml':
                    $this->exportXML($data, $filename, $includeHelp);
                    break;
                case 'json':
                    $this->exportJSON($data, $filename, $includeHelp);
                    break;
            }
        } catch (\Exception $e) {
            Log::add('Export error: ' . $e->getMessage(), Log::ERROR, 'com_j2commerce_importexport');
            echo new JsonResponse(['error' => Text::_('COM_J2COMMERCE_IMPORTEXPORT_ERROR_EXPORT_FAILED')], '', true);
        }

        $app->close();
    }

    protected function exportCSV($data, $filename, $includeHelp = true)
    {
        $app = Factory::getApplication();
        $params = $app->getParams();
        
        $delimiter = $params->get('csv_delimiter', ',');
        $enclosure = $params->get('csv_enclosure', '"');

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        // UTF-8 BOM for Excel compatibility
        echo "\xEF\xBB\xBF";

        $output = fopen('php://output', 'w');

        if (!empty($data)) {
            $headers = array_keys($data[0]);
            
            // Add description row before headers
            if ($includeHelp) {
                $descriptions = $this->getFieldDescriptions();
                $descRow = [];
                foreach ($headers as $header) {
                    $descRow[] = '# ' . ($descriptions[$header] ?? $header);
                }
                fputcsv($output, $descRow, $delimiter, $enclosure);
            }
            
            // Headers
            fputcsv($output, $headers, $delimiter, $enclosure);
            
            // Data
            foreach ($data as $row) {
                fputcsv($output, $row, $delimiter, $enclosure);
            }
        }

        fclose($output);
    }

    protected function exportXML($data, $filename, $includeHelp = true)
    {
        header('Content-Type: text/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><export></export>');
        
        // Add field documentation
        if ($includeHelp && !empty($data)) {
            $docs = $xml->addChild('field_documentation');
            $descriptions = $this->getFieldDescriptions();
            foreach (array_keys($data[0]) as $field) {
                $fieldDoc = $docs->addChild('field');
                $fieldDoc->addAttribute('name', $field);
                $fieldDoc->addAttribute('description', $descriptions[$field] ?? $field);
            }
        }
        
        $items = $xml->addChild('items');
        foreach ($data as $row) {
            $item = $items->addChild('item');
            foreach ($row as $key => $value) {
                if (is_array($value)) {
                    $value = json_encode($value);
                }
                $item->addChild($key, htmlspecialchars((string) $value));
            }
        }

        echo $xml->asXML();
    }

    protected function exportJSON($data, $filename, $includeHelp = true)
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $output = [];
        
        // Add field documentation
        if ($includeHelp) {
            $output['_documentation'] = [
                'description' => 'J2Commerce Product Export - Field Documentation',
                'import_notes' => [
                    'Images' => 'Upload images to your server first (via FTP or Media Manager), then reference the path relative to Joomla root (e.g., images/products/phone.jpg).',
                    'Duplicates' => 'Existing products are detected by: 1) article_id, 2) alias, 3) SKU. Matching products will be updated.',
                    'Categories' => 'Categories are created automatically if category_path is provided and category does not exist.',
                    'Stock' => 'Set manage_stock=0 to disable inventory tracking (unlimited stock).',
                    'CSV_Comments' => 'Lines starting with # are comments and will be ignored during import.',
                ],
                'fields' => $this->getFieldDescriptions(),
            ];
        }
        
        $output['products'] = $data;

        echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

<?php
/**
 * J2Commerce Product Compare Plugin
 *
 * @subpackage  Extension
 * @copyright   Copyright (C) 2026 Advans IT Solutions GmbH. All rights reserved.
 * @license     Proprietary
 */

namespace Advans\Plugin\J2Commerce\ProductCompare\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\FileLayout;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Response\JsonResponse;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\ParameterType;

class ProductCompare extends CMSPlugin
{
    protected $autoloadLanguage = true;

    /**
     * Register assets with WebAssetManager and pass JS configuration.
     *
     * Assets (CSS + JS) are registered via joomla.asset.json and enqueued
     * here. Configuration is passed via Joomla's script options mechanism
     * (rendered as a JSON blob in <head>, read by JS via Joomla.getOptions()).
     */
    public function onAfterDispatch(): void
    {
        $app = $this->getApplication();

        if ($app->isClient('administrator')) {
            return;
        }

        $doc = $app->getDocument();

        if ($doc->getType() !== 'html') {
            return;
        }

        $wa = $doc->getWebAssetManager();
        $wa->getRegistry()->addRegistryFile('media/plg_j2store_productcompare/joomla.asset.json');
        $wa->useStyle('plg_j2store_productcompare.css')
           ->useScript('plg_j2store_productcompare');

        // Configuration for JS — rendered as JSON in <head>, no inline <script> needed
        $doc->addScriptOptions('plg_j2store_productcompare', [
            'maxProducts' => (int) $this->params->get('max_products', 4),
            'ajaxUrl'     => Uri::base() . 'index.php?option=com_ajax&plugin=productcompare&group=j2store&format=json',
        ]);
    }

    /**
     * Inject compare bar and modal HTML before </body>.
     *
     * onAfterRender + setBody() is the official Joomla 5 mechanism for
     * injecting HTML into the rendered body. We use the literal </body>
     * marker which is guaranteed to be present in a valid HTML response.
     */
    public function onAfterRender(): void
    {
        $app = $this->getApplication();

        if ($app->isClient('administrator')) {
            return;
        }

        $doc = $app->getDocument();

        if ($doc->getType() !== 'html') {
            return;
        }

        $html = $this->renderLayout('bar', []) . "\n" . $this->renderLayout('modal', []);

        $body = $app->getBody();
        $app->setBody(str_replace('</body>', $html . "\n</body>", $body));
    }

    /**
     * Render compare button after a product in list view.
     */
    public function onJ2StoreAfterDisplayProductList(object $product): string
    {
        if (!$this->params->get('show_in_list', 1)) {
            return '';
        }

        return $this->renderCompareButton($product->j2store_product_id);
    }

    /**
     * Render compare button on the product detail page.
     */
    public function onJ2StoreAfterDisplayProduct(object $product, string $view): string
    {
        if (!$this->params->get('show_in_detail', 1)) {
            return '';
        }

        return $this->renderCompareButton($product->j2store_product_id);
    }

    /**
     * AJAX endpoint — returns comparison table HTML for the requested product IDs.
     *
     * Called via com_ajax:
     *   index.php?option=com_ajax&plugin=productcompare&group=j2store&format=json
     */
    public function onAjaxProductcompare(): void
    {
        $app = $this->getApplication();

        if (!Session::checkToken('get') && !Session::checkToken()) {
            echo new JsonResponse(null, Text::_('JINVALID_TOKEN'), true);
            $app->close();
        }

        try {
            $productIds = array_map('intval', (array) $app->input->get('products', [], 'array'));
            $productIds = array_filter($productIds);

            if (count($productIds) < 2) {
                throw new \RuntimeException(Text::_('PLG_J2COMMERCE_PRODUCTCOMPARE_ERROR_MIN_PRODUCTS'));
            }

            $products = $this->getProductsData($productIds);
            $html     = $this->renderLayout('table', ['products' => $products]);

            echo new JsonResponse(['html' => $html]);
        } catch (\Exception $e) {
            echo new JsonResponse(null, $e->getMessage(), true);
        }

        $app->close();
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Render a plugin layout with template-override support.
     *
     * Override resolution order (first match wins):
     *   1. templates/{active-template}/html/plg_j2store_productcompare/{layout}.php
     *   2. plugins/j2store/productcompare/tmpl/{layout}.php
     *
     * @param   string  $layout  Layout name (without .php)
     * @param   array   $data    Variables passed to the layout
     */
    private function renderLayout(string $layout, array $data): string
    {
        $basePath = JPATH_PLUGINS . '/j2store/productcompare/tmpl';

        $fileLayout = new FileLayout($layout, $basePath);
        $fileLayout->addIncludePath(
            JPATH_THEMES . '/' . $this->getApplication()->getTemplate() . '/html/plg_j2store_productcompare'
        );

        return $fileLayout->render($data);
    }

    /**
     * Render the compare button layout.
     */
    private function renderCompareButton(int $productId): string
    {
        return $this->renderLayout('button', [
            'productId'   => $productId,
            'buttonText'  => Text::_($this->params->get('button_text', 'PLG_J2COMMERCE_PRODUCTCOMPARE_DEFAULT_BUTTON_TEXT')),
            'buttonClass' => $this->params->get('button_class', 'btn btn-secondary'),
        ]);
    }

    /**
     * Load product data for the given IDs.
     *
     * @param   int[]  $productIds
     * @return  object[]
     */
    private function getProductsData(array $productIds): array
    {
        $db = $this->getDatabase();

        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('p.j2store_product_id'),
                $db->quoteName('p.product_source_id'),
                $db->quoteName('v.j2store_variant_id'),
                $db->quoteName('v.sku'),
                $db->quoteName('v.price'),
                $db->quoteName('v.stock'),
                $db->quoteName('v.availability'),
                $db->quoteName('c.title'),
                $db->quoteName('c.introtext'),
            ])
            ->from($db->quoteName('#__j2store_products', 'p'))
            ->join('LEFT', $db->quoteName('#__j2store_variants', 'v') . ' ON ' . $db->quoteName('p.j2store_product_id') . ' = ' . $db->quoteName('v.product_id'))
            ->join('LEFT', $db->quoteName('#__content', 'c') . ' ON ' . $db->quoteName('p.product_source_id') . ' = ' . $db->quoteName('c.id'))
            ->whereIn($db->quoteName('p.j2store_product_id'), $productIds)
            ->where($db->quoteName('p.enabled') . ' = 1')
            ->order($db->quoteName('p.j2store_product_id'));

        $db->setQuery($query);
        $products = $db->loadObjectList() ?: [];

        foreach ($products as &$product) {
            $product->options = $this->getProductOptions($product->j2store_product_id);
        }

        return $products;
    }

    /**
     * Load product options for a single product.
     */
    private function getProductOptions(int $productId): array
    {
        $db = $this->getDatabase();

        $query = $db->getQuery(true)
            ->select([$db->quoteName('option_name'), $db->quoteName('option_value')])
            ->from($db->quoteName('#__j2store_product_options'))
            ->where($db->quoteName('product_id') . ' = :productid')
            ->bind(':productid', $productId, ParameterType::INTEGER);

        $db->setQuery($query);

        try {
            return $db->loadAssocList() ?: [];
        } catch (\Exception $e) {
            return [];
        }
    }
}

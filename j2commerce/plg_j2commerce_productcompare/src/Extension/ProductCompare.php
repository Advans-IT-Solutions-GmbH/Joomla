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
use Joomla\Database\DatabaseAwareInterface;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\ParameterType;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;

class ProductCompare extends CMSPlugin implements DatabaseAwareInterface, SubscriberInterface
{
    use DatabaseAwareTrait;

    /**
     * Subscribe to J2Commerce 6 per-item hooks.
     *
     * These events are dispatched by J2Commerce 6 layout files via
     * J2CommerceHelper::plugin()->eventWithHtml():
     *
     *   AfterProductListItemDisplay — fired in list/category/item_*.php after
     *     each product card. Args: [$product, $context, &$displayData].
     *
     *   AfterProductDisplay — fired in app_bootstrap5/tmpl/bootstrap5/view.php
     *     after the product detail block. Args: [$product, $view].
     *
     * J2Commerce 4 events (onJ2Store*) are handled by the legacy method-name
     * convention and do not need entries here.
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onJ2CommerceAfterProductListItemDisplay' => 'onJ2CommerceAfterProductListItemDisplay',
            'onJ2CommerceAfterProductDisplay'         => 'onJ2CommerceAfterProductDisplay',
        ];
    }

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
        $wa->getRegistry()->addRegistryFile('media/plg_j2commerce_productcompare/joomla.asset.json');
        $wa->useStyle('plg_j2commerce_productcompare.css')
           ->useScript('plg_j2commerce_productcompare');

        // Configuration for JS — rendered as JSON in <head>, no inline <script> needed
        // com_ajax resolves plugins by their installed group (folder in #__extensions).
        // The group is set to j2store on J4/J5 and j2commerce on J6 by the installer script.
        $doc->addScriptOptions('plg_j2commerce_productcompare', [
            'maxProducts' => (int) $this->params->get('max_products', 4),
            'ajaxUrl'     => Uri::base() . 'index.php?option=com_ajax&plugin=productcompare&group=' . $this->_type . '&format=json',
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
     * J2Commerce 4 — render compare button after a product in list view.
     * Event fired by J2Commerce 4 (j2store group). Not fired on J2Commerce 6.
     */
    public function onJ2StoreAfterDisplayProductList(object $product): string
    {
        if (!$this->params->get('show_in_list', 1) || $this->isJ2Commerce6()) {
            return '';
        }

        return $this->renderCompareButton((int) $product->j2store_product_id);
    }

    /**
     * J2Commerce 4 — render compare button on the product detail page.
     * Event fired by J2Commerce 4 (j2store group). Not fired on J2Commerce 6.
     */
    public function onJ2StoreAfterDisplayProduct(object $product, string $view): string
    {
        if (!$this->params->get('show_in_detail', 1) || $this->isJ2Commerce6()) {
            return '';
        }

        return $this->renderCompareButton((int) $product->j2store_product_id);
    }

    /**
     * J2Commerce 6 — inject compare button after each product card in list view.
     *
     * Fired by list/category/item_*.php via:
     *   J2CommerceHelper::plugin()->eventWithHtml('AfterProductListItemDisplay', [$product, $context, &$displayData])
     *
     * The first argument is the product object with j2commerce_product_id.
     */
    public function onJ2CommerceAfterProductListItemDisplay(Event $event): void
    {
        if (!$this->params->get('show_in_list', 1)) {
            return;
        }

        $args    = $event->getArguments();
        $product = $args[0] ?? null;

        if (!$product || !isset($product->j2commerce_product_id)) {
            return;
        }

        $event->addResult($this->renderCompareButton((int) $product->j2commerce_product_id));
    }

    /**
     * J2Commerce 6 — inject compare button after the product detail block.
     *
     * Fired by app_bootstrap5/tmpl/bootstrap5/view.php via:
     *   J2CommerceHelper::plugin()->eventWithHtml('AfterProductDisplay', [...])
     *
     * The argument signature may vary across J2Commerce 6 versions. We scan all
     * arguments for the first object that carries j2commerce_product_id rather
     * than relying on a fixed index.
     */
    public function onJ2CommerceAfterProductDisplay(Event $event): void
    {
        if (!$this->params->get('show_in_detail', 1)) {
            return;
        }

        $product = null;

        foreach ($event->getArguments() as $arg) {
            if (is_object($arg) && isset($arg->j2commerce_product_id)) {
                $product = $arg;
                break;
            }
        }

        if ($product === null) {
            return;
        }

        $event->addResult($this->renderCompareButton((int) $product->j2commerce_product_id));
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
     *   1. templates/{active-template}/html/plg_j2commerce_productcompare/{layout}.php
     *   2. plugins/j2store/productcompare/tmpl/{layout}.php
     *
     * @param   string  $layout  Layout name (without .php)
     * @param   array   $data    Variables passed to the layout
     */
    private function renderLayout(string $layout, array $data): string
    {
        // $this->_type is the plugin group (j2store on J4/J5, j2commerce on J6)
        $basePath = JPATH_PLUGINS . '/' . $this->_type . '/productcompare/tmpl';

        $fileLayout = new FileLayout($layout, $basePath);
        $fileLayout->addIncludePath(
            JPATH_THEMES . '/' . $this->getApplication()->getTemplate() . '/html/plg_j2commerce_productcompare'
        );

        return $fileLayout->render($data);
    }

    /**
     * Render the compare button layout.
     */
    protected function renderCompareButton(int $productId): string
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
    /**
     * Create a fresh query object — compatible with Joomla 4/5 (getQuery) and 6 (createQuery).
     */
    private function createDbQuery(\Joomla\Database\DatabaseInterface $db): \Joomla\Database\QueryInterface
    {
        return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
    }

    /**
     * Detect J2Commerce 6 by checking for #__j2commerce_products in the database.
     * Uses SHOW TABLES LIKE to avoid stale getTableList() cache (e.g. during install).
     * Cached after first call.
     */
    private ?bool $j2commerce6 = null;

    private function isJ2Commerce6(): bool
    {
        if ($this->j2commerce6 === null) {
            $db     = $this->getDatabase();
            $result = $db->setQuery('SHOW TABLES LIKE ' . $db->quote($db->getPrefix() . 'j2commerce_products'))->loadResult();
            $this->j2commerce6 = !empty($result);
        }
        return $this->j2commerce6;
    }

    private function getProductsData(array $productIds): array
    {
        $db  = $this->getDatabase();
        $j6  = $this->isJ2Commerce6();

        $productsPk  = $j6 ? 'j2commerce_product_id' : 'j2store_product_id';
        $variantsPk  = $j6 ? 'j2commerce_variant_id' : 'j2store_variant_id';
        $productsT   = $j6 ? '#__j2commerce_products' : '#__j2store_products';
        $variantsT   = $j6 ? '#__j2commerce_variants'  : '#__j2store_variants';

        $query = $this->createDbQuery($db)
            ->select([
                $db->quoteName('p') . '.' . $db->quoteName($productsPk),
                $db->quoteName('p') . '.' . $db->quoteName('product_source_id'),
                $db->quoteName('v') . '.' . $db->quoteName($variantsPk),
                $db->quoteName('v') . '.' . $db->quoteName('sku'),
                $db->quoteName('v') . '.' . $db->quoteName('price'),
                $db->quoteName('v') . '.' . $db->quoteName('availability'),
                $db->quoteName('c') . '.' . $db->quoteName('title'),
                $db->quoteName('c') . '.' . $db->quoteName('introtext'),
            ])
            ->from($db->quoteName($productsT, 'p'))
            ->join('LEFT', $db->quoteName($variantsT, 'v')
                . ' ON ' . $db->quoteName('v') . '.' . $db->quoteName('product_id')
                . ' = ' . $db->quoteName('p') . '.' . $db->quoteName($productsPk))
            ->join('LEFT', $db->quoteName('#__content', 'c')
                . ' ON ' . $db->quoteName('c') . '.' . $db->quoteName('id')
                . ' = ' . $db->quoteName('p') . '.' . $db->quoteName('product_source_id'))
            ->whereIn($db->quoteName('p') . '.' . $db->quoteName($productsPk), $productIds)
            ->where($db->quoteName('p') . '.' . $db->quoteName('enabled') . ' = 1')
            ->order($db->quoteName('p') . '.' . $db->quoteName($productsPk));

        $db->setQuery($query);
        $products = $db->loadObjectList() ?: [];

        foreach ($products as &$product) {
            $product->options = $this->getProductOptions((int) $product->$productsPk);
        }

        return $products;
    }

    /**
     * Load product options for a single product, normalised to [{option_name, option_value}].
     *
     * Both J2Store 4 and J2Commerce 6 use a mapping table:
     *   product_options (product_id → option_id) + options (option_name) + optionvalues (optionvalue_name)
     * joined via product_optionvalues.
     */
    private function getProductOptions(int $productId): array
    {
        $db = $this->getDatabase();

        if ($this->isJ2Commerce6()) {
            // J2Commerce 6 schema
            $query = $this->createDbQuery($db)
                ->select([
                    $db->quoteName('o.option_name', 'option_name'),
                    $db->quoteName('ov.optionvalue_name', 'option_value'),
                ])
                ->from($db->quoteName('#__j2commerce_product_options', 'po'))
                ->join('LEFT', $db->quoteName('#__j2commerce_options', 'o')
                    . ' ON ' . $db->quoteName('o.j2commerce_option_id') . ' = ' . $db->quoteName('po.option_id'))
                ->join('LEFT', $db->quoteName('#__j2commerce_product_optionvalues', 'pov')
                    . ' ON ' . $db->quoteName('pov.productoption_id') . ' = ' . $db->quoteName('po.j2commerce_productoption_id'))
                ->join('LEFT', $db->quoteName('#__j2commerce_optionvalues', 'ov')
                    . ' ON ' . $db->quoteName('ov.j2commerce_optionvalue_id') . ' = ' . $db->quoteName('pov.optionvalue_id'))
                ->where($db->quoteName('po.product_id') . ' = :productid')
                ->bind(':productid', $productId, ParameterType::INTEGER)
                ->order($db->quoteName('po.ordering') . ' ASC');
        } else {
            // J2Store 4 schema: product_options is also a mapping table
            $query = $this->createDbQuery($db)
                ->select([
                    $db->quoteName('o.option_name', 'option_name'),
                    $db->quoteName('ov.optionvalue_name', 'option_value'),
                ])
                ->from($db->quoteName('#__j2store_product_options', 'po'))
                ->join('LEFT', $db->quoteName('#__j2store_options', 'o')
                    . ' ON ' . $db->quoteName('o.j2store_option_id') . ' = ' . $db->quoteName('po.option_id'))
                ->join('LEFT', $db->quoteName('#__j2store_product_optionvalues', 'pov')
                    . ' ON ' . $db->quoteName('pov.productoption_id') . ' = ' . $db->quoteName('po.j2store_productoption_id'))
                ->join('LEFT', $db->quoteName('#__j2store_optionvalues', 'ov')
                    . ' ON ' . $db->quoteName('ov.j2store_optionvalue_id') . ' = ' . $db->quoteName('pov.optionvalue_id'))
                ->where($db->quoteName('po.product_id') . ' = :productid')
                ->bind(':productid', $productId, ParameterType::INTEGER)
                ->order($db->quoteName('po.ordering') . ' ASC');
        }

        $db->setQuery($query);

        try {
            return $db->loadAssocList() ?: [];
        } catch (\Exception $e) {
            return [];
        }
    }
}

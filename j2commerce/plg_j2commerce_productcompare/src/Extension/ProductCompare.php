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
     * Subscribe to J2Commerce 6 events by their full dispatched names.
     *
     * J2Commerce 6 dispatches events via PluginHelper::eventWithHtml() which
     * prepends 'onJ2Commerce' to the event name. Confirmed from J2Commerce 6
     * PluginHelper source and app_bootstrap5 getSubscribedEvents().
     * J2Commerce 4 events (onJ2Store*) are handled by the legacy method-name
     * convention and do not need entries here.
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onJ2CommerceViewProductListHtml' => 'onJ2CommerceViewProductListHtml',
            'onJ2CommerceViewProductHtml'     => 'onJ2CommerceViewProductHtml',
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
        $wa->getRegistry()->addRegistryFile('media/plg_j2store_productcompare/joomla.asset.json');
        $wa->useStyle('plg_j2store_productcompare.css')
           ->useScript('plg_j2store_productcompare');

        // Configuration for JS — rendered as JSON in <head>, no inline <script> needed
        // The plugin is installed in group=j2store (see manifest). com_ajax
        // resolves the plugin by its installed group, so the AJAX URL must
        // always use group=j2store regardless of the J2Commerce version.
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
     * J2Commerce 6 — attempt to inject compare button via the ViewProductListHtml event.
     *
     * NOTE: ViewProductListHtml is a full view-render replacement event in J2Commerce 6
     * (dispatched with [null, &$view, $model]). addResult() appends to the 'html' result
     * set, but whether the template actually renders that HTML depends on the J2Commerce 6
     * view implementation. Button injection via this event is not guaranteed to work.
     * See issue #118 for the correct J6 approach.
     */
    public function onJ2CommerceViewProductListHtml(Event $event): void
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
     * J2Commerce 6 — attempt to inject compare button via the ViewProductHtml event.
     *
     * NOTE: ViewProductHtml is a full view-render replacement event in J2Commerce 6.
     * This handler scans arguments for a product object and calls addResult(), but
     * button injection via this event is not guaranteed to work in J2Commerce 6.
     * See issue #118 for the correct J6 approach.
     */
    public function onJ2CommerceViewProductHtml(Event $event): void
    {
        if (!$this->params->get('show_in_detail', 1)) {
            return;
        }

        $item = null;
        foreach ($event->getArguments() as $arg) {
            if (is_object($arg) && isset($arg->j2commerce_product_id)) {
                $item = $arg;
                break;
            }
        }

        if ($item === null) {
            return;
        }

        $event->addResult($this->renderCompareButton((int) $item->j2commerce_product_id));
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
                $db->quoteName('v') . '.' . $db->quoteName('stock'),
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
     * Load product options for a single product.
     *
     * J2Commerce 4: #__j2store_product_options has option_name / option_value columns.
     *
     * J2Commerce 6: #__j2commerce_product_options is a mapping table with columns
     * j2commerce_productoption_id, option_id, parent_id, product_id, ordering,
     * required, is_variant — no option_name/option_value. The option labels live in
     * a separate #__j2commerce_options table whose schema is not yet confirmed.
     * Returns empty array on J6 until the full schema is mapped (see #118).
     */
    private function getProductOptions(int $productId): array
    {
        if ($this->isJ2Commerce6()) {
            // TODO(#118): implement J6 option lookup via #__j2commerce_options join
            return [];
        }

        $db = $this->getDatabase();

        $query = $this->createDbQuery($db)
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

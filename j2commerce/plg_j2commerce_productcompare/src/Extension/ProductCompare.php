<?php
/**
 * J2Commerce Product Compare Plugin
 *
 * @subpackage  Extension
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
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
     * J2Store 4 hooks (J2Store::plugin()->eventWithHtml(), prefix onJ2Store;
     * verified against J2Store 4.1.4, app_bootstrap4 layouts):
     *
     *   AfterProductDisplay — fired in tmpl/bootstrap4/view.php after the
     *     product detail block. Args: [$product, $view].
     *
     *   AfterAddToCartButton — fired after the add-to-cart button in
     *     default_cart.php (product list), view_cart.php (product detail) and
     *     cart.php (up-sell and cross-sell products on the detail page).
     *     Args: [$product, $context]; $context ends with the layout name
     *     (e.g. "…default_cart"). The product of the detail page itself is
     *     covered by AfterProductDisplay, so view_cart is skipped. J2Store only
     *     renders these layouts when the cart can be shown (not in catalogue
     *     mode, not for guests when "registered users only" is set).
     *
     *   onAjaxProductcompare — dispatched by com_ajax for
     *     plugin=productcompare&group={installed folder}.
     *
     *   onBeforeCompileHead / onAfterRender — add the assets, script options
     *     and texts (head, rendered after the component and the modules) and
     *     the compare bar and modal to pages on which a compare button was
     *     rendered.
     *
     * Joomla registers a SubscriberInterface plugin only through this list
     * (CMSPlugin::registerListeners() skips the method-name convention), so
     * every handler must be listed here. The plugin belongs to the
     * j2commerce (Joomla 6) or j2store (Joomla 5) group, which Joomla only
     * loads when the shop imports it.
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onJ2CommerceAfterProductListItemDisplay' => 'onJ2CommerceAfterProductListItemDisplay',
            'onJ2CommerceAfterProductDisplay'         => 'onJ2CommerceAfterProductDisplay',
            'onJ2StoreAfterProductDisplay'            => 'onJ2StoreAfterProductDisplay',
            'onJ2StoreAfterAddToCartButton'           => 'onJ2StoreAfterAddToCartButton',
            'onAjaxProductcompare'                    => 'onAjaxProductcompare',
            'onBeforeCompileHead'                     => 'onBeforeCompileHead',
            'onAfterRender'                           => 'onAfterRender',
        ];
    }

    protected $autoloadLanguage = true;

    /**
     * Number of compare buttons rendered in this request. Assets, bar and
     * modal are only added to pages that show at least one button.
     */
    private int $renderedButtons = 0;

    /**
     * Whether the current response is a site HTML page the plugin may extend.
     * Administrator pages, com_ajax requests and non-HTML documents stay untouched.
     */
    private function isSitePage(): bool
    {
        $app = $this->getApplication();

        if (!$app->isClient('site') || $app->getInput()->getCmd('option') === 'com_ajax') {
            return false;
        }

        $doc = $app->getDocument();

        return $doc !== null && $doc->getType() === 'html';
    }

    /**
     * Language strings used by media/js/productcompare.js (Joomla.Text._()).
     */
    private const SCRIPT_TEXTS = [
        'PLG_J2COMMERCE_PRODUCTCOMPARE_DEFAULT_BUTTON_TEXT',
        'PLG_J2COMMERCE_PRODUCTCOMPARE_JS_REMOVE',
        'PLG_J2COMMERCE_PRODUCTCOMPARE_JS_MAX_PRODUCTS',
        'PLG_J2COMMERCE_PRODUCTCOMPARE_ERROR_MIN_PRODUCTS',
        'PLG_J2COMMERCE_PRODUCTCOMPARE_JS_CLEAR_CONFIRM',
        'PLG_J2COMMERCE_PRODUCTCOMPARE_LOADING',
        'PLG_J2COMMERCE_PRODUCTCOMPARE_JS_LOAD_FAILED',
        'PLG_J2COMMERCE_PRODUCTCOMPARE_JS_PRODUCT',
    ];

    /**
     * Register assets with WebAssetManager and pass JS configuration and texts.
     *
     * Runs while the document head is rendered, i.e. after the component and the
     * modules, so buttons rendered by modules are counted as well. Configuration
     * is passed via Joomla's script options (Joomla.getOptions()); the form token
     * lets the script call the com_ajax endpoint.
     */
    public function onBeforeCompileHead(): void
    {
        if ($this->renderedButtons === 0 || !$this->isSitePage()) {
            return;
        }

        $app = $this->getApplication();
        $doc = $app->getDocument();

        $wa = $doc->getWebAssetManager();
        $wa->getRegistry()->addRegistryFile('media/plg_j2commerce_productcompare/joomla.asset.json');
        $wa->useStyle('plg_j2commerce_productcompare.css')
           ->useScript('plg_j2commerce_productcompare');

        // com_ajax resolves plugins by their installed group (folder in #__extensions):
        // j2store on Joomla 5 and j2commerce on Joomla 6.
        $doc->addScriptOptions('plg_j2commerce_productcompare', [
            'maxProducts' => (int) $this->params->get('max_products', 4),
            'ajaxUrl'     => Uri::base() . 'index.php?option=com_ajax&plugin=productcompare&group=' . $this->_type . '&format=json',
            'token'       => Session::getFormToken(),
        ]);

        $this->loadPluginLanguage();

        foreach (self::SCRIPT_TEXTS as $key) {
            Text::script($key);
        }
    }

    /**
     * Load the plugin language for Text::script().
     *
     * autoloadLanguage derives the file name from the installed group, which is
     * j2store on Joomla 5 (plg_j2store_productcompare) and does not match the
     * shipped file plg_j2commerce_productcompare.ini. The files live in the
     * plugin folder, so load them from there (with the administrator language
     * folder as fallback) into the application language, which Text uses.
     */
    private function loadPluginLanguage(): void
    {
        $language = $this->getApplication()->getLanguage();

        if ($language === null) {
            return;
        }

        foreach ([\dirname(__DIR__, 2), JPATH_ADMINISTRATOR] as $path) {
            if ($language->load('plg_j2commerce_productcompare', $path)) {
                return;
            }
        }
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
        if ($this->renderedButtons === 0 || !$this->isSitePage()) {
            return;
        }

        $app  = $this->getApplication();
        $body = (string) $app->getBody();
        $pos  = strripos($body, '</body>');

        if ($pos === false) {
            return;
        }

        $html = $this->renderLayout('bar', []) . "\n" . $this->renderLayout('modal', []);

        $app->setBody(substr($body, 0, $pos) . $html . "\n" . substr($body, $pos));
    }

    /**
     * J2Store 4 — compare button on the product detail page.
     *
     * Fired by app_bootstrap4/tmpl/bootstrap4/view.php via
     *   J2Store::plugin()->eventWithHtml('AfterProductDisplay', [$product, $view])
     */
    public function onJ2StoreAfterProductDisplay(Event $event): void
    {
        if (!$this->params->get('show_in_detail', 1) || $this->isJ2Commerce6()) {
            return;
        }

        $product = $this->firstJ2StoreProduct($event);

        if ($product !== null) {
            $this->appendResult($event, $this->renderCompareButton((int) $product->j2store_product_id));
        }
    }

    /**
     * J2Store 4 — compare button in the product list, after the add-to-cart button.
     *
     * Fired via
     *   J2Store::plugin()->eventWithHtml('AfterAddToCartButton', [$product, $context])
     * in default_cart.php (list), view_cart.php (detail) and cart.php. The detail
     * page gets its button from onJ2StoreAfterProductDisplay(), so view_cart is
     * skipped to avoid a second button.
     */
    public function onJ2StoreAfterAddToCartButton(Event $event): void
    {
        if (!$this->params->get('show_in_list', 1) || $this->isJ2Commerce6()) {
            return;
        }

        $args    = array_values($event->getArguments());
        $context = isset($args[1]) && \is_string($args[1]) ? $args[1] : '';

        if (str_ends_with($context, 'view_cart')) {
            return;
        }

        $product = $this->firstJ2StoreProduct($event);

        if ($product !== null) {
            $this->appendResult($event, $this->renderCompareButton((int) $product->j2store_product_id));
        }
    }

    /**
     * First event argument that is a J2Store product.
     */
    private function firstJ2StoreProduct(Event $event): ?object
    {
        foreach ($event->getArguments() as $arg) {
            if (\is_object($arg) && !empty($arg->j2store_product_id)) {
                return $arg;
            }
        }

        return null;
    }

    /**
     * Add HTML to the event results.
     *
     * J2Commerce 6 dispatches result-aware events (addResult()). J2Store 4 uses
     * CMSApplication::triggerEvent(), which creates a generic event and returns its
     * "result" argument, so the result is appended to that argument there.
     */
    private function appendResult(Event $event, string $html): void
    {
        if ($html === '') {
            return;
        }

        if (method_exists($event, 'addResult')) {
            $event->addResult($html);

            return;
        }

        $results   = (array) $event->getArgument('result', []);
        $results[] = $html;
        $event->setArgument('result', $results);
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

        $this->appendResult($event, $this->renderCompareButton((int) $product->j2commerce_product_id));
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

        $this->appendResult($event, $this->renderCompareButton((int) $product->j2commerce_product_id));
    }

    /**
     * AJAX endpoint — returns comparison table HTML for the requested product IDs.
     *
     * Called via com_ajax:
     *   index.php?option=com_ajax&plugin=productcompare&group={j2store|j2commerce}&format=json
     *
     * The group is the installed plugin folder; which shop tables are read is
     * decided by getActiveShop(), not by the group.
     */
    public function onAjaxProductcompare(): void
    {
        $app = $this->getApplication();

        if (!$this->hasValidToken()) {
            echo new JsonResponse(null, Text::_('JINVALID_TOKEN'), true);
            $app->close();
        }

        try {
            $productIds = array_map('intval', (array) $app->getInput()->get('products', [], 'array'));
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
     * Whether the request carries the form token of the current session (POST
     * field, query parameter or X-CSRF-Token header).
     *
     * Session::checkToken() is not used: for a new session it redirects to the
     * home page instead of returning false, which would give the script an
     * empty redirect response instead of the JSON error.
     */
    private function hasValidToken(): bool
    {
        $input = $this->getApplication()->getInput();
        $token = Session::getFormToken();

        return hash_equals($token, (string) $input->server->get('HTTP_X_CSRF_TOKEN', '', 'alnum'))
            || $input->post->get($token, '', 'alnum') !== ''
            || $input->get->get($token, '', 'alnum') !== '';
    }

    /**
     * Render the compare button layout.
     */
    protected function renderCompareButton(int $productId): string
    {
        $this->renderedButtons++;

        return $this->renderLayout('button', [
            'productId'   => $productId,
            'buttonText'  => Text::_($this->params->get('button_text', 'PLG_J2COMMERCE_PRODUCTCOMPARE_DEFAULT_BUTTON_TEXT')),
            'buttonClass' => $this->params->get('button_class', 'btn btn-secondary'),
        ]);
    }

    /**
     * Create a fresh query object — compatible with Joomla 4/5 (getQuery) and 6 (createQuery).
     */
    private function createDbQuery(\Joomla\Database\DatabaseInterface $db): \Joomla\Database\QueryInterface
    {
        return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
    }

    /**
     * Cached result of isJ2Commerce6().
     */
    private ?bool $j2commerce6 = null;

    /**
     * Cached result of getActiveShop(); only valid once $activeShopResolved is true.
     */
    private ?string $activeShop = null;

    private bool $activeShopResolved = false;

    /**
     * Whether product data and events belong to J2Commerce 6.
     *
     * Decided by getActiveShop(). When no shop component is active, the previous
     * table-only check is kept as fallback (#__j2commerce_products present →
     * J2Commerce 6): without an active component nobody renders compare buttons,
     * so this only matters for direct calls, which then behave as before.
     */
    private function isJ2Commerce6(): bool
    {
        if ($this->j2commerce6 === null) {
            $shop = $this->getActiveShop();

            $this->j2commerce6 = $shop === 'j2commerce'
                || ($shop === null && $this->tableExists('j2commerce_products'));
        }

        return $this->j2commerce6;
    }

    /**
     * Determine the active shop component.
     *
     * Tables alone do not decide: after a migration J2Store → J2Commerce 6 both
     * table sets exist, and a J2Store site can still carry j2commerce tables from
     * an aborted installation. The enabled component decides:
     *   1. com_j2commerce enabled and #__j2commerce_products present → 'j2commerce'
     *   2. com_j2store enabled and #__j2store_products present       → 'j2store'
     *   3. otherwise                                                  → null
     *
     * Cached per plugin instance (one instance per request).
     *
     * @return  string|null  'j2commerce', 'j2store' or null
     */
    private function getActiveShop(): ?string
    {
        if ($this->activeShopResolved) {
            return $this->activeShop;
        }

        $this->activeShopResolved = true;

        $db    = $this->getDatabase();
        $query = $this->createDbQuery($db)
            ->select($db->quoteName('element'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
            ->where($db->quoteName('enabled') . ' = 1')
            ->whereIn($db->quoteName('element'), ['com_j2commerce', 'com_j2store'], ParameterType::STRING);

        $enabled = $db->setQuery($query)->loadColumn() ?: [];

        if (\in_array('com_j2commerce', $enabled, true) && $this->tableExists('j2commerce_products')) {
            $this->activeShop = 'j2commerce';
        } elseif (\in_array('com_j2store', $enabled, true) && $this->tableExists('j2store_products')) {
            $this->activeShop = 'j2store';
        }

        return $this->activeShop;
    }

    /**
     * Check whether a table exists (name without prefix).
     * Uses SHOW TABLES LIKE to avoid the stale getTableList() cache (e.g. during install).
     */
    private function tableExists(string $table): bool
    {
        $db   = $this->getDatabase();
        $like = $db->quote($db->escape($db->getPrefix() . $table, true), false);

        return !empty($db->setQuery('SHOW TABLES LIKE ' . $like)->loadResult());
    }

    /**
     * Load product data for the given IDs from the active shop's tables.
     *
     * @param   int[]  $productIds
     * @return  object[]
     */
    private function getProductsData(array $productIds): array
    {
        $db  = $this->getDatabase();
        $j6  = $this->isJ2Commerce6();

        $productsPk  = $j6 ? 'j2commerce_product_id' : 'j2store_product_id';
        $variantsPk  = $j6 ? 'j2commerce_variant_id' : 'j2store_variant_id';
        $productsT   = $j6 ? '#__j2commerce_products' : '#__j2store_products';
        $variantsT   = $j6 ? '#__j2commerce_variants'  : '#__j2store_variants';
        $quantitiesT  = $j6 ? '#__j2commerce_productquantities' : '#__j2store_productquantities';

        $query = $this->createDbQuery($db)
            ->select([
                $db->quoteName('p') . '.' . $db->quoteName($productsPk),
                $db->quoteName('p') . '.' . $db->quoteName('product_source_id'),
                $db->quoteName('v') . '.' . $db->quoteName($variantsPk),
                $db->quoteName('v') . '.' . $db->quoteName('sku'),
                $db->quoteName('v') . '.' . $db->quoteName('price'),
                $db->quoteName('v') . '.' . $db->quoteName('availability'),
                'COALESCE(' . $db->quoteName('pq.quantity') . ', 0) AS ' . $db->quoteName('stock'),
                $db->quoteName('c') . '.' . $db->quoteName('title'),
                $db->quoteName('c') . '.' . $db->quoteName('introtext'),
            ])
            ->from($db->quoteName($productsT, 'p'))
            ->join('LEFT', $db->quoteName($variantsT, 'v')
                . ' ON ' . $db->quoteName('v') . '.' . $db->quoteName('product_id')
                . ' = ' . $db->quoteName('p') . '.' . $db->quoteName($productsPk))
            ->join('LEFT', $db->quoteName($quantitiesT, 'pq')
                . ' ON ' . $db->quoteName('pq') . '.' . $db->quoteName('variant_id')
                . ' = ' . $db->quoteName('v') . '.' . $db->quoteName($variantsPk))
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

<?php
/**
 * @package     OSMap J2Commerce Plugin
 * @copyright   Copyright (C) 2026 Advans IT Solutions GmbH
 * @license     GNU GPL v3
 */

namespace Advans\Plugin\Osmap\J2Commerce\Extension;

defined('_JEXEC') or die;

use Alledia\OSMap\Sitemap\Collector;
use Alledia\OSMap\Sitemap\Item;
use Joomla\CMS\Access\Access;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Event\SubscriberInterface;
use Joomla\Registry\Registry;

/**
 * OSMap plugin for J2Store and J2Commerce.
 *
 * OSMap calls getComponentElement() to match this plugin against menu items,
 * then calls getTree() for each matching menu item to collect sitemap nodes.
 *
 * Two sitemap mechanisms are supported:
 *
 * Supported menu item views:
 *   - view=products      product list (optional catid filter, incl. subtree)
 *   - view=product       single product by id
 *   - view=categories    products in the selected root category (id) and its
 *                        subtree; all products if the menu item has no category
 *   - view=categoryalias J2Commerce single-category alias (redirects to
 *                        view=products at runtime; treated identically here)
 *
 * Two URL mechanisms are combined for list views (both run, results are
 * de-duplicated by product id so a product covered by both appears once):
 *
 * 1. published=-2 hidden children: installations that manually create hidden
 *    com_content menu items (published=-2) per product carry the correct SEF
 *    path in the menu item's path field. These are used directly as sitemap
 *    URLs when present.
 *
 * 2. Direct product queries: products are loaded from #__content joined with
 *    the products table. Works on any standard J2Store or J2Commerce
 *    installation, and catches products that have no hidden menu child.
 *
 * Both mechanisms now build identical URL formats (Uri::root() + menu path +
 * language SEF prefix), so running both only adds completeness — a single
 * leftover published=-2 item no longer suppresses the full product run.
 *
 * Supported components: com_j2store (J2Store) and com_j2commerce (J2Commerce).
 * The plugin registers itself for com_j2store by default. A second subclass
 * (J2CommerceNew) handles com_j2commerce and uses the j2commerce_products table.
 *
 * OSMap discovers plugins by calling getComponentElement() and getTree() —
 * no methods from Alledia\OSMap\Plugin\Base are used. Extending CMSPlugin
 * directly avoids a hard dependency on OSMap's internal class hierarchy,
 * which is not available during Joomla's plugin update/install process.
 */
class J2Commerce extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;

    protected $autoloadLanguage = true;

    /**
     * Create a database query object (Joomla 4/5/6 compatible).
     * Joomla 6 deprecates getQuery(true) in favour of createQuery().
     *
     * @return  \Joomla\Database\QueryInterface
     */
    private function createDbQuery(): \Joomla\Database\QueryInterface
    {
        $db = $this->getDb();
        return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
    }

    /**
     * The Joomla component option this instance handles.
     * Subclass J2CommerceNew overrides this to 'com_j2commerce'.
     */
    protected string $component = 'com_j2store';

    /**
     * Products table — #__j2store_products for J2Store, #__j2commerce_products for J2Commerce.
     */
    protected string $productsTable = '#__j2store_products';

    /**
     * Product ids already emitted during the current getTree() call. Both URL
     * mechanisms run for list views; this prevents a product that is covered by
     * a published=-2 hidden child AND the direct query from appearing twice.
     *
     * @var array<int, true>
     */
    private array $emittedProductIds = [];

    /**
     * Memoised language SEF prefixes, keyed by lang_code, so a large product
     * run does not issue one #__languages query per emitted node.
     *
     * @var array<string, string>
     */
    private array $languageSefCache = [];

    public static function getSubscribedEvents(): array
    {
        return [];
    }

    public function getComponentElement(): string
    {
        return $this->component;
    }

    /**
     * Returns the database driver. Falls back to the DI container when OSMap
     * loads the plugin via its legacy loader, which does not call setDatabase().
     */
    protected function getDb(): DatabaseInterface
    {
        try {
            return $this->getDatabase();
        } catch (\RuntimeException $e) {
            return Factory::getContainer()->get(DatabaseInterface::class);
        }
    }

    /**
     * Resolves the language SEF prefix (e.g. 'de/') for a menu item language.
     *
     * Returns '' for the "all languages" wildcard ('*'), an empty/absent
     * language, or when no published #__languages row matches — so single
     * language sites (menu items carry language '*') get no prefix, while a
     * multilingual site (menu items carry 'de-DE' etc.) gets the '/de/' prefix
     * its SEF URLs actually resolve under.
     */
    private function getLanguageSef(?string $language): string
    {
        if ($language === null || $language === '' || $language === '*') {
            return '';
        }

        if (isset($this->languageSefCache[$language])) {
            return $this->languageSefCache[$language];
        }

        $db    = $this->getDb();
        $query = $this->createDbQuery()
            ->select($db->quoteName('sef'))
            ->from($db->quoteName('#__languages'))
            ->where($db->quoteName('lang_code') . ' = :lang')
            ->where($db->quoteName('published') . ' = 1')
            ->bind(':lang', $language)
            ->setLimit(1);

        try {
            $sef = $db->setQuery($query)->loadResult();
        } catch (\Throwable $e) {
            $this->logQueryError($e);

            // Cache the empty result so a persistent query failure logs once and
            // is not retried for every emitted product on a large sitemap.
            return $this->languageSefCache[$language] = '';
        }

        return $this->languageSefCache[$language] = ($sef ? $sef . '/' : '');
    }

    /**
     * View access levels a guest (user id 0) is authorised to see. Used to keep
     * access-restricted products out of the public sitemap.
     *
     * @return int[]
     */
    private function guestViewLevels(): array
    {
        try {
            $levels = array_map('intval', Access::getAuthorisedViewLevels(0));
        } catch (\Throwable $e) {
            // Access needs a booted application/session; when that is unavailable
            // fall back to the Public view level (1). This is the conservative
            // default — it never leaks access-restricted products into the sitemap.
            return [1];
        }

        return $levels ?: [1];
    }

    /**
     * Logs a real query failure. OSMap swallows every exception thrown by a
     * plugin (General.php catch (\Exception) — ignored), so without this a
     * failing query produces a silent, shop-less sitemap with no trace.
     */
    private function logQueryError(\Throwable $e): void
    {
        Log::add('plg_osmap_j2commerce: ' . $e->getMessage(), Log::ERROR, 'plg_osmap_j2commerce');
    }

    /**
     * Runs a query and returns its object list, logging genuine failures instead
     * of letting OSMap swallow them silently.
     *
     * @return object[]
     */
    private function safeLoadObjectList(\Joomla\Database\QueryInterface $query): array
    {
        $db = $this->getDb();

        try {
            return $db->setQuery($query)->loadObjectList() ?: [];
        } catch (\Throwable $e) {
            $this->logQueryError($e);

            return [];
        }
    }

    /**
     * Called by OSMap for each menu item whose option matches getComponentElement().
     *
     * For list views (products, categories, categoryalias) both URL mechanisms
     * run and their output is de-duplicated by product id. For view=product the
     * single product is emitted directly.
     */
    public function getTree(Collector $collector, Item $parent, Registry $params): void
    {
        $this->emittedProductIds = [];

        parse_str(parse_url($parent->link ?? '', PHP_URL_QUERY) ?? '', $query);

        $view     = $query['view'] ?? '';
        $catid    = isset($query['catid']) ? (int) $query['catid'] : null;
        $id       = isset($query['id'])    ? (int) $query['id']    : null;
        $parentId = (int) ($parent->id ?? 0);

        switch ($view) {
            case 'product':
                if ($id) {
                    $this->emitSingleProduct($collector, $parent, $params, $id);
                }
                return;

            case 'categoryalias':
                // J2Commerce: menu item pointing to a single category by alias.
                // Redirects to view=products with catid=id at runtime; treat the
                // same way here — emit products for that category (subtree).
                $catid = $id;
                // fall through

            case 'products':
            case 'categories':
                // Run BOTH mechanisms and de-duplicate by product id:
                //   1. published=-2 hidden children (site-specific SEF resolvers)
                //   2. direct product query (every enabled product)
                // A single leftover hidden child must not suppress the full run.
                //
                // view=categories carries the chosen root category in `id`;
                // view=products/categoryalias carry it in `catid`. Both restrict
                // to that category's subtree; null means every category. The root
                // category is resolved first so BOTH mechanisms honour the filter.
                $rootCat = ($view === 'categories') ? $id : $catid;
                $rootCat = ($rootCat !== null && $rootCat > 0) ? $rootCat : null;

                if ($parentId > 0) {
                    $this->emitHiddenMenuChildren($collector, $parent, $params, $parentId, $rootCat);
                }

                if ($rootCat !== null) {
                    $this->emitProductsForCategory($collector, $parent, $params, $rootCat);
                } else {
                    $this->emitAllProducts($collector, $parent, $params);
                }
                return;

            default:
                // Unknown view (wishlist, myprofile, checkout, etc.) — emit nothing.
                // Only try hidden children for menu items with no view parameter at all.
                if (empty($view) && $parentId > 0) {
                    $this->emitHiddenMenuChildren($collector, $parent, $params, $parentId);
                }
                return;
        }
    }

    // -------------------------------------------------------------------------
    // Mechanism 1: J2Store published=-2 hidden menu children
    // -------------------------------------------------------------------------

    /**
     * Queries #__menu for published=-2 children of $parentId, joins #__content
     * and the products table, filters to publicly visible/published/enabled
     * products, then emits one sitemap node per product using the menu item's
     * SEF path as the URL.
     *
     * Returns true if at least one node was emitted, false otherwise.
     */
    protected function emitHiddenMenuChildren(
        Collector $collector,
        Item $parent,
        Registry $params,
        int $parentId,
        ?int $rootCat = null
    ): bool {
        $db    = $this->getDb();
        $query = $this->createDbQuery()
            ->select([
                $db->quoteName('a.id', 'article_id'),
                $db->quoteName('m.id'),
                $db->quoteName('m.path'),
                $db->quoteName('m.language'),
                $db->quoteName('m.browserNav'),
                $db->quoteName('a.modified'),
                $db->quoteName('a.title'),
            ])
            ->from($db->quoteName('#__menu', 'm'))
            ->join(
                'INNER',
                $db->quoteName('#__content', 'a')
                . ' ON ((m.link LIKE CONCAT(' . $db->quote('%&id=') . ', a.id, ' . $db->quote('&%') . ')'
                . '   OR m.link LIKE CONCAT(' . $db->quote('%&id=') . ', a.id))'
                . '  AND m.link LIKE ' . $db->quote('%com_content%view=article%') . ')'
            )
            ->join(
                'INNER',
                $db->quoteName($this->productsTable, 'p')
                . ' ON ' . $db->quoteName('p.product_source_id') . ' = ' . $db->quoteName('a.id')
                . ' AND ' . $db->quoteName('p.product_source') . ' = ' . $db->quote('com_content')
                . ' AND ' . $db->quoteName('p.enabled') . ' = 1'
                . ' AND ' . $db->quoteName('p.visibility') . ' = 1'
            )
            ->where($db->quoteName('m.published') . ' = -2')
            ->where($db->quoteName('m.parent_id') . ' = :parentId')
            ->where($db->quoteName('m.client_id') . ' = 0')
            ->where($db->quoteName('a.state') . ' = 1')
            ->whereIn($db->quoteName('a.access'), $this->guestViewLevels())
            ->bind(':parentId', $parentId, ParameterType::INTEGER)
            ->order($db->quoteName('a.title') . ' ASC');

        // For a category-filtered menu item keep only hidden children whose
        // article lives in that category subtree, so products from sibling
        // categories are not pulled in via this mechanism.
        $this->applyCategorySubtreeFilter($query, $rootCat);

        $items = $this->safeLoadObjectList($query);

        foreach ($items as $item) {
            $this->printMenuPathNode($collector, $parent, $params, $item);
        }

        return count($items) > 0;
    }

    // -------------------------------------------------------------------------
    // Mechanism 2: J2Commerce 4+ view-based queries
    // -------------------------------------------------------------------------

    protected function emitSingleProduct(
        Collector $collector,
        Item $parent,
        Registry $params,
        int $articleId
    ): void {
        $db    = $this->getDb();
        $query = $this->createDbQuery()
            ->select([
                $db->quoteName('a.id'),
                $db->quoteName('a.title'),
                $db->quoteName('a.alias'),
                $db->quoteName('a.modified'),
                $db->quoteName('a.catid'),
            ])
            ->from($db->quoteName('#__content', 'a'))
            ->join(
                'INNER',
                $db->quoteName($this->productsTable, 'p')
                . ' ON ' . $db->quoteName('p.product_source_id') . ' = ' . $db->quoteName('a.id')
                . ' AND ' . $db->quoteName('p.product_source') . ' = ' . $db->quote('com_content')
                . ' AND ' . $db->quoteName('p.enabled') . ' = 1'
                . ' AND ' . $db->quoteName('p.visibility') . ' = 1'
            )
            ->where($db->quoteName('a.id') . ' = :id')
            ->where($db->quoteName('a.state') . ' = 1')
            ->whereIn($db->quoteName('a.access'), $this->guestViewLevels())
            ->bind(':id', $articleId, ParameterType::INTEGER);

        try {
            $product = $db->setQuery($query)->loadObject();
        } catch (\Throwable $e) {
            // A missing products table is the normal state on the other stack
            // (e.g. a J2Store site has no #__j2commerce_products), so skip it
            // silently. Every other failure (malformed query, DB outage) is a
            // real problem and must be logged (#177), not swallowed.
            if (!$this->isMissingTableError($e)) {
                $this->logQueryError($e);
            }

            return;
        }

        if ($product) {
            $this->printProductNode($collector, $parent, $params, $product);
        }
    }

    /**
     * Detects the "products table does not exist" case (component not installed
     * on this stack) so it can be skipped silently, while genuine query errors
     * are still logged.
     */
    private function isMissingTableError(\Throwable $e): bool
    {
        $message = $e->getMessage();

        // Only a missing *products* table is the expected "component not
        // installed on this stack" case. Any other missing relation (e.g.
        // #__content, #__categories) is a genuine fault and must fall through
        // to logging (#177), so require the message to name the products table.
        // The driver reports the real, prefixed name (e.g. `jos_j2store_products`),
        // so match on the unprefixed token.
        $token = str_replace('#__', '', $this->productsTable);

        if ($token === '' || stripos($message, $token) === false) {
            return false;
        }

        // MySQL error 1146 (SQLSTATE 42S02) = base table or view not found.
        $needles = ['1146', '42S02', "doesn't exist", 'does not exist', 'Base table or view not found'];

        foreach ($needles as $needle) {
            if (stripos($message, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    protected function emitProductsForCategory(
        Collector $collector,
        Item $parent,
        Registry $params,
        ?int $catid
    ): void {
        foreach ($this->loadProducts($catid) as $product) {
            $this->printProductNode($collector, $parent, $params, $product);
        }
    }

    protected function emitAllProducts(
        Collector $collector,
        Item $parent,
        Registry $params
    ): void {
        foreach ($this->loadProducts(null) as $product) {
            $this->printProductNode($collector, $parent, $params, $product);
        }
    }

    // -------------------------------------------------------------------------
    // Data loading
    // -------------------------------------------------------------------------

    /**
     * Loads publicly visible, published, enabled products. When $catid is given
     * the result covers that category AND its descendants (via the #__categories
     * nested-set lft/rgt bounds), so a menu item pointing at a parent category
     * still lists products that live in its sub-categories.
     *
     * @return object[]
     */
    protected function loadProducts(?int $catid): array
    {
        $db    = $this->getDb();
        $query = $this->createDbQuery()
            ->select([
                $db->quoteName('a.id'),
                $db->quoteName('a.title'),
                $db->quoteName('a.alias'),
                $db->quoteName('a.modified'),
                $db->quoteName('a.catid'),
            ])
            ->from($db->quoteName('#__content', 'a'))
            ->join(
                'INNER',
                $db->quoteName($this->productsTable, 'p')
                . ' ON ' . $db->quoteName('p.product_source_id') . ' = ' . $db->quoteName('a.id')
                . ' AND ' . $db->quoteName('p.product_source') . ' = ' . $db->quote('com_content')
                . ' AND ' . $db->quoteName('p.enabled') . ' = 1'
                . ' AND ' . $db->quoteName('p.visibility') . ' = 1'
            )
            ->where($db->quoteName('a.state') . ' = 1')
            ->whereIn($db->quoteName('a.access'), $this->guestViewLevels())
            ->order($db->quoteName('a.title') . ' ASC');

        $this->applyCategorySubtreeFilter($query, $catid);

        return $this->safeLoadObjectList($query);
    }

    /**
     * Restricts a query (whose #__content alias is `a`) to products living in
     * $catid or any of its descendants, using the #__categories nested-set
     * lft/rgt bounds. A null $catid leaves the query unrestricted (whole shop).
     *
     * The :catid placeholder is bound on the outer query on purpose: casting the
     * subquery to string drops any parameters bound on the subquery object.
     */
    private function applyCategorySubtreeFilter(\Joomla\Database\QueryInterface $query, ?int $catid): void
    {
        if ($catid === null) {
            return;
        }

        $db  = $this->getDb();
        $sub = $this->createDbQuery()
            ->select($db->quoteName('c2.id'))
            ->from($db->quoteName('#__categories', 'c1'))
            ->join(
                'INNER',
                $db->quoteName('#__categories', 'c2')
                . ' ON ' . $db->quoteName('c2.lft') . ' >= ' . $db->quoteName('c1.lft')
                . ' AND ' . $db->quoteName('c2.rgt') . ' <= ' . $db->quoteName('c1.rgt')
                . ' AND ' . $db->quoteName('c2.extension') . ' = ' . $db->quote('com_content')
            )
            ->where($db->quoteName('c1.id') . ' = :catid');

        $query->where($db->quoteName('a.catid') . ' IN (' . (string) $sub . ')')
              ->bind(':catid', $catid, ParameterType::INTEGER);
    }

    // -------------------------------------------------------------------------
    // Node output
    // -------------------------------------------------------------------------

    /**
     * Emits a sitemap node for a published=-2 hidden menu item.
     *
     * OSMap excludes published=-2 items from its routing cache, so passing
     * 'index.php?Itemid=<id>' produces an empty fullLink and the node is
     * suppressed. Instead, use the menu item's path field directly — it
     * already contains the correct SEF-relative path (e.g. 'shop/my-product')
     * and is always present for published=-2 items created by J2Store.
     * This is the same approach used by printProductNode().
     *
     * The language SEF prefix comes from the child menu item's own language, so
     * a multilingual site produces '/de/shop/my-product' rather than a bare
     * '/shop/my-product' that only resolves via a 301 redirect.
     */
    protected function printMenuPathNode(
        Collector $collector,
        Item $parent,
        Registry $params,
        object $item
    ): void {
        if (empty($item->id)) {
            return;
        }

        // De-duplicate against the direct product query (both mechanisms run).
        $articleId = (int) ($item->article_id ?? 0);
        if ($articleId > 0) {
            if (isset($this->emittedProductIds[$articleId])) {
                return;
            }
            $this->emittedProductIds[$articleId] = true;
        }

        // Build absolute URL from the menu item's SEF path, bypassing OSMap's
        // router (which skips published=-2 items). The prefix is taken from the
        // hidden child's own language (its SEF path carries no language segment).
        $prefix = $this->getLanguageSef($item->language ?? '');
        $link   = rtrim(Uri::root(), '/') . '/' . $prefix . ltrim($item->path, '/');

        $node = (object) [
            'id'         => $item->id,
            'name'       => $item->title,
            'uid'        => 'j2commerce.product.' . ($articleId > 0 ? $articleId : $item->id),
            'modified'   => $item->modified,
            'browserNav' => $item->browserNav ?? $parent->browserNav,
            'priority'   => $params->get('priority', '0.8'),
            'changefreq' => $params->get('changefreq', 'weekly'),
            'link'       => $link,
            'expandible' => false,
        ];

        $collector->printNode($node);
    }

    /**
     * Builds a product URL from the parent menu item's SEF path + product alias
     * and emits the node. This avoids router dependency — no view=product menu
     * item is required.
     */
    protected function printProductNode(
        Collector $collector,
        Item $parent,
        Registry $params,
        object $product
    ): void {
        // De-duplicate against the hidden-children mechanism (both run).
        $articleId = (int) ($product->id ?? 0);
        if ($articleId > 0) {
            if (isset($this->emittedProductIds[$articleId])) {
                return;
            }
            $this->emittedProductIds[$articleId] = true;
        }

        // Derive the product URL from the parent menu item's SEF path + alias.
        // e.g. parent path "shop" + alias "my-product" → "https://example.com/de/shop/my-product"
        // The language SEF prefix comes from the parent menu item's language, so
        // products on a multilingual site resolve directly (HTTP 200) instead of
        // via a 301 redirect from the prefixless path.
        // Joomla aliases are guaranteed URL-safe by JFilterOutput::stringURLSafe() — no
        // percent-encoding needed. rawurlencode() would produce %XX sequences that Joomla's
        // SEF router does not expect and cannot resolve.
        $prefix   = $this->getLanguageSef($parent->language ?? '');
        $basePath = rtrim($parent->path ?? '', '/');
        $link     = rtrim(Uri::root(), '/') . '/' . $prefix
                  . ($basePath ? $basePath . '/' : '') . ltrim($product->alias, '/');

        $node = (object) [
            'id'         => $product->id,
            'name'       => $product->title,
            'uid'        => 'j2commerce.product.' . $product->id,
            'modified'   => $product->modified,
            'browserNav' => $parent->browserNav,
            'priority'   => $params->get('priority', '0.8'),
            'changefreq' => $params->get('changefreq', 'weekly'),
            'link'       => $link,
            'expandible' => false,
        ];

        $collector->printNode($node);
    }
}

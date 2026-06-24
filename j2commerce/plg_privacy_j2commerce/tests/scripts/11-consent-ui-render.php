<?php
/**
 * Test 11: Consent-UI Template Override RENDERING
 *
 * Closes the one remaining real-coverage gap for the template overrides.
 * Test 08 only proves the override FILES are deployed and non-empty. This test
 * actually RENDERS the bundled override templates for the active stack with a
 * realistic data context and asserts the produced HTML really contains:
 *   - the checkout GDPR consent checkbox (expected input id/name), and
 *   - the MyProfile "Privacy" tab markup (expected tab id + shield icon).
 *
 * It renders the REAL override shipped inside the installed plugin and reads
 * the REAL, installed-and-enabled privacy plugin params via PluginHelper, so
 * the consent conditionals (show_consent_checkbox / consent_required) are
 * exercised against the real extension configuration — not a hand-rolled stub.
 *
 * Only the surrounding J2Store/J2Commerce view + helper objects are doubled
 * (the override's data context), exactly as Joomla would supply them at render
 * time. This is the "include the override template with a realistic data
 * context" approach permitted by the harness, and is consistent with how the
 * other tests/scripts/*.php boot Joomla via includes/framework.php.
 *
 * Stack-aware: J2Store 4/5 renders com_j2store overrides; J2Commerce 6 renders
 * com_j2commerce overrides.
 */
define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Registry\Registry;

/* ───────────────────────── Render data-context doubles ─────────────────────
 * These stand in for the J2Store/J2Commerce view + helpers that own the
 * override at render time. They only provide the structural plumbing the
 * override calls; the privacy markup itself comes entirely from the real
 * override file under test.
 */

// Web asset manager + document + application (consent_required path registers a script).
class RenderHarnessWam
{
    public function registerAndUseScript($name, $url = '', $deps = [], $attribs = [], $opts = [])
    {
        return $this;
    }
}
class RenderHarnessMenu
{
    public function getActive()
    {
        return null;
    }
}
class RenderHarnessApp
{
    public function getDocument()
    {
        return new class {
            public function getWebAssetManager()
            {
                return new RenderHarnessWam();
            }
        };
    }
    public function getMenu()
    {
        return new RenderHarnessMenu();
    }
    public function getIdentity()
    {
        return null;
    }
}

// Result object returned by eventWithHtml(): echoable (J2Store) AND has getArgument() (J2Commerce 6).
class RenderHarnessEventResult
{
    public function getArgument($name, $default = null)
    {
        return $default;
    }
    public function __toString()
    {
        return '';
    }
}
class RenderHarnessPlugin
{
    public function eventWithHtml($event, $args = [])
    {
        return new RenderHarnessEventResult();
    }
}
class RenderHarnessModules
{
    public function loadposition($position)
    {
        return '';
    }
}
class RenderHarnessPlatform
{
    public function loadExtra($name)
    {
    }
    public function application()
    {
        return new RenderHarnessApp();
    }
    public function getRegistry($data = '{}')
    {
        return new Registry($data);
    }
    public function getMyprofileUrl($a = [], $b = false, $c = false)
    {
        return '';
    }
}
class RenderHarnessCurrency
{
    public function format($value)
    {
        return (string) $value;
    }
}

// Global J2Store helper used by the com_j2store (J2Store 4/5) overrides.
if (!class_exists('J2Store', false)) {
    class J2Store
    {
        public static function plugin()
        {
            return new RenderHarnessPlugin();
        }
        public static function platform()
        {
            return new RenderHarnessPlatform();
        }
        public static function config()
        {
            return new Registry(['bootstrap_version' => 5, 'download_area' => 1]);
        }
        public static function modules()
        {
            return new RenderHarnessModules();
        }
    }
}

// Namespaced J2Commerce 6 helper used by the com_j2commerce overrides.
// Declared as a normal global-namespace class and aliased to the expected
// namespaced name (avoids eval(), which security hardening often blocks in CI).
class RenderHarnessJ2CommerceHelper
{
    public static function currency()
    {
        return new RenderHarnessCurrency();
    }
    public static function plugin()
    {
        return new RenderHarnessPlugin();
    }
}
if (!class_exists('J2Commerce\\Component\\J2commerce\\Administrator\\Helper\\J2CommerceHelper', false)) {
    class_alias(
        'RenderHarnessJ2CommerceHelper',
        'J2Commerce\\Component\\J2commerce\\Administrator\\Helper\\J2CommerceHelper'
    );
}

// View double: supplies the override's $this (escape/loadTemplate + a few props).
class RenderHarnessView
{
    public $order;
    public $orders = [];
    public $user;
    public $params;

    public function escape($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }

    public function loadTemplate($tpl = '')
    {
        return '<!-- loadTemplate:' . $tpl . ' -->';
    }

    public function __get($name)
    {
        return null;
    }
}

class ConsentUiRenderTest
{
    private $db;
    private int $passed = 0;
    private int $failed = 0;

    public function __construct()
    {
        $this->db = Factory::getContainer()->get('DatabaseDriver');
    }

    private function test(string $name, bool $condition, string $message = ''): bool
    {
        if ($condition) {
            echo "✓ $name... PASS\n";
            $this->passed++;
            return true;
        }
        echo "✗ $name... FAIL" . ($message ? " - $message" : '') . "\n";
        $this->failed++;
        return false;
    }

    /** Detect J2Commerce 6: env hint first, then real table probe (matches sibling tests). */
    private function isJ6(): bool
    {
        if (getenv('J2COMMERCE_STACK') === 'j6') {
            return true;
        }
        $tables = $this->db->getTableList();
        return in_array($this->db->getPrefix() . 'j2commerce_products', $tables, true);
    }

    /** Make Factory::getApplication() and PluginHelper::getPlugin() usable in this CLI render. */
    private function primeJoomlaState(): void
    {
        // Provide an application with a working WebAssetManager for the consent_required path.
        try {
            $appProp = (new \ReflectionClass(Factory::class))->getProperty('application');
            $appProp->setAccessible(true);
            if (!$appProp->getValue()) {
                $appProp->setValue(null, new RenderHarnessApp());
            }
            $this->test('Factory application primed via reflection', true);
        } catch (\ReflectionException $e) {
            $this->test('Factory application primed via reflection', false,
                'Reflection on Factory::application failed: ' . $e->getMessage());
        } catch (\Throwable $e) {
            $this->test('Factory application primed via reflection', false,
                'Unexpected error priming Factory::application: ' . $e->getMessage());
        }

        // Seed PluginHelper's cache with the REAL installed privacy plugin row so the
        // override reads real, installed params without depending on session/user state.
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('params'))
            ->from($this->db->quoteName('#__extensions'))
            ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'))
            ->where($this->db->quoteName('folder') . ' = ' . $this->db->quote('privacy'))
            ->where($this->db->quoteName('element') . ' = ' . $this->db->quote('j2commerce'));
        $this->db->setQuery($query);
        $params = $this->db->loadResult();

        $this->test('Privacy plugin row found in #__extensions', $params !== null,
            'No privacy/j2commerce plugin row');

        $pluginObj         = new \stdClass();
        $pluginObj->type   = 'privacy';
        $pluginObj->name   = 'j2commerce';
        $pluginObj->params = $params ?? '{}';

        try {
            $pluginsProp = (new \ReflectionClass(PluginHelper::class))->getProperty('plugins');
            $pluginsProp->setAccessible(true);
            $pluginsProp->setValue(null, [$pluginObj]);

            $resolved = PluginHelper::getPlugin('privacy', 'j2commerce');
            $this->test('PluginHelper resolves the privacy plugin for rendering',
                !empty($resolved) && isset($resolved->name) && $resolved->name === 'j2commerce');
        } catch (\ReflectionException $e) {
            $this->test('PluginHelper resolves the privacy plugin for rendering', false,
                'Reflection on PluginHelper::plugins failed: ' . $e->getMessage());
        } catch (\Throwable $e) {
            $this->test('PluginHelper resolves the privacy plugin for rendering', false,
                'Unexpected error seeding PluginHelper cache: ' . $e->getMessage());
        }
    }

    /** Render an override file in the scope of a view double and return its HTML. */
    private function renderOverride(string $file, RenderHarnessView $view): string
    {
        $renderer = function () use ($file) {
            ob_start();
            include $file;
            return ob_get_clean();
        };
        return \Closure::bind($renderer, $view, RenderHarnessView::class)();
    }

    public function run(): bool
    {
        echo "=== Consent-UI Override Rendering Tests ===\n\n";

        $isJ6        = $this->isJ6();
        $comName     = $isJ6 ? 'com_j2commerce' : 'com_j2store';
        $overrideDir = JPATH_BASE . '/plugins/privacy/j2commerce/overrides/' . $comName;

        echo "Stack: " . ($isJ6 ? 'J2Commerce 6 (com_j2commerce)' : 'J2Commerce 4/5 (com_j2store)') . "\n\n";

        $this->primeJoomlaState();

        $checkoutFile  = $overrideDir . '/checkout/default_shipping_payment.php';
        $myprofileFile = $overrideDir . '/myprofile/default.php';

        $this->test('Checkout override exists', file_exists($checkoutFile), $checkoutFile);
        $this->test('MyProfile override exists', file_exists($myprofileFile), $myprofileFile);

        // ── Render checkout override → assert real consent checkbox ──────────
        echo "\n-- Checkout: GDPR consent checkbox --\n";
        $view        = new RenderHarnessView();
        $view->order = new \stdClass();
        $view->user  = (object) ['id' => 100];
        $view->params = new Registry(['bootstrap_version' => 5, 'download_area' => 1]);

        $checkoutHtml = '';
        if (!file_exists($checkoutFile)) {
            $this->test('Checkout override rendered without error', false,
                'Checkout override file missing, render skipped: ' . $checkoutFile);
        } else {
            try {
                $checkoutHtml = $this->renderOverride($checkoutFile, $view);
                $this->test('Checkout override rendered without error', true);
            } catch (\Throwable $e) {
                $this->test('Checkout override rendered without error', false,
                    $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            }
        }

        $this->test('Rendered HTML is non-empty', strlen(trim($checkoutHtml)) > 0,
            'Override produced no output (early return / guard hit?)');
        $this->test('Consent wrapper rendered',
            strpos($checkoutHtml, 'j2commerce-privacy-consent') !== false);
        $this->test('Consent checkbox input is type=checkbox',
            strpos($checkoutHtml, 'type="checkbox"') !== false);
        $this->test('Consent checkbox has id="j2commerce_privacy_consent"',
            strpos($checkoutHtml, 'id="j2commerce_privacy_consent"') !== false);
        $this->test('Consent checkbox has name="j2commerce_privacy_consent"',
            strpos($checkoutHtml, 'name="j2commerce_privacy_consent"') !== false);

        // ── Render myprofile override → assert real Privacy tab markup ───────
        echo "\n-- MyProfile: Privacy tab --\n";
        $view2          = new RenderHarnessView();
        $view2->order   = new \stdClass();
        $view2->user    = (object) ['id' => 100];
        $view2->orders  = [];
        $view2->params  = new Registry(['bootstrap_version' => 5, 'download_area' => 1]);

        $profileHtml = '';
        if (!file_exists($myprofileFile)) {
            $this->test('MyProfile override rendered without error', false,
                'MyProfile override file missing, render skipped: ' . $myprofileFile);
        } else {
            try {
                $profileHtml = $this->renderOverride($myprofileFile, $view2);
                $this->test('MyProfile override rendered without error', true);
            } catch (\Throwable $e) {
                $this->test('MyProfile override rendered without error', false,
                    $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            }
        }

        $this->test('Rendered MyProfile HTML is non-empty', strlen(trim($profileHtml)) > 0);
        $this->test('Privacy tab id "j2commerce-privacy-tab" present',
            strpos($profileHtml, 'j2commerce-privacy-tab') !== false);
        $this->test('Privacy tab shield icon present',
            strpos($profileHtml, 'fa-shield') !== false);
        $this->test('Privacy tab title token present',
            strpos($profileHtml, 'PLG_PRIVACY_J2COMMERCE_MYPROFILE_TAB_TITLE') !== false
            || strpos($profileHtml, 'Privacy') !== false);

        echo "\n=== Consent-UI Render Test Summary ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";

        return $this->failed === 0;
    }
}

// Register the legacy J-prefixed aliases the J2Store 4/5 myprofile override expects,
// if the running Joomla version no longer ships them as B/C aliases.
foreach ([
    'JText'    => Text::class,
    'JRoute'   => Route::class,
    'JHtml'    => HTMLHelper::class,
    'JFactory' => Factory::class,
] as $alias => $target) {
    if (!class_exists($alias)) {
        class_alias($target, $alias);
    }
}

$test = new ConsentUiRenderTest();
exit($test->run() ? 0 : 1);

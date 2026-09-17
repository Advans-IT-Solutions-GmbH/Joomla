<?php
/**
 * Test 12: .htaccess check of the installer (behaviour test)
 *
 * Places .htaccess fixtures in the Joomla root, installs the real package
 * (/tmp/extension.zip) through the Joomla CLI and inspects the messages the
 * installer script queued. This exercises checkHtaccess() exactly as it runs
 * during a real installation or update, instead of re-implementing its logic.
 *
 * Cases:
 *   - no .htaccess                                 → no warning
 *   - Joomla's standard htaccess.txt               → no warning
 *   - blocking rules with the README exceptions    → no warning
 *   - /component/ blocking without an exception    → component warning only
 *   - index.php?option= blocking without exception → option warning only
 *   - exception attached to a different rule        → component warning
 *   - exceptions joined with [OR]                   → both warnings
 *   - exceptions that do not match the rule's type  → both warnings
 *   - components/ hardening, option=com_users rule  → no warning
 *   - R=permanent and absolute redirect target      → both warnings
 *
 * The original .htaccess (if any) is restored afterwards.
 */

define('JOOMLA_ROOT', '/var/www/html');

require_once __DIR__ . '/shared-test-helpers.php';

class HtaccessCheckTest
{
    private const PACKAGE      = '/tmp/extension.zip';
    private const PLUGIN_DIR   = JOOMLA_ROOT . '/plugins/ajax/joomlaajaxforms';
    private const HTACCESS     = JOOMLA_ROOT . '/.htaccess';
    private const REQUIRED_KEYS = [
        'PLG_AJAX_JOOMLAAJAXFORMS_HTACCESS_WARNING_TITLE',
        'PLG_AJAX_JOOMLAAJAXFORMS_HTACCESS_COMPONENT_BLOCKED',
        'PLG_AJAX_JOOMLAAJAXFORMS_HTACCESS_OPTION_BLOCKED',
        'PLG_AJAX_JOOMLAAJAXFORMS_HTACCESS_WARNING_ACTION',
    ];

    private int $passed = 0;
    private int $failed = 0;

    /** @var array<string, string> en-GB texts used by the installer in CLI */
    private array $text = [];

    private function test(string $name, bool $ok, string $detail = ''): void
    {
        if ($ok) {
            echo "PASS $name\n";
            $this->passed++;
            return;
        }

        echo "FAIL $name" . ($detail !== '' ? " - $detail" : '') . "\n";
        $this->failed++;
    }

    public function run(): bool
    {
        echo "=== .htaccess Check Tests (installer behaviour) ===\n\n";

        $this->testLanguageKeys();

        if (!is_file(self::PACKAGE)) {
            $this->test('package available at ' . self::PACKAGE, false);
            return $this->summary();
        }

        $original = is_file(self::HTACCESS) ? file_get_contents(self::HTACCESS) : null;

        try {
            $this->runCase('no .htaccess', null, false, false);

            $standard = is_file(JOOMLA_ROOT . '/htaccess.txt') ? file_get_contents(JOOMLA_ROOT . '/htaccess.txt') : null;

            if ($standard !== null) {
                $this->runCase("Joomla's standard htaccess.txt", $standard, false, false);
            } else {
                $this->test("Joomla's standard htaccess.txt is present", false);
            }

            $this->runCase('blocking rules with the README exceptions', <<<'HTACCESS'
RewriteEngine On
# Block /component/ URLs
RewriteCond %{REQUEST_URI} ^(/[a-z]{2})?/component/ [NC]
# Allow com_ajax plugin calls through /component/ blocking
RewriteCond %{QUERY_STRING} !plugin= [NC]
RewriteRule ^([a-z]{2})?/?component/.*$ /$1/ [R=301,L]

# Block index.php?option=com_*
RewriteCond %{QUERY_STRING} ^option=com_ [NC]
# Allow com_ajax through index.php?option= blocking
RewriteCond %{QUERY_STRING} !^option=com_ajax [NC]
RewriteRule ^index\.php$ /? [R=301,L]
HTACCESS, false, false);

            $this->runCase('/component/ blocking without exception', <<<'HTACCESS'
RewriteEngine On
RewriteCond %{REQUEST_URI} ^(/[a-z]{2})?/component/ [NC]
RewriteRule ^([a-z]{2})?/?component/.*$ /$1/ [R=301,L]
HTACCESS, true, false);

            $this->runCase('index.php?option= blocking without exception', <<<'HTACCESS'
RewriteEngine On
RewriteCond %{QUERY_STRING} ^option=com_ [NC]
RewriteCond %{QUERY_STRING} !^option=com_users [NC]
RewriteRule ^index\.php$ /? [R=301,L]
HTACCESS, false, true);

            $this->runCase('exception attached to a different rule', <<<'HTACCESS'
RewriteEngine On
RewriteCond %{QUERY_STRING} !plugin= [NC]
RewriteRule ^legacy-page$ / [R=301,L]
RewriteCond %{REQUEST_URI} ^/component/ [NC]
RewriteRule ^component/.*$ / [R=301,L]
HTACCESS, true, false);

            $this->runCase('exceptions joined with [OR]', <<<'HTACCESS'
RewriteEngine On
RewriteCond %{QUERY_STRING} !plugin= [NC,OR]
RewriteCond %{HTTP_HOST} ^www\. [NC]
RewriteRule ^([a-z]{2})?/?component/.*$ / [R=301,L]
RewriteCond %{QUERY_STRING} ^option=com_ [NC,OR]
RewriteCond %{QUERY_STRING} !^option=com_ajax [NC]
RewriteRule ^index\.php$ /? [R=301,L]
HTACCESS, true, true);

            $this->runCase('exception that does not match the blocked request', <<<'HTACCESS'
RewriteEngine On
RewriteCond %{QUERY_STRING} !^option=com_ajax [NC]
RewriteRule ^([a-z]{2})?/?component/.*$ / [R=301,L]
RewriteCond %{QUERY_STRING} ^option=com_ [NC]
RewriteCond %{REQUEST_URI} !component/ajax [NC]
RewriteRule ^index\.php$ /? [R=301,L]
HTACCESS, true, true);

            $this->runCase('components/ hardening and option=com_users rule', <<<'HTACCESS'
RewriteEngine On
RewriteRule ^components/.*\.php$ - [F,L]
RewriteCond %{QUERY_STRING} ^option=com_users [NC]
RewriteRule ^index\.php$ /login [R=301,L]
HTACCESS, false, false);

            $this->runCase('R=permanent and absolute redirect target', <<<'HTACCESS'
RewriteEngine On
RewriteRule ^component/(.*)$ https://example.org/$1 [L]
RewriteCond %{QUERY_STRING} ^option=com_ [NC]
RewriteRule ^index\.php$ / [R=permanent,L]
HTACCESS, true, true);
        } finally {
            if ($original === null) {
                @unlink(self::HTACCESS);
            } else {
                file_put_contents(self::HTACCESS, $original);
            }
        }

        return $this->summary();
    }

    private function testLanguageKeys(): void
    {
        echo "--- Language keys ---\n";

        foreach (['en-GB', 'de-DE', 'fr-FR'] as $tag) {
            $file    = self::PLUGIN_DIR . '/language/' . $tag . '/plg_ajax_joomlaajaxforms.ini';
            $strings = is_file($file) ? @parse_ini_file($file, false, INI_SCANNER_RAW) : false;

            if (!is_array($strings)) {
                $this->test("$tag language file parses", false, $file);
                continue;
            }

            $missing = array_diff(self::REQUIRED_KEYS, array_keys($strings));
            $this->test("$tag has all .htaccess keys", !$missing, implode(', ', $missing));

            if ($tag === 'en-GB') {
                foreach (self::REQUIRED_KEYS as $key) {
                    $this->text[$key] = $this->normalise(strip_tags((string) ($strings[$key] ?? '')));
                }
            }
        }

        echo "\n";
    }

    private function runCase(string $label, ?string $htaccess, bool $expectComponent, bool $expectOption): void
    {
        echo "--- $label ---\n";

        if ($htaccess === null) {
            @unlink(self::HTACCESS);
        } else {
            file_put_contents(self::HTACCESS, $htaccess);
        }

        [$code, $output] = sh_cli_install(JOOMLA_ROOT, self::PACKAGE);
        $normalised      = $this->normalise(strip_tags(sh_strip_ansi($output)));

        $this->test("$label: installation succeeds", $code === 0, "exit code $code\n$output");

        $title     = $this->text['PLG_AJAX_JOOMLAAJAXFORMS_HTACCESS_WARNING_TITLE'] ?? '';
        $component = $this->firstWords($this->text['PLG_AJAX_JOOMLAAJAXFORMS_HTACCESS_COMPONENT_BLOCKED'] ?? '');
        $option    = $this->firstWords($this->text['PLG_AJAX_JOOMLAAJAXFORMS_HTACCESS_OPTION_BLOCKED'] ?? '');

        $hasWarning   = $title !== '' && str_contains($normalised, $title);
        $hasComponent = $component !== '' && str_contains($normalised, $component);
        $hasOption    = $option !== '' && str_contains($normalised, $option);

        $this->test(
            "$label: warning " . ($expectComponent || $expectOption ? 'shown' : 'not shown'),
            $hasWarning === ($expectComponent || $expectOption),
            $output
        );
        $this->test("$label: /component/ issue " . ($expectComponent ? 'reported' : 'not reported'), $hasComponent === $expectComponent);
        $this->test("$label: option= issue " . ($expectOption ? 'reported' : 'not reported'), $hasOption === $expectOption);

        echo "\n";
    }

    private function normalise(string $text): string
    {
        // SymfonyStyle wraps long messages and prefixes continuation lines.
        $text = preg_replace('/^\s*!?\s*(\[[A-Z]+\])?\s*/m', '', $text);

        return trim(preg_replace('/\s+/', ' ', $text));
    }

    private function firstWords(string $text, int $count = 6): string
    {
        return implode(' ', array_slice(explode(' ', $text), 0, $count));
    }

    private function summary(): bool
    {
        echo "\n=== .htaccess Check Test Summary ===\n";
        echo "Passed: {$this->passed}, Failed: {$this->failed}\n";

        return $this->failed === 0;
    }
}

$test = new HtaccessCheckTest();
exit($test->run() ? 0 : 1);

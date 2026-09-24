<?php
/**
 * Shared helpers for the AJAX Forms HTTP test scripts.
 */

use Joomla\CMS\Application\SiteApplication;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\User\UserHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\DispatcherInterface;
use Joomla\Registry\Registry;

/**
 * Directory the captured mails are written to.
 */
function ajaxforms_mailcatch_dir(): string
{
    return '/tmp/ajaxforms-mailcatch';
}

/**
 * The binary PHP hands an outgoing mail to.
 *
 * Without a sendmail_path in php.ini, PHP uses the path it was compiled with,
 * which is /usr/sbin/sendmail on the official images.
 */
function ajaxforms_mail_binary(): string
{
    $configured = trim((string) ini_get('sendmail_path'));

    if ($configured !== '' && preg_match('#^(/\S+)#', $configured, $match)) {
        return $match[1];
    }

    return '/usr/sbin/sendmail';
}

/**
 * Marks a file as this helper's capture script. A run that is killed between
 * the installation and the clean-up leaves the script behind, and without this
 * marker the next run would take it for the original binary, save it as the
 * backup and write it back for good.
 */
const AJAXFORMS_MAILCATCH_MARKER = '# ajaxforms-mailcatch';

/**
 * Put a capture script in place of that binary, so a mail the site sends is
 * written to a file instead of being delivered. The test container has no mail
 * server, so nothing is lost.
 *
 * Only this CLI test process triggers a capture, so the captured mails, which
 * carry a password reset token in clear text, are readable by their owner only.
 *
 * @return  string|null  Null when the capture is in place, otherwise the reason
 */
function ajaxforms_mailcatch_install(): ?string
{
    $dir = ajaxforms_mailcatch_dir();

    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return 'cannot create ' . $dir;
    }

    @chmod($dir, 0700);

    $binary = ajaxforms_mail_binary();
    $parent = \dirname($binary);

    if (!is_dir($parent) && !@mkdir($parent, 0755, true) && !is_dir($parent)) {
        return 'cannot create ' . $parent;
    }

    $existing = is_file($binary) ? (string) @file_get_contents($binary) : '';
    $isOwn    = $existing !== '' && strpos($existing, AJAXFORMS_MAILCATCH_MARKER) !== false;

    if (is_file($binary) && !$isOwn && !is_file($binary . '.ajaxforms-backup')) {
        @copy($binary, $binary . '.ajaxforms-backup');
    }

    $script = <<<'SH'
#!/bin/sh
AJAXFORMS_MAILCATCH_MARKER
# Test helper: keep the outgoing mail instead of delivering it.
dir="AJAXFORMS_MAILCATCH_DIR"
mkdir -p "$dir" 2>/dev/null
chmod 0700 "$dir" 2>/dev/null
file="$dir/mail-$$-$(date +%s%N).eml"
umask 077
cat > "$file"
exit 0
SH;

    $script = str_replace(
        ['AJAXFORMS_MAILCATCH_MARKER', 'AJAXFORMS_MAILCATCH_DIR'],
        [AJAXFORMS_MAILCATCH_MARKER, $dir],
        $script
    );

    if (@file_put_contents($binary, $script) === false) {
        return 'cannot write ' . $binary;
    }

    @chmod($binary, 0755);

    if (!is_executable($binary)) {
        return $binary . ' is not executable';
    }

    // A test that dies before its clean-up must not leave the capture behind.
    static $armed = false;

    if (!$armed) {
        $armed = true;
        register_shutdown_function('ajaxforms_mailcatch_remove');
    }

    return null;
}

/**
 * Restore whatever was there before. Safe to call more than once.
 */
function ajaxforms_mailcatch_remove(): void
{
    $binary = ajaxforms_mail_binary();
    $backup = $binary . '.ajaxforms-backup';

    if (is_file($backup)) {
        @rename($backup, $binary);
    } elseif (is_file($binary)) {
        $content = (string) @file_get_contents($binary);

        // Only ever delete this helper's own script.
        if (strpos($content, AJAXFORMS_MAILCATCH_MARKER) !== false) {
            @unlink($binary);
        }
    }

    ajaxforms_mailcatch_clear();
    @rmdir(ajaxforms_mailcatch_dir());
}

/**
 * Drop every captured mail.
 */
function ajaxforms_mailcatch_clear(): void
{
    foreach (glob(ajaxforms_mailcatch_dir() . '/*.eml') ?: [] as $file) {
        @unlink($file);
    }
}

/**
 * The captured mails, oldest first.
 *
 * @return  string[]  Raw messages including their headers
 */
function ajaxforms_mailcatch_messages(): array
{
    $files = glob(ajaxforms_mailcatch_dir() . '/*.eml') ?: [];
    sort($files);

    $messages = [];

    foreach ($files as $file) {
        $messages[] = (string) @file_get_contents($file);
    }

    return $messages;
}

/**
 * Prove the capture works before anything is measured through it, so a failing
 * assertion later can only come from the code under test.
 *
 * @return  string|null  Null when a test mail was captured, otherwise the diagnosis
 */
function ajaxforms_mailcatch_selftest(): ?string
{
    ajaxforms_mailcatch_clear();

    $binary = ajaxforms_mail_binary();

    @mail('mailcatch-selftest@example.test', 'AJAX Forms mail capture self test', 'self test');

    if (ajaxforms_mailcatch_messages() !== []) {
        ajaxforms_mailcatch_clear();

        return null;
    }

    return sprintf(
        'no mail captured: sendmail_path=%s, binary=%s (file: %s, executable: %s), dir writable: %s',
        var_export(ini_get('sendmail_path'), true),
        $binary,
        is_file($binary) ? 'yes' : 'no',
        is_executable($binary) ? 'yes' : 'no',
        is_writable(ajaxforms_mailcatch_dir()) ? 'yes' : 'no'
    );
}

/**
 * Readable text of a captured mail: quoted-printable is decoded, anything else
 * is returned unchanged so a link is not mangled.
 */
function ajaxforms_mail_text(string $raw): string
{
    return stripos($raw, 'quoted-printable') === false ? $raw : quoted_printable_decode($raw);
}

/**
 * Let the site build HTML mails, the setting a site has to make for its mails
 * to carry its own design (System, Mail Templates, "Mail style").
 *
 * Set on the component record, so it applies to this process without changing
 * what the site stores.
 *
 * ComponentHelper reads the component through the cache, which needs the
 * application, so the application is built first.
 */
function ajaxforms_enable_html_mail(): void
{
    ajaxforms_bootstrap_site_application();

    ComponentHelper::getParams('com_mails')->set('mail_style', 'both');
}

/**
 * A real SiteApplication for a CLI test process.
 *
 * Joomla starts no application in a CLI process, so Factory::getApplication()
 * fails. The application is built from the container services instead, without
 * the HTTP session the container factory would also require.
 */
function ajaxforms_bootstrap_site_application(): SiteApplication
{
    if (Factory::$application instanceof SiteApplication) {
        return Factory::$application;
    }

    $container = Factory::getContainer();
    $input     = null;

    foreach (['Joomla\\CMS\\Input\\Input', 'Joomla\\Input\\Input'] as $inputClass) {
        try {
            if ($container->has($inputClass)) {
                $input = $container->get($inputClass);
                break;
            }
        } catch (\Throwable $e) {
            // try the next candidate
        }
    }

    $app = new SiteApplication($input, $container->get('config'), null, $container);
    $app->setDispatcher($container->get(DispatcherInterface::class));

    $template           = new \stdClass();
    $template->id       = 0;
    $template->template = 'cassiopeia';
    $template->parent   = '';
    $template->params   = new Registry();

    $property = new \ReflectionProperty($app, 'template');
    $property->setAccessible(true);
    $property->setValue($app, $template);

    Factory::$application = $app;
    $app->loadLanguage();

    return $app;
}

/**
 * The plugin, wired the way its service provider wires it.
 *
 * The configuration is read from #__extensions instead of through
 * PluginHelper::getPlugin(): that one asks for the current user and therefore
 * for an HTTP session, which a CLI process does not have. The array has the
 * same shape, so the plugin is built exactly as in a request.
 */
function ajaxforms_plugin_instance(): object
{
    $app = ajaxforms_bootstrap_site_application();
    $db  = Factory::getContainer()->get(DatabaseInterface::class);

    $query = (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true))
        ->select($db->quoteName(['extension_id', 'params']))
        ->from($db->quoteName('#__extensions'))
        ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
        ->where($db->quoteName('folder') . ' = ' . $db->quote('ajax'))
        ->where($db->quoteName('element') . ' = ' . $db->quote('joomlaajaxforms'));

    $db->setQuery($query);
    $row = $db->loadObject();

    $class  = '\\Advans\\Plugin\\Ajax\\JoomlaAjaxForms\\Extension\\JoomlaAjaxForms';
    $plugin = new $class([
        'id'     => (int) ($row->extension_id ?? 0),
        'type'   => 'ajax',
        'name'   => 'joomlaajaxforms',
        'params' => (string) ($row->params ?? '{}'),
    ]);
    $plugin->setApplication($app);
    $plugin->setDatabase($db);

    return $plugin;
}

/**
 * Create a throwaway account and return its record from #__users.
 *
 * @param   string  $suffix       Distinguishes the accounts of the single suites
 * @param   string  $languageTag  Frontend language of the account, empty for none
 */
function ajaxforms_create_test_user(string $suffix, string $languageTag = ''): object
{
    // Factory::getDate() asks the application for the site offset.
    ajaxforms_bootstrap_site_application();

    $db       = Factory::getContainer()->get(DatabaseInterface::class);
    $username = 'ajaxforms_' . $suffix;
    $email    = $username . '@example.test';

    ajaxforms_delete_test_user($suffix);

    $params = new Registry();

    if ($languageTag !== '') {
        $params->set('language', $languageTag);
    }

    $record               = new \stdClass();
    $record->name         = 'AJAX Forms ' . $suffix;
    $record->username     = $username;
    $record->email        = $email;
    $record->password     = UserHelper::hashPassword(UserHelper::genRandomPassword(24));
    $record->block        = 0;
    $record->sendEmail    = 0;
    $record->registerDate = Factory::getDate()->toSql();
    $record->params       = $params->toString();

    $db->insertObject('#__users', $record, 'id');

    $map          = new \stdClass();
    $map->user_id = (int) $record->id;
    $map->group_id = 2;
    $db->insertObject('#__user_usergroup_map', $map);

    $db->setQuery(
        (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true))
            ->select('*')
            ->from($db->quoteName('#__users'))
            ->where($db->quoteName('id') . ' = ' . (int) $record->id)
    );

    return $db->loadObject();
}

/**
 * Remove that account again.
 */
function ajaxforms_delete_test_user(string $suffix): void
{
    $db       = Factory::getContainer()->get(DatabaseInterface::class);
    $username = 'ajaxforms_' . $suffix;

    $query = (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true))
        ->select($db->quoteName('id'))
        ->from($db->quoteName('#__users'))
        ->where($db->quoteName('username') . ' = ' . $db->quote($username));

    $db->setQuery($query);
    $ids = array_map('intval', (array) $db->loadColumn());

    foreach ($ids as $id) {
        foreach (['#__user_usergroup_map' => 'user_id', '#__users' => 'id'] as $table => $column) {
            $delete = (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true))
                ->delete($db->quoteName($table))
                ->where($db->quoteName($column) . ' = ' . $id);
            $db->setQuery($delete)->execute();
        }
    }
}

/**
 * The plugin may answer directly with {"success":false,...} or through the
 * com_ajax envelope that carries the plugin JSON string in data[0].
 *
 * The envelope itself always has "success":true (com_ajax dispatched the
 * request), so the plugin payload in data[0] is checked first; the outer
 * object only counts when it carries no plugin payload.
 *
 * @return array<string, mixed>|null
 */
function ajaxforms_decode_response(string $body): ?array
{
    $outer = json_decode($body, true);

    if (!is_array($outer)) {
        return null;
    }

    $inner = $outer['data'][0] ?? null;

    if (is_string($inner)) {
        $inner = json_decode($inner, true);
    }

    if (is_array($inner) && array_key_exists('success', $inner)) {
        return $inner;
    }

    return array_key_exists('success', $outer) ? $outer : null;
}

/**
 * True when ajaxforms_decode_response() finds a plugin payload whose top-level
 * success flag is false, whether it came directly from the plugin or from the
 * com_ajax envelope.
 */
function ajaxforms_is_json_rejection(string $body): bool
{
    $data = ajaxforms_decode_response($body);

    return is_array($data) && (($data['success'] ?? null) === false);
}

/**
 * The shipped script on disk.
 *
 * Joomla installs the plugin's media files to media/plg_ajax_joomlaajaxforms
 * (`<media destination="plg_ajax_joomlaajaxforms" folder="media">`), not into
 * the plugin folder. The source layout is accepted as a fallback, so the
 * helpers also work against a checkout.
 */
function ajaxforms_script_path(): string
{
    $candidates = [
        '/var/www/html/media/plg_ajax_joomlaajaxforms/js/joomlaajaxforms.js',
        '/var/www/html/plugins/ajax/joomlaajaxforms/media/js/joomlaajaxforms.js',
    ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return $candidates[0];
}

/**
 * The selectors the shipped script uses to find a com_users form.
 *
 * Read straight from media/js/joomlaajaxforms.js (userFormSelectors), so the
 * test can never drift from the code it is meant to protect. The older shape,
 * one inline querySelector() list per init function, is read as well, so the
 * checks judge the detection itself and not the name of a function.
 *
 * @return string[]  Concrete selectors for this view, in the order the script tries them.
 */
function ajaxforms_form_selectors(string $view, string $task, string $scriptPath = ''): array
{
    $source = (string) @file_get_contents($scriptPath ?: ajaxforms_script_path());

    if (!preg_match('/userFormSelectors:\s*function\s*\([^)]*\)\s*\{\s*return\s*\[(.*?)\];/s', $source, $m)) {
        $init = 'init' . ucfirst($view) . 'Form';

        if (preg_match('/' . $init . ':\s*function[^{]*\{.*?document\.querySelector\(\s*\'([^\']*)\'/s', $source, $inline)) {
            return array_values(array_filter(array_map('trim', explode(',', $inline[1]))));
        }

        return [];
    }

    $selectors = [];

    foreach (preg_split('/,\s*\R/', trim($m[1])) as $entry) {
        $entry = trim($entry, " \t\r\n,");

        if ($entry === '') {
            continue;
        }

        // Rebuild the runtime value of an expression like
        // 'form.com-users-' + view + '__form'  ->  form.com-users-reset__form
        $value = '';

        foreach (preg_split('/\s*\+\s*/', $entry) as $part) {
            $part = trim($part);

            if ($part === 'view') {
                $value .= $view;
            } elseif ($part === 'task') {
                $value .= $task;
            } elseif (preg_match('/^\'(.*)\'$/s', $part, $lit)) {
                $value .= $lit[1];
            } else {
                return [];   // an unexpected shape must not pass silently
            }
        }

        $selectors[] = $value;
    }

    return $selectors;
}

/**
 * True when the selector addresses one element on its own: no descendant
 * combinator, so it does not depend on any wrapper around the form.
 */
function ajaxforms_selector_is_standalone(string $selector): bool
{
    return strpos(trim($selector), ' ') === false;
}

/**
 * True when the selector looks at the form action, which SEF routing rewrites.
 */
function ajaxforms_selector_uses_action(string $selector): bool
{
    return strpos($selector, 'action') !== false;
}

/**
 * Does a standalone selector match a <form> in this HTML?
 *
 * Supports exactly the shapes the script uses: an optional tag name followed by
 * any number of .class, #id and [attr], [attr="v"] or [attr*="v"] parts. An
 * unsupported shape returns null, so the caller can fail instead of passing.
 */
function ajaxforms_selector_matches(string $html, string $selector): ?bool
{
    // An empty body would make loadHTML() raise a PHP warning, which the
    // deprecation lane reads as a finding. Report "not understood" instead.
    if (trim($html) === '') {
        return null;
    }

    if (!preg_match('/^([a-z][a-z0-9]*)?((?:[.#][A-Za-z0-9_-]+|\[[^\]]+\])*)$/', trim($selector), $m)) {
        return null;
    }

    $tag        = $m[1] !== '' ? $m[1] : '*';
    $conditions = [];

    if (preg_match_all('/[.#][A-Za-z0-9_-]+|\[[^\]]+\]/', $m[2], $parts)) {
        foreach ($parts[0] as $part) {
            if ($part[0] === '.') {
                $conditions[] = "contains(concat(' ', normalize-space(@class), ' '), ' " . substr($part, 1) . " ')";
            } elseif ($part[0] === '#') {
                $conditions[] = "@id='" . substr($part, 1) . "'";
            } elseif (preg_match('/^\[([A-Za-z0-9_:-]+)\*=["\']([^"\']*)["\']\]$/', $part, $a)) {
                $conditions[] = "contains(@{$a[1]}, '{$a[2]}')";
            } elseif (preg_match('/^\[([A-Za-z0-9_:-]+)=["\']([^"\']*)["\']\]$/', $part, $a)) {
                $conditions[] = "@{$a[1]}='{$a[2]}'";
            } elseif (preg_match('/^\[([A-Za-z0-9_:-]+)\]$/', $part, $a)) {
                $conditions[] = "@{$a[1]}";
            } else {
                return null;
            }
        }
    }

    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $xpath = '//' . $tag . ($conditions ? '[' . implode(' and ', $conditions) . ']' : '');
    $nodes = (new DOMXPath($document))->query($xpath);

    if ($nodes === false) {
        return null;
    }

    foreach ($nodes as $node) {
        if (strtolower($node->nodeName) === 'form') {
            return true;
        }
    }

    return false;
}

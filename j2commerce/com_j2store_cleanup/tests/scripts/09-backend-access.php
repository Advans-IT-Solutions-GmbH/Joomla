<?php
/**
 * Backend access through real Joomla ACL and real HTTP requests.
 *
 * The run starts by upgrading the installed component from a state without the
 * permission files, so every later check runs on an upgraded installation.
 * A synthetic member of the core "Manager" group then logs into the
 * administrator while the permission rules are switched, and the scan page and
 * the cleanup task are requested the way a browser would:
 *  - core.manage denied: the page and the task are refused.
 *  - core.manage only: the scan is visible, nothing is selectable, a forged
 *    POST removes nothing.
 *  - core.manage and core.delete on the component, but no rights in Joomla's
 *    Extensions (com_installer): still read-only.
 *  - with core.manage and core.delete in com_installer as well: the eligible
 *    record is removed, but only with the form token.
 *  - the permission editor and Options button need core.admin.
 * Finally a member of the core "Administrator" group checks the untouched
 * default rules: Joomla denies that group the Extensions manager, so it may
 * inspect the scan but cannot remove anything.
 */
define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';
require_once __DIR__ . '/shared-test-helpers.php';

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

$db        = Factory::getContainer()->get(DatabaseInterface::class);
$baseUrl   = getenv('BACKEND_VIEWS_BASE_URL') ?: 'http://localhost';
$cookieJar = '/tmp/cleanup-backend-access-cookies.txt';
$failures  = 0;

function ba_check(string $name, bool $condition): void
{
    global $failures;
    echo ($condition ? 'PASS ' : 'FAIL ') . $name . "\n";
    if (!$condition) {
        $failures++;
    }
}

/** @return array{code:int, body:string} */
function ba_request(string $url, array $post = []): array
{
    global $cookieJar;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_COOKIEJAR      => $cookieJar,
        CURLOPT_COOKIEFILE     => $cookieJar,
    ]);
    if ($post) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $code, 'body' => $body === false ? '' : $body];
}

function ba_token(string $html): ?string
{
    foreach (['/name="([a-f0-9]{32})" value="1"/', '/"csrf\.token":"([a-f0-9]{32})"/'] as $pattern) {
        if (preg_match($pattern, $html, $m)) {
            return $m[1];
        }
    }

    return null;
}

function ba_query(DatabaseInterface $db)
{
    return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
}

function ba_rules(DatabaseInterface $db, string $asset): ?string
{
    $db->setQuery(
        ba_query($db)
            ->select($db->quoteName('rules'))
            ->from($db->quoteName('#__assets'))
            ->where($db->quoteName('name') . ' = ' . $db->quote($asset))
    );
    $rules = $db->loadResult();

    return $rules === null ? null : (string) $rules;
}

function ba_set_rules(DatabaseInterface $db, string $rules, string $asset = 'com_j2store_cleanup'): void
{
    $db->setQuery(
        ba_query($db)
            ->update($db->quoteName('#__assets'))
            ->set($db->quoteName('rules') . ' = ' . $db->quote($rules))
            ->where($db->quoteName('name') . ' = ' . $db->quote($asset))
    )->execute();
}

/** @return array{id:int, username:string, password:string} */
function ba_create_user(DatabaseInterface $db, int $groupId, string $label): array
{
    // Random password that exists only for this run.
    $suffix   = bin2hex(random_bytes(6));
    $password = bin2hex(random_bytes(16)) . 'Aa1!';
    $user = (object) [
        'name'         => 'Cleanup Access Test ' . $label,
        'username'     => 'cleanup-acl-' . $suffix,
        'email'        => 'cleanup-acl-' . $suffix . '@example.invalid',
        'password'     => password_hash($password, PASSWORD_BCRYPT),
        'block'        => 0,
        'sendEmail'    => 0,
        'registerDate' => gmdate('Y-m-d H:i:s'),
        'params'       => '{}',
    ];
    $db->insertObject('#__users', $user, 'id');
    $membership = (object) ['user_id' => (int) $user->id, 'group_id' => $groupId];
    $db->insertObject('#__user_usergroup_map', $membership);

    return ['id' => (int) $user->id, 'username' => $user->username, 'password' => $password];
}

/** An eligible J2Store plugin record whose files are missing. @return array{id:int, element:string} */
function ba_insert_probe(DatabaseInterface $db, int $packageId = 0): array
{
    $probe = (object) [
        'package_id'     => $packageId,
        'name'           => 'plg_j2store_aclprobe',
        'type'           => 'plugin',
        'element'        => 'aclprobe_' . bin2hex(random_bytes(6)),
        'folder'         => 'j2store',
        'client_id'      => 0,
        'enabled'        => 0,
        'access'         => 1,
        'protected'      => 0,
        'locked'         => 0,
        'manifest_cache' => '{}',
        'params'         => '{}',
        'custom_data'    => '',
        'state'          => 0,
    ];
    $db->insertObject('#__extensions', $probe, 'extension_id');

    return ['id' => (int) $probe->extension_id, 'element' => $probe->element];
}

function ba_login(string $baseUrl, array $user): bool
{
    global $cookieJar;

    @unlink($cookieJar);
    $login = ba_request($baseUrl . '/administrator/index.php');
    $token = ba_token($login['body']);
    $after = ba_request($baseUrl . '/administrator/index.php', [
        'username' => $user['username'],
        'passwd'   => $user['password'],
        'option'   => 'com_login',
        'task'     => 'login',
        'lang'     => 'en-GB',
        'return'   => base64_encode('index.php'),
        (string) $token => '1',
    ]);

    return $token !== null && str_contains($after['body'], 'task=logout');
}

function ba_has_extension(DatabaseInterface $db, int $id): bool
{
    $db->setQuery(
        ba_query($db)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('extension_id') . ' = ' . $id)
    );

    return (int) $db->loadResult() === 1;
}

$userIds           = [];
$probeIds          = [];
$original          = null;
$originalInstaller = null;
$page              = $baseUrl . '/administrator/index.php?option=com_j2store_cleanup';

try {
    $original          = ba_rules($db, 'com_j2store_cleanup');
    $originalInstaller = ba_rules($db, 'com_installer');
    ba_check('Precondition: the component and Joomla Extensions have permission assets',
        $original !== null && $originalInstaller !== null);

    // 0. Upgrade from an installation without the permission files: earlier
    // releases had no access.xml/config.xml, and an administrator may already
    // have delegated core.manage. The upgrade must deliver both files and keep
    // the rules exactly as they were (no automatic core.delete or core.admin).
    $componentDir = JPATH_BASE . '/administrator/components/com_j2store_cleanup';
    $package      = getenv('BACKEND_VIEWS_PACKAGE') ?: '/tmp/extension.zip';
    $manifest     = sh_read_package_manifest($package);
    ba_check('Precondition: the installation package is readable', $manifest !== null);
    @unlink($componentDir . '/access.xml');
    @unlink($componentDir . '/config.xml');
    $legacyRules = '{"core.manage":{"6":1}}';
    ba_set_rules($db, $legacyRules);
    $upgrade = sys_get_temp_dir() . '/cleanup-upgrade-' . bin2hex(random_bytes(4)) . '.zip';
    $built   = $manifest !== null && copy($package, $upgrade)
        && sh_set_package_version($upgrade, $manifest['xmlfile'], sh_bump_version($manifest['version'])) !== null;
    [$upgradeCode] = $built ? sh_cli_install_reviewed_fixture(JPATH_BASE, $upgrade) : [1, ''];
    @unlink($upgrade);
    ba_check('The package upgrades the installed component', $built && $upgradeCode === 0);
    clearstatcache();
    ba_check('The upgrade delivers access.xml and config.xml',
        is_file($componentDir . '/access.xml') && is_file($componentDir . '/config.xml'));
    ba_check('The upgrade keeps the existing rules and grants neither core.delete nor core.admin',
        json_decode((string) ba_rules($db, 'com_j2store_cleanup'), true) === json_decode($legacyRules, true));

    $manager    = ba_create_user($db, 6, 'Manager');
    $userIds[]  = $manager['id'];
    $probe      = ba_insert_probe($db);
    $probeIds[] = $probe['id'];
    ba_check('A Manager can log into the administrator', ba_login($baseUrl, $manager));

    // 1. core.manage denied: neither the scan nor the task is reachable.
    ba_set_rules($db, '{"core.manage":{"6":0}}');
    $denied = ba_request($page);
    ba_check('Without core.manage the scan page is refused',
        $denied['code'] >= 400 && !str_contains($denied['body'], $probe['element']));
    $cpanel = ba_request($baseUrl . '/administrator/index.php');
    ba_request($page, ['task' => 'cleanup', 'cid' => [$probe['id']], (string) ba_token($cpanel['body']) => '1']);
    ba_check('Without core.manage a forged cleanup request removes nothing', ba_has_extension($db, $probe['id']));

    // 2. core.manage allowed, core.delete denied: read-only scan.
    ba_set_rules($db, '{"core.manage":{"6":1},"core.delete":{"6":0}}');
    $readOnly = ba_request($page);
    ba_check('With core.manage only the scan lists the eligible record',
        $readOnly['code'] === 200 && str_contains($readOnly['body'], $probe['element']));
    ba_check('With core.manage only no record can be selected', !str_contains($readOnly['body'], 'name="cid[]"'));
    ba_request($page, ['task' => 'cleanup', 'cid' => [$probe['id']], (string) ba_token($readOnly['body']) => '1']);
    ba_check('With core.manage only a forged cleanup request removes nothing', ba_has_extension($db, $probe['id']));

    // 3. Delete on the component alone is not enough: removal is an uninstall.
    ba_set_rules($db, '{"core.manage":{"6":1},"core.delete":{"6":1}}');
    ba_set_rules($db, '{"core.manage":{"6":0},"core.delete":{"6":0}}', 'com_installer');
    $noInstaller = ba_request($page);
    ba_check('Without Joomla Extensions permissions no record can be selected',
        $noInstaller['code'] === 200 && !str_contains($noInstaller['body'], 'name="cid[]"'));
    ba_request($page, ['task' => 'cleanup', 'cid' => [$probe['id']], (string) ba_token($noInstaller['body']) => '1']);
    ba_check('Without Joomla Extensions permissions a forged cleanup request removes nothing',
        ba_has_extension($db, $probe['id']));

    // 4. With core.manage and core.delete in com_installer too: removal works, token required.
    ba_set_rules($db, '{"core.manage":{"6":1},"core.delete":{"6":1}}', 'com_installer');
    // A package child without files stays listed, but is never selectable here.
    $child      = ba_insert_probe($db, 999999);
    $probeIds[] = $child['id'];
    $full = ba_request($page);
    ba_check('With component and Extensions permissions the record is selectable',
        $full['code'] === 200 && str_contains($full['body'], 'name="cid[]" value="' . $probe['id'] . '"'));
    ba_check('A package child without files is listed but not selectable',
        str_contains($full['body'], $child['element'])
        && !str_contains($full['body'], 'name="cid[]" value="' . $child['id'] . '"'));
    ba_request($page, ['task' => 'cleanup', 'cid' => [$child['id']], (string) ba_token($full['body']) => '1']);
    ba_check('A forged cleanup request for a package child removes nothing', ba_has_extension($db, $child['id']));

    // The page as Atum really renders it: theme variables and responsive table containers.
    $html = $full['body'];
    ba_check('The page renders inside the Atum administrator template',
        str_contains($html, 'templates/administrator/atum'));
    ba_check('The rendered component surface uses Atum color variables with Bootstrap fallbacks',
        str_contains($html, '<div class="j2cleanup">')
        && str_contains($html, 'var(--body-bg, var(--bs-body-bg, #fff))')
        && str_contains($html, 'var(--body-color, var(--bs-body-color, #212529))'));
    ba_check('The rendered extension tables scroll inside responsive containers',
        preg_match('/\.j2cleanup \.table-responsive\s*\{[^}]*overflow-x:\s*auto/s', $html) === 1
        && preg_match('#<div class="table-responsive">\s*<table#', $html) === 1);
    ba_request($page, ['task' => 'cleanup', 'cid' => [$probe['id']]]);
    ba_check('A cleanup request without the form token removes nothing', ba_has_extension($db, $probe['id']));
    ba_request($page . '&task=cleanup&cid[]=' . $probe['id'] . '&' . ba_token($full['body']) . '=1');
    ba_check('A GET cleanup request carrying the token removes nothing', ba_has_extension($db, $probe['id']));
    ba_request($page, ['task' => 'cleanup', 'cid' => [$probe['id']], (string) ba_token($full['body']) => '1']);
    ba_check('With all permissions and the form token the eligible record is removed',
        !ba_has_extension($db, $probe['id']));
    $after = ba_request($page);
    ba_check('The remove action is offered exactly when a row is selectable',
        $after['code'] === 200
        && str_contains($after['body'], 'name="cid[]"') === str_contains($after['body'], 'name="task" value="cleanup"'));

    // 5. Permission editing: the installed config.xml/access.xml drive Joomla's editor.
    $options = $baseUrl . '/administrator/index.php?option=com_config&view=component&component=com_j2store_cleanup';
    ba_set_rules($db, '{"core.manage":{"6":1}}');
    $withoutAdmin = ba_request($page);
    ba_check('Without core.admin or core.options the Options button is hidden',
        $withoutAdmin['code'] === 200 && !str_contains($withoutAdmin['body'], 'component=com_j2store_cleanup'));
    $refused = ba_request($options);
    ba_check('Without core.admin the permission editor is refused', !str_contains($refused['body'], 'jform_rules'));

    ba_set_rules($db, '{"core.manage":{"6":1},"core.admin":{"6":1}}');
    $withAdmin = ba_request($page);
    ba_check('With core.admin the Options button links to the component options',
        str_contains($withAdmin['body'], 'component=com_j2store_cleanup'));
    $editor = ba_request($options);
    ba_check('With core.admin the permission editor lists the manage and delete actions',
        $editor['code'] === 200 && str_contains($editor['body'], 'jform_rules')
        && str_contains($editor['body'], 'core.manage') && str_contains($editor['body'], 'core.delete'));

    // 6. Untouched default rules: the core Administrator group inherits core.manage
    // and core.delete, but Joomla denies it the Extensions manager.
    ba_set_rules($db, (string) $original);
    ba_set_rules($db, (string) $originalInstaller, 'com_installer');
    $administrator = ba_create_user($db, 7, 'Administrator');
    $userIds[]     = $administrator['id'];
    $second        = ba_insert_probe($db);
    $probeIds[]    = $second['id'];
    ba_check('An Administrator can log into the administrator', ba_login($baseUrl, $administrator));
    $default = ba_request($page);
    ba_check('With default rules an Administrator sees the scan but cannot select records',
        $default['code'] === 200 && str_contains($default['body'], $second['element'])
        && !str_contains($default['body'], 'name="cid[]"'));
    ba_request($page, ['task' => 'cleanup', 'cid' => [$second['id']], (string) ba_token($default['body']) => '1']);
    ba_check('With default rules a forged cleanup request by an Administrator removes nothing',
        ba_has_extension($db, $second['id']));
} finally {
    if ($original !== null) {
        ba_set_rules($db, $original);
    }
    if ($originalInstaller !== null) {
        ba_set_rules($db, $originalInstaller, 'com_installer');
    }
    foreach ($probeIds as $probeId) {
        $db->setQuery(ba_query($db)->delete($db->quoteName('#__extensions'))
            ->where($db->quoteName('extension_id') . ' = ' . (int) $probeId))->execute();
    }
    foreach ($userIds as $userId) {
        foreach (['#__user_usergroup_map' => 'user_id', '#__users' => 'id'] as $table => $column) {
            $db->setQuery(ba_query($db)->delete($db->quoteName($table))
                ->where($db->quoteName($column) . ' = ' . (int) $userId))->execute();
        }
    }
    @unlink($cookieJar);
}

echo $failures === 0 ? "\nBackend access: all checks passed\n" : "\nBackend access: {$failures} check(s) failed\n";
exit($failures === 0 ? 0 : 1);

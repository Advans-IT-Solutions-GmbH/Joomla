<?php
/**
 * Backend access through real Joomla ACL and real HTTP requests.
 *
 * A synthetic member of the core "Manager" group logs into the administrator.
 * The component's permission rules are switched between three states and the
 * scan page and the cleanup task are requested the way a browser would. The
 * run starts by upgrading the installed component from a state without the
 * permission files, so every later check runs on an upgraded installation.
 *  - core.manage denied: the page and the task are refused.
 *  - core.manage allowed, core.delete denied: the scan is visible, but no
 *    selection is offered and a forged POST removes nothing.
 *  - core.manage and core.delete allowed: the eligible record is removed.
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
function ba_request(string $url, array $post = [], bool $follow = true): array
{
    global $cookieJar;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => $follow,
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

function ba_set_rules(DatabaseInterface $db, string $rules): void
{
    $db->setQuery(
        ba_query($db)
            ->update($db->quoteName('#__assets'))
            ->set($db->quoteName('rules') . ' = ' . $db->quote($rules))
            ->where($db->quoteName('name') . ' = ' . $db->quote('com_j2store_cleanup'))
    )->execute();
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

$userId   = 0;
$probeId  = 0;
$original = null;
$page     = $baseUrl . '/administrator/index.php?option=com_j2store_cleanup';

try {
    $db->setQuery(
        ba_query($db)
            ->select($db->quoteName('rules'))
            ->from($db->quoteName('#__assets'))
            ->where($db->quoteName('name') . ' = ' . $db->quote('com_j2store_cleanup'))
    );
    $original = $db->loadResult();
    ba_check('Precondition: the component has its own permission asset', $original !== null);

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
    [$upgradeCode, $upgradeOutput] = $built ? sh_cli_install_reviewed_fixture(JPATH_BASE, $upgrade) : [1, ''];
    @unlink($upgrade);
    ba_check('The package upgrades the installed component', $built && $upgradeCode === 0);
    clearstatcache();
    ba_check('The upgrade delivers access.xml and config.xml',
        is_file($componentDir . '/access.xml') && is_file($componentDir . '/config.xml'));
    $db->setQuery(
        ba_query($db)
            ->select($db->quoteName('rules'))
            ->from($db->quoteName('#__assets'))
            ->where($db->quoteName('name') . ' = ' . $db->quote('com_j2store_cleanup'))
    );
    ba_check('The upgrade keeps the existing rules and grants neither core.delete nor core.admin',
        json_decode((string) $db->loadResult(), true) === json_decode($legacyRules, true));

    // Synthetic Manager with a random password that exists only for this run.
    $suffix   = bin2hex(random_bytes(6));
    $password = bin2hex(random_bytes(16)) . 'Aa1!';
    $user = (object) [
        'name'         => 'Cleanup Access Test Manager',
        'username'     => 'cleanup-acl-' . $suffix,
        'email'        => 'cleanup-acl-' . $suffix . '@example.invalid',
        'password'     => password_hash($password, PASSWORD_BCRYPT),
        'block'        => 0,
        'sendEmail'    => 0,
        'registerDate' => gmdate('Y-m-d H:i:s'),
        'params'       => '{}',
    ];
    $db->insertObject('#__users', $user, 'id');
    $userId = (int) $user->id;
    $membership = (object) ['user_id' => $userId, 'group_id' => 6];
    $db->insertObject('#__user_usergroup_map', $membership);

    // An eligible J2Store plugin record whose files are missing.
    $probe = (object) [
        'name'           => 'plg_j2store_aclprobe',
        'type'           => 'plugin',
        'element'        => 'aclprobe_' . $suffix,
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
    $probeId = (int) $probe->extension_id;

    @unlink($cookieJar);
    $login = ba_request($baseUrl . '/administrator/index.php');
    $token = ba_token($login['body']);
    $after = ba_request($baseUrl . '/administrator/index.php', [
        'username' => $user->username,
        'passwd'   => $password,
        'option'   => 'com_login',
        'task'     => 'login',
        'lang'     => 'en-GB',
        'return'   => base64_encode('index.php'),
        (string) $token => '1',
    ]);
    ba_check('A Manager can log into the administrator', $token !== null && str_contains($after['body'], 'task=logout'));

    // 1. core.manage denied: neither the scan nor the task is reachable.
    ba_set_rules($db, '{"core.manage":{"6":0}}');
    $denied = ba_request($page);
    ba_check('Without core.manage the scan page is refused',
        $denied['code'] >= 400 && !str_contains($denied['body'], $probe->element));
    $cpanel = ba_request($baseUrl . '/administrator/index.php');
    ba_request($page, ['task' => 'cleanup', 'cid' => [$probeId], (string) ba_token($cpanel['body']) => '1']);
    ba_check('Without core.manage a forged cleanup request removes nothing', ba_has_extension($db, $probeId));

    // 2. core.manage allowed, core.delete denied: read-only scan.
    ba_set_rules($db, '{"core.manage":{"6":1},"core.delete":{"6":0}}');
    $readOnly = ba_request($page);
    ba_check('With core.manage only the scan lists the eligible record',
        $readOnly['code'] === 200 && str_contains($readOnly['body'], $probe->element));
    ba_check('With core.manage only no record can be selected', !str_contains($readOnly['body'], 'name="cid[]"'));
    ba_request($page, ['task' => 'cleanup', 'cid' => [$probeId], (string) ba_token($readOnly['body']) => '1']);
    ba_check('With core.manage only a forged cleanup request removes nothing', ba_has_extension($db, $probeId));

    // 3. core.manage and core.delete allowed: the record can be removed.
    ba_set_rules($db, '{"core.manage":{"6":1},"core.delete":{"6":1}}');
    $full = ba_request($page);
    ba_check('With core.manage and core.delete the record is selectable',
        $full['code'] === 200 && str_contains($full['body'], 'name="cid[]" value="' . $probeId . '"'));
    ba_request($page, ['task' => 'cleanup', 'cid' => [$probeId]]);
    ba_check('A cleanup request without the form token removes nothing', ba_has_extension($db, $probeId));
    ba_request($page, ['task' => 'cleanup', 'cid' => [$probeId], (string) ba_token($full['body']) => '1']);
    ba_check('With core.manage, core.delete and the form token the eligible record is removed',
        !ba_has_extension($db, $probeId));

    // 4. Permission editing: the installed config.xml/access.xml drive Joomla's editor.
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
} finally {
    if ($original !== null) {
        ba_set_rules($db, (string) $original);
    }
    if ($probeId > 0) {
        $db->setQuery(ba_query($db)->delete($db->quoteName('#__extensions'))
            ->where($db->quoteName('extension_id') . ' = ' . $probeId))->execute();
    }
    if ($userId > 0) {
        foreach (['#__user_usergroup_map' => 'user_id', '#__users' => 'id'] as $table => $column) {
            $db->setQuery(ba_query($db)->delete($db->quoteName($table))
                ->where($db->quoteName($column) . ' = ' . $userId))->execute();
        }
    }
    @unlink($cookieJar);
}

echo $failures === 0 ? "\nBackend access: all checks passed\n" : "\nBackend access: {$failures} check(s) failed\n";
exit($failures === 0 ? 0 : 1);

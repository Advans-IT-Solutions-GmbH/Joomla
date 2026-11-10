<?php
/**
 * Test-only Joomla administrator identity for authorized import model tests.
 */

use Joomla\CMS\Factory;
use Joomla\CMS\Application\AdministratorApplication;
use Joomla\CMS\Language\LanguageFactoryInterface;
use Joomla\CMS\Session\Session;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;

function importExportBootAuthorizedAdministrator(): array
{
    $container = Factory::getContainer();
    $previousApplication = Factory::$application;
    $previousIdentity    = is_object($previousApplication) && method_exists($previousApplication, 'getIdentity')
        ? $previousApplication->getIdentity()
        : null;
    $context = [
        'application'          => null,
        'previous_application' => $previousApplication,
        'previous_identity'    => $previousIdentity,
        'user_id'              => 0,
    ];

    try {
        $container->alias('session.web', 'session.web.administrator')
            ->alias('session', 'session.web.administrator')
            ->alias(Session::class, 'session.web.administrator')
            ->alias(Joomla\Session\Session::class, 'session.web.administrator')
            ->alias(Joomla\Session\SessionInterface::class, 'session.web.administrator');

        $app = $container->get(AdministratorApplication::class);
        $context['application'] = $app;
        Factory::$application = $app;
        $app->loadLanguage($container->get(LanguageFactoryInterface::class)->createLanguage('en-GB'));
        $app->createExtensionNamespaceMap();

        $db     = $container->get(DatabaseInterface::class);
        $suffix = bin2hex(random_bytes(8));
        $user   = (object) [
            'name'         => 'Synthetic Import Test Administrator',
            'username'     => 'import-test-' . $suffix,
            'email'        => 'import-test-' . $suffix . '@example.invalid',
            'password'     => password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT),
            'block'        => 0,
            'sendEmail'    => 0,
            'registerDate' => gmdate('Y-m-d H:i:s'),
            'params'       => '{}',
        ];

        $db->insertObject('#__users', $user, 'id');
        $context['user_id'] = (int) $user->id;
        $db->insertObject('#__user_usergroup_map', (object) [
            'user_id'  => (int) $user->id,
            'group_id' => 8,
        ]);

        $identity = $container->get(UserFactoryInterface::class)->loadUserById((int) $user->id);
        $app->loadIdentity($identity);

        $context['identity'] = $identity;

        return $context;
    } catch (\Throwable $e) {
        try {
            importExportRemoveTestAdministrator((int) $context['user_id']);
        } finally {
            importExportRestoreApplication($context);
        }

        throw $e;
    }
}

function importExportRestoreApplication(array $context): void
{
    $previousApplication = $context['previous_application'] ?? null;
    $previousIdentity    = $context['previous_identity'] ?? null;

    if (is_object($previousApplication) && $previousIdentity !== null
        && method_exists($previousApplication, 'loadIdentity')) {
        $previousApplication->loadIdentity($previousIdentity);
    }

    Factory::$application = $previousApplication;
}

function importExportRemoveTestAdministrator(int $userId): void
{
    if ($userId < 1) {
        return;
    }

    $db = Factory::getContainer()->get(DatabaseInterface::class);

    foreach (['#__user_usergroup_map' => 'user_id', '#__users' => 'id'] as $table => $column) {
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $db->setQuery($query->delete($db->quoteName($table))
            ->where($db->quoteName($column) . ' = ' . $userId))->execute();
    }
}

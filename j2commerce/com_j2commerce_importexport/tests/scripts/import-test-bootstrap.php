<?php
/**
 * Test-only Joomla administrator identity for authorized import model tests.
 */

use Joomla\CMS\Factory;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;

function importExportBootAuthorizedAdministrator(): array
{
    $container = Factory::getContainer();
    $db        = $container->get(DatabaseInterface::class);
    $app       = Factory::getApplication('administrator');
    $suffix    = bin2hex(random_bytes(8));
    $user      = (object) [
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

    try {
        $db->insertObject('#__user_usergroup_map', (object) [
            'user_id'  => (int) $user->id,
            'group_id' => 8,
        ]);

        $identity = $container->get(UserFactoryInterface::class)->loadUserById((int) $user->id);
        $app->loadIdentity($identity);

        return [
            'application' => $app,
            'identity'    => $identity,
            'user_id'     => (int) $user->id,
        ];
    } catch (\Throwable $e) {
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $db->setQuery($query->delete($db->quoteName('#__user_usergroup_map'))
            ->where($db->quoteName('user_id') . ' = ' . (int) $user->id))->execute();
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $db->setQuery($query->delete($db->quoteName('#__users'))
            ->where($db->quoteName('id') . ' = ' . (int) $user->id))->execute();

        throw $e;
    }
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

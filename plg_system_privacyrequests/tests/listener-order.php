<?php
// Native plugin-event order regression, separate from the browser/Core model lifecycle.
if (!str_ends_with($email, '@example.test') || $app->get('mailer') !== 'smtp'
    || $app->get('smtphost') !== '127.0.0.1') {
    throw new RuntimeException('An explicitly synthetic account and captured loopback mail are required.');
}
$app->loadLanguage(\Joomla\CMS\Factory::getLanguage());
$app->getLanguage()->load('plg_privacy_appointments', JPATH_ADMINISTRATOR, 'en-GB', true);
$app->bootComponent('com_privacy');
$app->bootComponent('com_appointments');
$params = \Joomla\CMS\Component\ComponentHelper::getParams('com_appointments');
$saved = [$params->get('teamcal_enabled', 0), $params->get('erase_mode', 'anonymise')];
$tables = [
    'j2commerce_orders' => 'j2commerce_order_id INT PRIMARY KEY, order_id VARCHAR(64), user_id INT, user_email VARCHAR(320), created_on DATETIME, order_total DECIMAL(10,2), currency_code VARCHAR(8)',
    'j2commerce_orderitems' => 'order_id VARCHAR(64), product_id INT',
    'j2commerce_addresses' => 'j2commerce_address_id INT PRIMARY KEY, user_id INT, email VARCHAR(320)',
    'j2commerce_carts' => 'j2commerce_cart_id INT PRIMARY KEY, user_id INT',
    'j2commerce_cartitems' => 'id INT PRIMARY KEY, cart_id INT',
];
foreach (array_keys($tables) as $table) {
    if (in_array($db->replacePrefix('#__' . $table), $db->getTableList(), true)) {
        throw new RuntimeException('Refusing to shadow an existing shop data set.');
    }
}
$created = $users = $requests = [];
$trigger = null;
$results = [];
$assert = static function (bool $ok, string $label) use (&$results): void {
    if (!$ok) {
        throw new RuntimeException($label);
    }
    $results[] = $label;
};
try {
    foreach ($tables as $table => $columns) {
        $db->setQuery('CREATE TABLE ' . $db->quoteName('#__' . $table) . ' (' . $columns . ') ENGINE=InnoDB')->execute();
        $created[] = $table;
    }
    $core = $app->bootPlugin('user', 'privacy');
    $appointments = $app->bootPlugin('appointments', 'privacy');
    $shop = $app->bootPlugin('j2commerce', 'privacy');
    \Advans\Plugin\Privacy\J2Commerce\Support\J2CommerceStack::reset();
    foreach (['staff', 'unexpected'] as $failure) {
        $account = (object) ['name' => 'Ordered privacy fixture', 'username' => 'ordered-' . bin2hex(random_bytes(8)),
            'email' => $failure . '-' . $email, 'password' => '', 'block' => 0,
            'registerDate' => gmdate('Y-m-d H:i:s'), 'params' => '{}'];
        $db->insertObject('#__users', $account, 'id');
        $uid = (int) $account->id;
        $users[] = $uid;
        $user = $container->get(\Joomla\CMS\User\UserFactoryInterface::class)->loadUserById($uid);
        $request = new \Joomla\Component\Privacy\Administrator\Table\RequestTable($db);
        $request->email = $account->email;
        $request->request_type = 'remove';
        $request->status = 0;
        $request->requested_at = gmdate('Y-m-d H:i:s');
        if (!$request->store()) {
            throw new RuntimeException('Cannot create the pending synthetic receipt.');
        }
        $id = (int) $request->id;
        $requests[] = $id;
        $params->set('teamcal_enabled', 1);
        $params->set('erase_mode', $failure === 'unexpected' ? 'invalid-fixture-mode' : 'anonymise');
        $db->setQuery("INSERT INTO #__j2commerce_addresses VALUES (1,$uid,'alternate@example.test'),(2,0,'bystander@example.test')")->execute();
        $db->setQuery("INSERT INTO #__j2commerce_carts VALUES (1,$uid),(2,0)")->execute();
        $db->setQuery('INSERT INTO #__j2commerce_cartitems VALUES (1,1),(2,2)')->execute();
        if ($failure === 'staff') {
            $db->setQuery('INSERT INTO #__appointments_user_keys'
                . ' (user_id,public_key,password_salt,pwhash_opslimit,pwhash_memlimit,row_mac,created_utc,modified_utc)'
                . " VALUES ($uid,REPEAT(CHAR(1),32),REPEAT(CHAR(2),16),1,1,REPEAT(CHAR(0),32),UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute();
            $trigger = $db->getPrefix() . 'privacy_order_hold';
            fixtureDdl($db, 'CREATE TRIGGER ' . $db->quoteName($trigger)
                . " BEFORE DELETE ON #__appointments_user_keys FOR EACH ROW BEGIN IF OLD.user_id=$uid"
                . " THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected ordered staff cleanup failure'; END IF; END");
        }
        $can = new \Joomla\CMS\Event\Privacy\CanRemoveDataEvent('onPrivacyCanRemoveData', ['subject' => $request, 'user' => $user]);
        $appointments->onPrivacyCanRemoveData($can);
        $shop->onPrivacyCanRemoveData($can);
        foreach ($can->getArgument('result') as $status) {
            $assert($status->canRemove, "$failure: actual CanRemove callbacks capture the original registered identity");
        }
        $stages = [];
        $dispatcher = new \Joomla\Event\Dispatcher();
        foreach (['Core User' => $core, 'Appointments' => $appointments, 'J2Commerce' => $shop] as $name => $listener) {
            $dispatcher->addListener('onPrivacyRemoveData', static function ($event) use ($listener, $name, &$stages): void {
                $listener->onPrivacyRemoveData($event);
                $stages[] = $name;
            });
        }
        $dispatch = static function (? \Joomla\CMS\User\User $subject) use ($dispatcher, $request, $app): void {
            $app->getMessageQueue(true);
            $dispatcher->dispatch('onPrivacyRemoveData',
                new \Joomla\CMS\Event\Privacy\RemoveDataEvent('onPrivacyRemoveData', ['subject' => $request, 'user' => $subject]));
        };
        $dispatch($user);
        $assert($stages === ['Core User', 'Appointments', 'J2Commerce'] && $user->email !== $account->email,
            "$failure: real Core pseudonymisation and Appointments failure do not starve the later actual shop callback");
        foreach (['addresses', 'carts', 'cartitems'] as $suffix) {
            $pk = ['addresses' => 'j2commerce_address_id', 'carts' => 'j2commerce_cart_id', 'cartitems' => 'id'][$suffix];
            $assert((int) $db->setQuery("SELECT COUNT(*) FROM #__j2commerce_$suffix WHERE $pk=1")->loadResult() === 0
                && (int) $db->setQuery("SELECT COUNT(*) FROM #__j2commerce_$suffix WHERE $pk=2")->loadResult() === 1,
                "$failure: actual shop $suffix cleanup runs in the same dispatch and preserves unrelated guest rows");
        }
        $assert(in_array(\Joomla\CMS\Language\Text::_('PLG_PRIVACY_APPOINTMENTS_INCOMPLETE'),
            array_column(array_filter($app->getMessageQueue(), static fn ($message) => $message['type'] === 'warning'), 'message'), true),
            "$failure: Appointments explicitly reports its failed work as a warning");
        \Advans\Component\Appointments\Administrator\Service\PrivacyOutcome::record($db, $id, true);
        $db->setQuery('UPDATE #__appointments_privacy_outcomes SET complete=1 WHERE request_id=' . $id)->execute();
        $assert(\Advans\Component\Appointments\Administrator\Service\PrivacyOutcome::incomplete($db, $id),
            "$failure: authenticated pending attempt vetoes stale and forged success");
        $row = $db->setQuery('SELECT * FROM #__appointments_privacy_outcomes WHERE request_id=' . $id)->loadObject();
        $db->setQuery('DELETE FROM #__appointments_privacy_outcomes WHERE request_id=' . $id)->execute();
        $assert(\Advans\Component\Appointments\Administrator\Service\PrivacyOutcome::incomplete($db, $id),
            "$failure: deleting the outcome flag cannot erase the authenticated pending attempt");
        $db->insertObject('#__appointments_privacy_outcomes', $row);
        $dispatch(null);
        $assert(\Advans\Component\Appointments\Administrator\Service\PrivacyOutcome::incomplete($db, $id),
            "$failure: null-user retry remains incomplete while the Appointments failure persists");
        if ($trigger !== null) {
            fixtureDdl($db, 'DROP TRIGGER ' . $db->quoteName($trigger));
            $trigger = null;
        }
        $params->set('erase_mode', 'anonymise');
        $dispatch(null);
        $assert(!\Advans\Component\Appointments\Administrator\Service\PrivacyOutcome::incomplete($db, $id),
            "$failure: actual repaired null-user retry clears the attempt without changing Core request status");
        foreach (['addresses', 'cartitems', 'carts'] as $suffix) {
            $db->setQuery('DELETE FROM #__j2commerce_' . $suffix)->execute();
        }
    }
} finally {
    $params->set('teamcal_enabled', $saved[0]);
    $params->set('erase_mode', $saved[1]);
    if ($trigger !== null) {
        fixtureDdl($db, 'DROP TRIGGER ' . $db->quoteName($trigger));
    }
    foreach ($requests as $id) {
        foreach (['appointments_privacy_staff', 'appointments_privacy_outcomes', 'privacy_requests'] as $table) {
            $db->setQuery('DELETE FROM #__' . $table . ' WHERE ' . ($table === 'privacy_requests' ? 'id' : 'request_id') . '=' . $id)->execute();
        }
    }
    foreach ($users as $uid) {
        $db->setQuery('DELETE FROM #__appointments_user_keys WHERE user_id=' . $uid)->execute();
        $db->setQuery('DELETE FROM #__users WHERE id=' . $uid)->execute();
    }
    foreach (array_reverse($created) as $table) {
        $db->setQuery('DROP TABLE ' . $db->quoteName('#__' . $table))->execute();
    }
}
$out = ['coverage' => 'native ordered plugin events, not a Core model/browser lifecycle', 'checks' => $results];

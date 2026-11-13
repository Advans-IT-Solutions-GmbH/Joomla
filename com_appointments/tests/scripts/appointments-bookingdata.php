<?php

/**
 * Test helper only: the personal fields of bookings are encrypted at rest
 * (team-calendars.md, 5.10). Suites that read a booking row directly from the
 * database open it with the installation's own BookingData class and the site
 * secret of the test container. Never shipped, never used outside the tests.
 */

defined('_JEXEC') || define('_JEXEC', 1);
require_once __DIR__ . '/appointments-environment.php';
require_once appointments_test_root() . '/configuration.php';
$appointmentsHelper = appointments_test_root() . '/administrator/components/com_appointments/src/Helper/';
require_once $appointmentsHelper . 'KeyProvider.php';
require_once $appointmentsHelper . 'UtcTimestamp.php';
require_once $appointmentsHelper . 'Team/CipherSuite.php';
require_once $appointmentsHelper . 'BookingData.php';

/**
 * A booking row with its personal fields in clear (strict: a field that does not open fails the test).
 *
 * @param array<string, mixed>|null $row
 *
 * @return array<string, mixed>|null
 */
function appointments_open_booking(?array $row): ?array
{
    static $data = null;

    if ($row === null) {
        return null;
    }

    $data ??= new \Advans\Component\Appointments\Administrator\Helper\BookingData(
        new \Advans\Component\Appointments\Administrator\Helper\KeyProvider((string) (new JConfig())->secret)
    );

    return $data->open($row);
}

/**
 * Fields as the application stores them: the personal ones sealed for the booking's UID and creation time.
 *
 * @param array<string, mixed> $fields
 *
 * @return array<string, mixed>
 */
function appointments_seal_booking(array $stored, array $fields): array
{
    $data = new \Advans\Component\Appointments\Administrator\Helper\BookingData(
        new \Advans\Component\Appointments\Administrator\Helper\KeyProvider((string) (new JConfig())->secret)
    );

    $context = array_intersect_key($stored, array_flip(['id', 'eventtype_id', 'host_user_id', 'uid', 'created_utc']));

    return array_intersect_key($data->seal($fields + $context), $fields);
}

/** Whether a stored value is encrypted (for the checks that nothing personal is stored in clear). */
function appointments_is_sealed(mixed $value): bool
{
    return \Advans\Component\Appointments\Administrator\Helper\BookingData::isSealed($value);
}

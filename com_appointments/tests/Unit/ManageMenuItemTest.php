<?php

namespace Advans\Component\Appointments\Tests\Unit;

use Advans\Component\Appointments\Administrator\Helper\ManageMenuItem;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Conference/RuntimeStubs.php';

final class ManageMenuItemTest extends TestCase
{
    private static function row(int $id, string $link, string $language = '*'): array
    {
        return ['id' => $id, 'link' => $link, 'language' => $language];
    }

    public function testTheBookingPageOfTheEventTypeFramesItsSignedPages(): void
    {
        $rows = [
            self::row(10, 'index.php?option=com_appointments&view=eventtypes'),
            self::row(11, 'index.php?option=com_appointments&view=eventtype&id=3'),
            self::row(12, 'index.php?option=com_appointments&view=eventtype&id=4'),
        ];

        self::assertSame(12, ManageMenuItem::pick($rows, 4, 'de-DE'));
        self::assertSame(11, ManageMenuItem::pick($rows, 3, 'de-DE'));
    }

    public function testWithoutABookingPageTheListFramesThem(): void
    {
        $rows = [
            self::row(10, 'index.php?option=com_appointments&view=eventtypes'),
            self::row(11, 'index.php?option=com_appointments&view=eventtype&id=3'),
            // Another component's menu item and the internet calendars never frame a booking.
            self::row(5, 'index.php?option=com_content&view=featured'),
            self::row(6, 'index.php?option=com_appointments&view=mycalendars'),
        ];

        self::assertSame(10, ManageMenuItem::pick($rows, 9, 'de-DE'));
    }

    public function testTheLanguageOfTheLinkComesBeforeAllAndAnotherLanguageIsNeverUsed(): void
    {
        $rows = [
            self::row(20, 'index.php?option=com_appointments&view=eventtype&id=4', '*'),
            self::row(21, 'index.php?option=com_appointments&view=eventtype&id=4', 'en-GB'),
            self::row(22, 'index.php?option=com_appointments&view=eventtype&id=4', 'de-DE'),
        ];

        self::assertSame(22, ManageMenuItem::pick($rows, 4, 'de-DE'));
        self::assertSame(21, ManageMenuItem::pick($rows, 4, 'en-GB'));
        self::assertSame(20, ManageMenuItem::pick($rows, 4, 'fr-FR'));
        // Only an item of another language: a list in "All" frames the page, else nothing.
        $other = [self::row(30, 'index.php?option=com_appointments&view=eventtype&id=4', 'en-GB')];
        self::assertSame(0, ManageMenuItem::pick($other, 4, 'de-DE'));
        $other[] = self::row(31, 'index.php?option=com_appointments&view=eventtypes', '*');
        self::assertSame(31, ManageMenuItem::pick($other, 4, 'de-DE'));
    }

    public function testTheBookingPageInAllComesBeforeAListInTheLanguage(): void
    {
        $rows = [
            self::row(40, 'index.php?option=com_appointments&view=eventtypes', 'de-DE'),
            self::row(41, 'index.php?option=com_appointments&view=eventtype&id=4', '*'),
        ];

        self::assertSame(41, ManageMenuItem::pick($rows, 4, 'de-DE'));
    }

    public function testTheLowestIdWinsATieAndNoItemMeansNoItemid(): void
    {
        $rows = [
            self::row(52, 'index.php?option=com_appointments&view=eventtype&id=4'),
            self::row(51, 'index.php?option=com_appointments&view=eventtype&id=4'),
        ];

        self::assertSame(51, ManageMenuItem::pick($rows, 4, 'de-DE'));
        self::assertSame(0, ManageMenuItem::pick([], 4, 'de-DE'));
        // A link to an event type whose id only starts with the booking's.
        self::assertSame(0, ManageMenuItem::pick([self::row(53, 'index.php?option=com_appointments&view=eventtype&id=40')], 4, 'de-DE'));
    }

    public function testARedirectKeepsTheActiveMenuItemOfTheComponent(): void
    {
        $active = (object) ['id' => 7, 'query' => ['option' => 'com_appointments', 'view' => 'eventtypes']];
        // The database is not consulted: the request already came through a page of this component.
        $db = $this->createMock(\Joomla\Database\DatabaseInterface::class);
        $db->expects(self::never())->method('setQuery');

        self::assertSame(7, ManageMenuItem::frame($active, $db, 4, 'de-DE'));
    }
}
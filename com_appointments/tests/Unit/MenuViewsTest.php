<?php

namespace Advans\Component\Appointments\Tests\Unit;

use Advans\Component\Appointments\Administrator\Helper\MenuViews;
use PHPUnit\Framework\TestCase;

/**
 * The reachable site requests: a page only through the menu item of its type, the pages that
 * check a signature, token or session themselves, and the listed tasks; everything else is 404.
 */
final class MenuViewsTest extends TestCase
{
    private const BOOKING_PAGE = ['option' => 'com_appointments', 'view' => 'eventtype', 'id' => '7'];
    private const LIST = ['option' => 'com_appointments', 'view' => 'eventtypes'];
    private const MYCALENDARS = ['option' => 'com_appointments', 'view' => 'mycalendars'];
    private const ARTICLE = ['option' => 'com_content', 'view' => 'article', 'id' => '1'];

    private static function page(string $view, int $id = 0, ?array $menu = null, string $layout = '', string $format = ''): bool
    {
        return MenuViews::allows('display', 'display', $view, $id, $layout, $format, $menu);
    }

    public function testABookingPageOpensThroughItsOwnMenuItemOnly(): void
    {
        self::assertTrue(self::page('eventtype', 7, self::BOOKING_PAGE));
        // The background update of the times is the same page.
        self::assertTrue(self::page('eventtype', 7, self::BOOKING_PAGE, '', 'raw'));
        self::assertTrue(self::page('eventtype', 7, self::BOOKING_PAGE, '_:default'));
        // Another event type on the menu item of one event type.
        self::assertFalse(self::page('eventtype', 8, self::BOOKING_PAGE));
        self::assertFalse(self::page('eventtype', 0, self::BOOKING_PAGE));
        // Without a menu item of the component.
        self::assertFalse(self::page('eventtype', 7, null));
        self::assertFalse(self::page('eventtype', 7, self::ARTICLE));
    }

    public function testAQueryParameterCannotSwitchAMenuItemToAnotherView(): void
    {
        self::assertFalse(self::page('eventtypes', 0, self::BOOKING_PAGE));
        self::assertFalse(self::page('', 0, self::BOOKING_PAGE), 'The default view is the list.');
        self::assertFalse(self::page('mycalendars', 0, self::BOOKING_PAGE));
        self::assertFalse(self::page('eventtype', 7, self::MYCALENDARS));
        self::assertFalse(self::page('guest', 0, self::BOOKING_PAGE), 'A layout folder without a view class.');
        self::assertFalse(self::page('nosuchview', 0, self::LIST));
        // View names are compared without case, as Joomla finds the view class without case.
        self::assertFalse(self::page('EventTypes', 0, self::BOOKING_PAGE));
        self::assertTrue(self::page('EventType', 7, self::BOOKING_PAGE));
    }

    public function testNeitherALayoutNorAFormatCanBeSwitched(): void
    {
        self::assertFalse(self::page('eventtype', 7, self::BOOKING_PAGE, 'default_details'));
        self::assertFalse(self::page('eventtype', 7, self::BOOKING_PAGE, 'othertemplate:default'));
        self::assertFalse(self::page('eventtype', 7, self::BOOKING_PAGE, '', 'json'));
        self::assertFalse(self::page('eventtypes', 0, self::LIST, '', 'raw'));
        self::assertFalse(self::page('eventtypes', 0, self::LIST, 'created'));
        self::assertFalse(self::page('mycalendars', 0, self::MYCALENDARS, 'created'), 'Only the response of «Add device» shows it.');
        self::assertFalse(self::page('mycalendars', 0, self::MYCALENDARS, '', 'raw'));
        // A menu item with an alternative layout of a template keeps exactly that layout.
        $custom = self::BOOKING_PAGE + ['layout' => 'mytemplate:compact'];
        self::assertTrue(self::page('eventtype', 7, $custom, 'mytemplate:compact'));
        self::assertFalse(self::page('eventtype', 7, $custom, 'default'));
    }

    public function testTheListOpensThroughItsMenuItemWithTheEventTypesItLists(): void
    {
        self::assertTrue(self::page('eventtypes', 0, self::LIST));
        self::assertTrue(self::page('', 0, self::LIST));
        // The booking pages below the list item; publication, access and language are checked by the page.
        self::assertTrue(self::page('eventtype', 8, self::LIST));
        self::assertTrue(self::page('eventtype', 8, self::LIST, '', 'raw'));
        self::assertFalse(self::page('eventtype', 0, self::LIST));
        self::assertFalse(self::page('eventtypes', 0, null));
        self::assertFalse(self::page('', 0, null), 'The component address without a menu item.');
    }

    public function testMyCalendarsOpensThroughItsMenuItemWithItsDevicePage(): void
    {
        self::assertTrue(self::page('mycalendars', 0, self::MYCALENDARS));
        self::assertTrue(self::page('mycalendars', 3, self::MYCALENDARS, 'device'));
        self::assertFalse(self::page('mycalendars', 0, null));
        self::assertFalse(self::page('mycalendars', 0, self::LIST));
    }

    public function testPagesThatCheckTheirOwnProofNeedNoMenuItem(): void
    {
        foreach (['booking', 'manage', 'activation'] as $view) {
            self::assertTrue(self::page($view, 0, null), $view);
            self::assertTrue(self::page($view, 0, self::BOOKING_PAGE), $view . ' in the frame of a booking page');
            self::assertFalse(self::page($view, 0, null, 'edit'), $view . ' with another layout');
            self::assertFalse(self::page($view, 0, null, '', 'raw'), $view . ' in another format');
        }
    }

    public function testOnlyTheListedTasksRun(): void
    {
        foreach (MenuViews::TASKS as $controller => $tasks) {
            foreach ($tasks as $task) {
                self::assertTrue(MenuViews::allows($controller, $task, '', 0, '', '', null), $controller . '.' . $task);
                self::assertTrue(MenuViews::allows(strtoupper($controller), strtoupper($task), '', 0, '', '', null), 'Without case: ' . $controller . '.' . $task);
            }

            // Every controller has a display task, and an unknown or empty task falls back to it.
            self::assertFalse(MenuViews::allows($controller, 'display', 'eventtypes', 0, '', '', null), $controller . '.display');
            self::assertFalse(MenuViews::allows($controller, '', 'eventtypes', 0, '', '', null), $controller . '.');
            self::assertFalse(MenuViews::allows($controller, 'nosuchtask', 'eventtypes', 0, '', '', self::LIST), $controller . '.nosuchtask');
        }

        self::assertFalse(MenuViews::allows('display', 'submit', '', 0, '', '', self::LIST));
        self::assertFalse(MenuViews::allows('booking', 'remove', '', 0, '', '', null));
        self::assertFalse(MenuViews::allows('nosuchcontroller', 'submit', '', 0, '', '', null));
        self::assertFalse(MenuViews::allows('feeds', 'export', '', 0, '', '', null), 'An administrator task.');
        self::assertTrue(MenuViews::allows('', '', '', 0, '', '', self::LIST), 'No task is the display of the view.');
    }
}

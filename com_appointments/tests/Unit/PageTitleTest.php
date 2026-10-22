<?php

namespace Advans\Component\Appointments\Tests\Unit;

use Advans\Component\Appointments\Site\View\PageTitleTrait;
use Joomla\Registry\Registry;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Conference/RuntimeStubs.php';
require_once dirname(__DIR__, 2) . '/components/com_appointments/src/View/PageTitleTrait.php';

final class PageTitleTest extends TestCase
{
    private function resolve(?object $menu, string $view, string $title, int $id = 0): array
    {
        $page = new class {
            use PageTitleTrait;
        };

        return $page::resolvePageTitle($menu, $view, $title, $id);
    }

    private function menu(array $query, string $title, array $params = []): object
    {
        return new class($query, $title, new Registry($params)) {
            public function __construct(public array $query, public string $title, private Registry $params)
            {
            }

            public function getParams(): Registry
            {
                return $this->params;
            }
        };
    }

    public function testWithoutAMenuItemOfItsOwnThePageUsesItsOwnTitle(): void
    {
        // The release test case: the home page menu item is active on /component/appointments/.
        $home = $this->menu(['option' => 'com_content', 'view' => 'featured'], 'We Provide Technology!');
        self::assertSame(['title' => 'Termin buchen', 'heading' => 'Termin buchen'], $this->resolve($home, 'eventtypes', 'Termin buchen'));
        self::assertSame(['title' => 'Beratung', 'heading' => 'Beratung'], $this->resolve(null, 'eventtype', 'Beratung', 4));
        // A list menu item does not own a single event type, nor does another event type's item.
        $list = $this->menu(['option' => 'com_appointments', 'view' => 'eventtypes'], 'Termine');
        self::assertSame('Beratung', $this->resolve($list, 'eventtype', 'Beratung', 4)['title']);
        $other = $this->menu(['option' => 'com_appointments', 'view' => 'eventtype', 'id' => '5'], 'Andere');
        self::assertSame('Beratung', $this->resolve($other, 'eventtype', 'Beratung', 4)['title']);
    }

    public function testAMenuItemOfThePageKeepsItsBrowserTitleAndPageHeading(): void
    {
        $item = $this->menu(['option' => 'com_appointments', 'view' => 'eventtype', 'id' => '4'], 'Erstgespräch', [
            'page_title' => 'Erstgespräch vereinbaren', 'show_page_heading' => 1, 'page_heading' => 'Ihr Erstgespräch',
        ]);
        self::assertSame(['title' => 'Erstgespräch vereinbaren', 'heading' => 'Ihr Erstgespräch'], $this->resolve($item, 'eventtype', 'Beratung', 4));
        // Without a browser page title the menu title applies; a hidden page heading keeps the default.
        $item = $this->menu(['option' => 'com_appointments', 'view' => 'eventtypes'], 'Termine', ['page_heading' => 'Unsichtbar']);
        self::assertSame(['title' => 'Termine', 'heading' => 'Termin buchen'], $this->resolve($item, 'eventtypes', 'Termin buchen'));
    }
}

<?php

namespace Advans\Component\Appointments\Tests\Unit;

use Advans\Component\Appointments\Site\Helper\BackLink;
use Joomla\Registry\Registry;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Conference/RuntimeStubs.php';
require_once dirname(__DIR__, 2) . '/components/com_appointments/src/Helper/BackLink.php';

final class BackLinkTest extends TestCase
{
    private function menu(int $id, array $query, array $params = []): object
    {
        return new class($id, $query, new Registry($params)) {
            public function __construct(public int $id, public array $query, private Registry $params)
            {
            }

            public function getParams(): Registry
            {
                return $this->params;
            }
        };
    }

    public function testWithoutAMenuItemOfOneEventTypeTheButtonLeadsToTheList(): void
    {
        self::assertSame([BackLink::OVERVIEW, 0], BackLink::decide(null, true));
        $home = $this->menu(1, ['option' => 'com_content', 'view' => 'featured']);
        self::assertSame([BackLink::OVERVIEW, 0], BackLink::decide($home, true));
        $list = $this->menu(2, ['option' => 'com_appointments', 'view' => 'eventtypes']);
        self::assertSame([BackLink::OVERVIEW, 0], BackLink::decide($list, true));
        self::assertSame([BackLink::OVERVIEW, 0], BackLink::decide($list, false));
    }

    public function testOnAMenuItemOfOneEventTypeTheButtonIsHiddenByDefault(): void
    {
        $item = $this->menu(7, ['option' => 'com_appointments', 'view' => 'eventtype', 'id' => '4']);

        self::assertSame([BackLink::NONE, 0], BackLink::decide($item, true));
        // The confirmation and the signed pages keep a way out within the branch: the booking page.
        self::assertSame([BackLink::ITEM, 7], BackLink::decide($item, false));
    }

    public function testTheMenuItemCanShowTheButtonAndChooseItsTarget(): void
    {
        $shown = $this->menu(7, ['option' => 'com_appointments', 'view' => 'eventtype', 'id' => '4'], ['show_back_link' => '1']);
        self::assertSame([BackLink::OVERVIEW, 0], BackLink::decide($shown, true));
        self::assertSame([BackLink::OVERVIEW, 0], BackLink::decide($shown, false));

        $target = $this->menu(7, ['option' => 'com_appointments', 'view' => 'eventtype', 'id' => '4'],
            ['show_back_link' => '1', 'back_link_itemid' => '12']);
        self::assertSame([BackLink::ITEM, 12], BackLink::decide($target, true));
        self::assertSame([BackLink::ITEM, 12], BackLink::decide($target, false));

        // A target without the button shown changes nothing.
        $hidden = $this->menu(7, ['option' => 'com_appointments', 'view' => 'eventtype', 'id' => '4'],
            ['show_back_link' => '0', 'back_link_itemid' => '12']);
        self::assertSame([BackLink::NONE, 0], BackLink::decide($hidden, true));
    }
}

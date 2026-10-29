<?php
/**
 * @package     DMARC Reports Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Layout guard for the Action Center on the dashboard. Each status form used to
 * be its own flex item in a wrapping, space-between row next to the text, so the
 * buttons landed left, centre or right depending on the length of the text and
 * the width of the window. The buttons must instead sit in one fixed row below
 * the text, in the same order for every item.
 *
 * The template is rendered in a separate PHP process (Fixtures/render-dashboard.php)
 * so its Joomla stand-ins stay out of this process.
 *
 * @since  1.1.3
 */
class DashboardLayoutTest extends TestCase
{
    private function render(bool $canEditState): \DOMXPath
    {
        $script = __DIR__ . '/Fixtures/render-dashboard.php';
        $cmd    = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . ($canEditState ? '1' : '0');
        $output = [];
        $code   = 0;

        exec($cmd . ' 2>&1', $output, $code);
        $html = implode("\n", $output);

        $this->assertSame(0, $code, "Rendering the dashboard template failed:\n" . $html);
        $this->assertStringNotContainsString('Warning:', $html);
        $this->assertStringNotContainsString('Notice:', $html);
        $this->assertStringNotContainsString('Deprecated:', $html);

        $doc      = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($doc);
    }

    private static function classIs(string $class): string
    {
        return "contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')";
    }

    /**
     * @return \DOMElement[]
     */
    private function actionItems(\DOMXPath $xp): array
    {
        $items = iterator_to_array($xp->query('//ol[' . self::classIs('list-group-numbered') . ']/li'));
        $this->assertCount(2, $items, 'Both fixture actions are rendered');

        return $items;
    }

    public function testNoFormIsADirectFlexItemOfTheActionRow(): void
    {
        $xp = $this->render(true);

        foreach ($this->actionItems($xp) as $li) {
            $this->assertSame(0, $xp->query('./form', $li)->length, 'Forms must not be flex items of the row');

            $classes = preg_split('/\s+/', trim($li->getAttribute('class')));
            $this->assertNotContains('flex-wrap', $classes);
            $this->assertNotContains('justify-content-between', $classes);
        }
    }

    public function testButtonsSitInOneRowBelowTheText(): void
    {
        $xp = $this->render(true);

        foreach ($this->actionItems($xp) as $li) {
            $controls = $xp->query('.//div[' . self::classIs('com-dmarcreports-action-controls') . ']', $li);
            $this->assertSame(1, $controls->length, 'Exactly one controls row per action');

            $row = $controls->item(0);
            $this->assertInstanceOf(\DOMElement::class, $row);

            // The row is the last element of the same column that holds the title,
            // so it always comes after the text, never beside it.
            $column = $row->parentNode;
            $this->assertInstanceOf(\DOMElement::class, $column);
            $this->assertSame(1, $xp->query('./div[' . self::classIs('fw-bold') . ']', $column)->length);
            $this->assertSame($row, $xp->query('./*[last()]', $column)->item(0));

            $tasks = [];

            foreach ($xp->query('./form', $row) as $form) {
                $tasks[] = $xp->query('./input[@name="task"]', $form)->item(0)->getAttribute('value');
                $this->assertSame('m-0', $form->getAttribute('class'), 'No per-form margins that shift single buttons');
            }

            $this->assertSame(['actions.done', 'actions.snooze', 'actions.ignore'], $tasks);
        }
    }

    public function testControlsRowIsIdenticalForShortAndLongText(): void
    {
        $xp    = $this->render(true);
        $shape = [];

        foreach ($this->actionItems($xp) as $li) {
            $row     = $xp->query('.//div[' . self::classIs('com-dmarcreports-action-controls') . ']', $li)->item(0);
            $buttons = [];

            foreach ($xp->query('.//button', $row) as $button) {
                $buttons[] = $button->getAttribute('class') . '|' . trim($button->textContent);
            }

            $shape[] = [$li->getAttribute('class'), $row->getAttribute('class'), $buttons];
        }

        $this->assertSame($shape[0], $shape[1], 'The text length must not change the layout of the controls');
    }

    public function testHandledItemsUseTheSameControlsRow(): void
    {
        $xp = $this->render(true);
        $li = $xp->query('//ul[' . self::classIs('list-group') . ']/li')->item(0);

        $this->assertInstanceOf(\DOMElement::class, $li);
        $this->assertSame(0, $xp->query('./form', $li)->length);
        $this->assertSame(
            1,
            $xp->query('.//div[' . self::classIs('com-dmarcreports-action-controls') . ']/form/input[@name="task"][@value="actions.reopen"]', $li)->length
        );
    }

    public function testViewOnlyUsersSeeNoControls(): void
    {
        $xp = $this->render(false);

        $this->assertSame(0, $xp->query('//div[' . self::classIs('com-dmarcreports-action-controls') . ']')->length);
        $this->assertSame(0, $xp->query('//form')->length);
        $this->assertCount(2, $this->actionItems($xp));
    }
}

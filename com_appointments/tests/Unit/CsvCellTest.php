<?php

use Advans\Component\Appointments\Administrator\Helper\CsvCell;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CsvCellTest extends TestCase
{
    public static function cells(): array
    {
        return [
            [null, ''], ['', ''], ['ordinary text', 'ordinary text'], ['123', '123'],
            ['=1+1', "'=1+1"], ['+123', "'+123"], ['-1', "'-1"], ['@SUM(A1)', "'@SUM(A1)"],
            ["\ttext", "'\ttext"], ["\rtext", "'\rtext"], ["\ntext", "'\ntext"],
            [" \t=1", "' \t=1"], ["\0=1", "'\0=1"], ["\u{00a0}=1", "'\u{00a0}=1"],
            ["'safe", "'safe"], ["a\nb", "a\nb"],
        ];
    }

    #[DataProvider('cells')]
    public function testSpreadsheetCellsCannotBecomeFormulas(?string $value, string $expected): void
    {
        $this->assertSame($expected, CsvCell::safe($value));
    }
}

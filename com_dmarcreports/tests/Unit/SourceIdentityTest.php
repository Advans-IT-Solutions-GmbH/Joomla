<?php
/**
 * @package     DMARC Reports Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Tests\Unit;

use Advans\Component\DmarcReports\Administrator\Helper\SourceIdentity;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the mailbox-identity comparison that decides when a source
 * save (or a concurrent poll) must reset the persisted UID cursor.
 *
 * @since  1.0.0
 */
class SourceIdentityTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function source(array $overrides = []): array
    {
        return array_merge(
            [
                'protocol' => 'imap',
                'host'     => 'mail.example.org',
                'port'     => 993,
                'security' => 'ssl',
                'username' => 'reports@example.org',
                'folder'   => 'INBOX',
                // A non-identity column must never influence the comparison.
                'password_enc' => 'secret',
            ],
            $overrides
        );
    }

    public function testUnchangedWhenIdentityFieldsAreEqual(): void
    {
        $this->assertFalse(SourceIdentity::changed($this->source(), $this->source()));
    }

    public function testCredentialChangeDoesNotCountAsIdentityChange(): void
    {
        // Re-authenticating against the same mailbox must keep the cursor, so a
        // password change alone is not an identity change.
        $this->assertFalse(
            SourceIdentity::changed($this->source(), $this->source(['password_enc' => 'rotated']))
        );
    }

    public function testHostChangeIsAnIdentityChange(): void
    {
        $this->assertTrue(
            SourceIdentity::changed($this->source(), $this->source(['host' => 'imap.example.net']))
        );
    }

    public function testFolderChangeIsAnIdentityChange(): void
    {
        $this->assertTrue(
            SourceIdentity::changed($this->source(), $this->source(['folder' => 'Archive']))
        );
    }

    public function testPortComparisonIgnoresValueType(): void
    {
        // The stored row returns an int while a posted form sends a string; the
        // same port must not be read as a change.
        $this->assertFalse(
            SourceIdentity::changed($this->source(['port' => 993]), $this->source(['port' => '993']))
        );

        $this->assertTrue(
            SourceIdentity::changed($this->source(['port' => 993]), $this->source(['port' => '143']))
        );
    }

    public function testObjectAndArrayRowsCompareEqual(): void
    {
        $this->assertFalse(SourceIdentity::changed($this->source(), (object) $this->source()));
    }

    public function testMissingFieldsAreTreatedAsEmpty(): void
    {
        // A partial posted payload must not be read as equal to a fully
        // populated source: the absent fields differ from the stored values.
        $this->assertTrue(SourceIdentity::changed($this->source(), ['host' => 'mail.example.org']));
    }
}

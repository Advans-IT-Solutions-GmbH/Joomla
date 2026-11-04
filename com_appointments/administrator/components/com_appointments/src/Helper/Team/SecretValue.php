<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Helper\Team;

defined('_JEXEC') or die;

/**
 * Base of every object that holds key material in memory for the length of one
 * request. It cannot be serialised (so it never reaches a session, a cache or a
 * log by accident), it hides its bytes from var_dump() and print_r(), and it
 * overwrites them when the request ends or wipe() is called.
 *
 * @since  1.4.0
 */
abstract class SecretValue
{
    /**
     * @var  string
     */
    private $bytes;

    protected function __construct(string $bytes)
    {
        $this->bytes = $bytes;
    }

    /**
     * The raw bytes. Never write them anywhere; pass them to sodium only.
     *
     * @throws  \LogicException  After wipe().
     */
    protected function bytes(): string
    {
        if ($this->bytes === '') {
            throw new \LogicException('The key material has been wiped.');
        }

        return $this->bytes;
    }

    public function wipe(): void
    {
        if ($this->bytes !== '' && \function_exists('sodium_memzero')) {
            sodium_memzero($this->bytes);
        }

        $this->bytes = '';
    }

    public function __destruct()
    {
        $this->wipe();
    }

    public function __serialize(): array
    {
        throw new \LogicException('Key material cannot be serialised.');
    }

    public function __unserialize(array $data): void
    {
        throw new \LogicException('Key material cannot be unserialised.');
    }

    public function __debugInfo(): array
    {
        return ['bytes' => '[hidden]'];
    }

    public function __clone()
    {
        throw new \LogicException('Key material cannot be cloned.');
    }
}

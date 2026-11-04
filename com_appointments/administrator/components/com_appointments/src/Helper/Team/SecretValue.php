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
 * request. The bytes are not a property of the object: they live in a WeakMap
 * outside it, so var_dump(), print_r(), var_export(), a debugger's property
 * view and an exception's argument dump never show them. The object cannot be
 * serialised or cloned, and the bytes are overwritten when the request ends or
 * wipe() is called.
 *
 * @since  1.4.0
 */
abstract class SecretValue
{
    /**
     * @var  \WeakMap<SecretValue, string>|null
     */
    private static $store;

    protected function __construct(#[\SensitiveParameter] string $bytes)
    {
        self::$store ??= new \WeakMap();
        self::$store[$this] = $bytes;
    }

    /**
     * The raw bytes. Never write them anywhere; pass them to sodium only.
     *
     * @throws  \LogicException  After wipe().
     */
    protected function bytes(): string
    {
        $bytes = self::$store[$this] ?? '';

        if ($bytes === '') {
            throw new \LogicException('The key material has been wiped.');
        }

        return $bytes;
    }

    public function wipe(): void
    {
        if (self::$store !== null && isset(self::$store[$this])) {
            $bytes = self::$store[$this];

            if ($bytes !== '' && \function_exists('sodium_memzero')) {
                sodium_memzero($bytes);
            }

            unset(self::$store[$this]);
        }
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

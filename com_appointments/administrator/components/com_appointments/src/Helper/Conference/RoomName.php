<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Helper\Conference;

use Advans\Component\Appointments\Administrator\Helper\KeyProvider;

defined('_JEXEC') or die;

final class RoomName
{
    public const KEY_PURPOSE = 'conference-room';

    public function __construct(
        private KeyProvider $keys,
        private string $pattern = '{prefix}-{alias}-{random}',
        private string $prefix = 'meeting',
        private string $alias = 'event',
        private int $entropy = 24
    ) {
        if ($entropy < 16 || $entropy > 64
            || substr_count($pattern, '{random}') !== 1
            || preg_match('/[^A-Za-z0-9_-]/', strtr($pattern, [
                '{prefix}' => '', '{alias}' => '', '{random}' => '',
            ]))
            || !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $prefix)
            || !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $alias)
            || strlen(strtr($pattern, ['{prefix}' => $prefix, '{alias}' => $alias, '{random}' => str_repeat('a', $entropy)])) > 255
        ) {
            throw new ConferenceException('configuration');
        }
    }

    public function forMeeting(MeetingRequest $request): string
    {
        $random = substr(hash_hmac('sha256', $request->roomSeed, $this->keys->key(self::KEY_PURPOSE)), 0, $this->entropy);

        return strtr($this->pattern, [
            '{prefix}' => $this->prefix,
            '{alias}' => $this->alias,
            '{random}' => $random,
        ]);
    }
}

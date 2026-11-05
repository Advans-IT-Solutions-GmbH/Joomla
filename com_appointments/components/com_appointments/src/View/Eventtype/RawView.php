<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Site\View\Eventtype;

use Joomla\CMS\Factory;

defined('_JEXEC') or die;

/**
 * The date-and-time part of the booking page on its own (`format=raw`), for the
 * background update of the booking script: the same layout file and the same data as
 * the full page, so a template override of `default_picker.php` applies to both and
 * the script never renders dates or times itself. The address is the address of the
 * day or month link plus `format=raw`; without JavaScript that link opens the full page.
 * The time-zone note (`default_zone_note.php`) follows the part: in the full page it stands
 * outside of it, and it names the zone of the times just loaded.
 */
class RawView extends HtmlView
{
    public function display($tpl = null)
    {
        $this->prepare(false);
        Factory::getApplication()->setHeader('X-Robots-Tag', 'noindex, nofollow', true);

        echo $this->loadTemplate('picker') . $this->loadTemplate('zone_note');

        return null;
    }
}

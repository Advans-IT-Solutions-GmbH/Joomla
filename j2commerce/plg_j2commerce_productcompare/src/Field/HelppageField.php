<?php
/**
 * @package     J2Commerce Product Compare Plugin
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Plugin\J2Commerce\ProductCompare\Field;

use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The help page of the plugin's «Help» tab.
 *
 * The page is described in the manifest, inside the field element, so every
 * language key stays written out in full:
 *
 *     <field name="helppage" type="helppage" hiddenLabel="true">
 *         <section title="KEY">
 *             <p>KEY</p>
 *             <ul><li>KEY</li></ul>
 *             <ol><li>KEY</li></ol>
 *             <note>KEY</note>
 *         </section>
 *     </field>
 *
 * The field stores nothing. Every copy of this class in the repository is
 * identical apart from the namespace and the package line, which
 * shared/tests/help-lint.php enforces.
 */
class HelppageField extends FormField
{
    /**
     * @var string
     */
    protected $type = 'Helppage';

    /**
     * The page has its own headings, so the field has no label.
     */
    protected function getLabel()
    {
        return '';
    }

    /**
     * The sections as cards: a heading, then paragraphs, lists and notes in
     * manifest order. The texts come from the plugin's own language files.
     */
    protected function getInput()
    {
        $html = [];

        foreach ($this->element->section as $section) {
            $html[] = '<section class="card mb-3"><div class="card-body">';
            $html[] = '<h3 class="h4 card-title">' . Text::_((string) $section['title']) . '</h3>';

            foreach ($section->children() as $block) {
                $name = $block->getName();

                if ($name === 'p') {
                    $html[] = '<p>' . Text::_(trim((string) $block)) . '</p>';
                } elseif ($name === 'note') {
                    $html[] = '<div class="alert alert-info" role="note">' . Text::_(trim((string) $block)) . '</div>';
                } elseif ($name === 'ul' || $name === 'ol') {
                    $html[] = '<' . $name . '>';

                    foreach ($block->li as $item) {
                        $html[] = '<li>' . Text::_(trim((string) $item)) . '</li>';
                    }

                    $html[] = '</' . $name . '>';
                }
            }

            $html[] = '</div></section>';
        }

        return '<div class="advans-helppage">' . implode('', $html) . '</div>';
    }
}

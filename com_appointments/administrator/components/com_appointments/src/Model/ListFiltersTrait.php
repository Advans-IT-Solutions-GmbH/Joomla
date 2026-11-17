<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Model;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

defined('_JEXEC') or die;

/**
 * Joomla searchtools for the administrator lists: the filters arrive as `filter[<name>]`, the
 * sorting and the number per page as `list[...]`, the way the core lists do it.
 *
 * Every filter only narrows what the list query already allows the user to see: the permission
 * condition of the list is applied first and never depends on a filter value.
 *
 * @since  1.6.0
 */
trait ListFiltersTrait
{
    /**
     * The filter names of this list, in the order of the filter form.
     *
     * @return  string[]
     */
    abstract protected function filterNames(): array;

    /**
     * Links and scripts of earlier releases send a flat `filter_<name>`. It is moved into the
     * searchtools array before Joomla reads the request, so such a link keeps working. Call it
     * before `parent::populateState()`.
     */
    protected function adoptFlatFilters(): void
    {
        $input = Factory::getApplication()->getInput();
        $flat = [];

        foreach ($this->filterNames() as $name) {
            $value = $input->get('filter_' . $name, null, 'raw');

            if ($value !== null) {
                $flat[$name] = $value;
            }
        }

        if ($flat !== []) {
            $current = $input->get('filter', null, 'raw');
            $input->set('filter', array_replace(\is_array($current) ? $current : [], $flat));
        }
    }

    /**
     * The submitted value of a filter as a trimmed string, or null when it is not a single
     * value (an array or an object never is a filter value).
     */
    protected function rawFilter(string $name): ?string
    {
        $value = $this->state->get('filter.' . $name, '');

        if ($value === null) {
            return '';
        }

        return \is_scalar($value) ? trim((string) $value) : null;
    }

    /**
     * Refuses a forged filter value with 400. The stored filters are cleared first, so a forged
     * link does not leave the list stuck on the error.
     */
    protected function rejectFilters(string $messageKey): never
    {
        Factory::getApplication()->setUserState($this->context . '.filter', []);

        throw new \InvalidArgumentException(Text::_($messageKey), 400);
    }

    /**
     * A search text of at most `$max` characters of valid UTF-8, otherwise empty.
     */
    protected function textFilter(string $name, int $max = 200): string
    {
        $value = $this->rawFilter($name);

        if ($value === null || $value === '' || preg_match('//u', $value) !== 1 || mb_strlen($value) > $max) {
            return '';
        }

        return $value;
    }

    /**
     * A positive id of at most ten digits as a string, otherwise empty.
     */
    protected function idFilter(string $name): string
    {
        $value = $this->rawFilter($name);

        return $value !== null && ctype_digit($value) && \strlen($value) <= 10 && (int) $value > 0 ? (string) (int) $value : '';
    }

    /**
     * One of the allowed values, otherwise empty.
     *
     * @param   string[]  $allowed
     */
    protected function optionFilter(string $name, array $allowed): string
    {
        $value = $this->rawFilter($name);

        return $value !== null && \in_array($value, $allowed, true) ? $value : '';
    }

    /**
     * Active filters for the searchtools layout: those with a value, `0` included.
     *
     * @return  array<string, mixed>
     */
    public function getActiveFilters()
    {
        $active = [];

        foreach ($this->filterNames() as $name) {
            $value = $this->getState('filter.' . $name);

            if ((\is_string($value) && $value !== '') || \is_int($value)) {
                $active[$name] = $value;
            }
        }

        return $active;
    }

    /**
     * The ordering column of the list, checked against the sortable columns, and its direction.
     *
     * @param   string[]  $columns
     *
     * @return  array{0: string, 1: string}
     */
    protected function listOrdering(array $columns, string $default, string $defaultDirection): array
    {
        $order = (string) $this->getState('list.ordering', $default);
        $order = \in_array($order, $columns, true) ? $order : $default;
        $direction = strtoupper((string) $this->getState('list.direction', $defaultDirection));

        return [$order, \in_array($direction, ['ASC', 'DESC'], true) ? $direction : $defaultDirection];
    }
}

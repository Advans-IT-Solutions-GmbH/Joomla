<?php
/**
 * @copyright (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Installer\V2;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;

/**
 * The scheduled tasks an extension needs (tasks.php, named per extension in catalog.php).
 *
 * After every installation and update a required task that is missing is created, switched on,
 * with the defaults of the task plugin's own form, and reported in plain language. A task that
 * exists is never changed and never duplicated: one that is switched off or trashed was switched
 * off on purpose, so it is reported with the menu path to switch it on, not switched on. The same
 * inspection feeds the repeatable system check, together with whether Joomla's Scheduler can run.
 */
final class ScheduledTasks
{
    public const REQUIRED = 'required';
    public const OPTIONAL = 'optional';
    public const NOT_NEEDED = 'not-needed';

    public const MISSING = 'missing';
    public const ENABLED = 'enabled';
    /** Switched off (state 0) or trashed (state -2): in both cases someone decided it. */
    public const SWITCHED_OFF = 'switched-off';

    /** A run within this period proves that the Scheduler of this site is started. */
    private const RECENT_RUN_SECONDS = 2 * 86400;

    /** @var array<string, true> Routines handled in this request: a package and its children report once. */
    private static array $handled = [];

    /** @return array<string, array<string, mixed>> Routine ID => definition, for the tasks the extension checks. */
    public static function routines(string $id): array
    {
        $all = require __DIR__ . '/tasks.php';
        $routines = [];

        foreach (SystemCheck::definition($id)['tasks'] ?? [] as $routine) {
            if (!isset($all[$routine])) {
                throw new \InvalidArgumentException('Unknown scheduled task: ' . $routine);
            }

            $routines[$routine] = $all[$routine];
        }

        return $routines;
    }

    /**
     * The current state of every routine the extension checks.
     *
     * @return list<array{routine: string, spec: array, title: string, need: string, status: string, task: ?array, plugin: ?object}>
     */
    public static function inspect(string $id): array
    {
        $routines = self::routines($id);

        if ($routines === []) {
            return [];
        }

        $db = self::db();
        $table = in_array($db->getPrefix() . 'scheduler_tasks', $db->getTableList(), true);
        $rows = [];

        foreach ($routines as $routine => $spec) {
            self::loadLanguage($spec);
            $tasks = $table ? self::tasks($db, $routine) : [];
            $enabled = array_values(array_filter($tasks, static fn(array $task): bool => (int) $task['state'] === 1));
            $task = self::preferredTask($enabled !== [] ? $enabled : $tasks);
            $rows[] = [
                'routine' => $routine,
                'spec'    => $spec,
                'title'   => Text::_($spec['title']),
                'need'    => self::need($db, $spec['need']),
                'status'  => $tasks === [] ? self::MISSING : ($enabled !== [] ? self::ENABLED : self::SWITCHED_OFF),
                'task'    => $task,
                'plugin'  => self::extension($db, 'plugin', $spec['plugin'][1], $spec['plugin'][0]),
            ];
        }

        return $rows;
    }

    /**
     * Installation and update: create what is missing, report what is switched off, say so when
     * the Scheduler cannot start tasks. Never fails the installation.
     */
    public static function ensure(string $id): void
    {
        $app = Factory::getApplication();

        try {
            $rows = self::inspect($id);
            $required = false;

            foreach ($rows as $row) {
                if ($row['need'] !== self::REQUIRED) {
                    continue;
                }

                $required = true;

                if (isset(self::$handled[$row['routine']])) {
                    continue;
                }

                self::$handled[$row['routine']] = true;
                $title = self::escape($row['title']);
                $purpose = Text::_($row['spec']['purpose']);

                if ($row['plugin'] === null) {
                    $app->enqueueMessage(Text::sprintf('ADVANS_TASKS_PLUGIN_MISSING', $title, $purpose), 'warning');
                    continue;
                }

                if ($row['status'] === self::MISSING) {
                    try {
                        $params = self::create($row);
                        $message = Text::sprintf('ADVANS_TASKS_CREATED', $title, self::escape(self::schedule($row['spec']['rules'])), $purpose);

                        if (isset($row['spec']['settings'])) {
                            [$key, $names] = $row['spec']['settings'];
                            $message .= ' ' . Text::sprintf($key, ...array_map(static fn(string $name): string => self::escape((string) ($params[$name] ?? '')), $names));
                        }

                        $app->enqueueMessage($message, 'message');
                    } catch (\Throwable $error) {
                        Log::add('Cannot create the scheduled task ' . $row['routine'] . ': ' . $error->getMessage(), Log::WARNING, 'advans-installer');
                        $app->enqueueMessage(Text::sprintf('ADVANS_TASKS_CREATE_FAILED', $title, $purpose), 'warning');
                    }
                } elseif ($row['status'] === self::SWITCHED_OFF) {
                    $app->enqueueMessage(Text::sprintf('ADVANS_TASKS_SWITCHED_OFF', $title, $purpose), 'warning');
                }

                // A task plugin that is itself the extension being installed asks to be enabled in
                // its own message; the system check still lists the task as unable to run.
                if ((int) $row['plugin']->enabled !== 1 && !self::isExtension($id, $row['spec']['plugin'])) {
                    $app->enqueueMessage(Text::sprintf('ADVANS_TASKS_PLUGIN_DISABLED', $title), 'warning');
                }
            }

            if ($required && !isset(self::$handled['#trigger'])) {
                self::$handled['#trigger'] = true;
                $trigger = self::trigger($rows);

                if (!$trigger['proven']) {
                    $app->enqueueMessage(self::escape($trigger['text']), $trigger['runs'] ? 'message' : 'warning');
                }
            }
        } catch (\Throwable $error) {
            Log::add('Cannot check the scheduled tasks of ' . $id . ': ' . $error->getMessage(), Log::WARNING, 'advans-installer');
            $app->enqueueMessage(Text::_('ADVANS_TASKS_CHECK_FAILED'), 'warning');
        }
    }

    /**
     * Whether Joomla's Scheduler starts tasks on this site. A recent run of one of the extension's
     * tasks proves it; otherwise the configuration decides: a web trigger (Lazy Scheduler, Web
     * Cron) is not proof that it fires, and without one only a server cron job could start tasks.
     *
     * @param list<array<string, mixed>> $rows Result of inspect() for the extension.
     * @return array{runs: bool, proven: bool, text: string}
     */
    public static function trigger(array $rows = []): array
    {
        $latest = '';

        foreach ($rows as $row) {
            $task = $row['task'];

            if ($row['status'] === self::ENABLED && (int) ($task['times_executed'] ?? 0) > 0 && (string) ($task['last_execution'] ?? '') > $latest) {
                $latest = (string) $task['last_execution'];
            }
        }

        if ($latest !== '' && strtotime($latest . ' UTC') >= time() - self::RECENT_RUN_SECONDS) {
            return ['runs' => true, 'proven' => true, 'text' => Text::sprintf('ADVANS_TASKS_TRIGGER_RAN', $latest)];
        }

        $db = self::db();
        $scheduler = self::extension($db, 'component', 'com_scheduler');

        if ($scheduler === null || (int) $scheduler->enabled !== 1) {
            return ['runs' => false, 'proven' => false, 'text' => Text::_('ADVANS_TASKS_TRIGGER_OFF')];
        }

        $runner = self::extension($db, 'plugin', 'schedulerunner', 'system');

        if ($runner === null || (int) $runner->enabled !== 1) {
            return ['runs' => false, 'proven' => false, 'text' => Text::_('ADVANS_TASKS_TRIGGER_RUNNER_OFF')];
        }

        $params = self::params($db, 'component', 'com_scheduler');

        if ((int) ($params['webcron']['enabled'] ?? 0) === 1) {
            return ['runs' => true, 'proven' => false, 'text' => Text::_('ADVANS_TASKS_TRIGGER_WEBCRON')];
        }

        if ((int) ($params['lazy_scheduler']['enabled'] ?? 1) === 1) {
            return ['runs' => true, 'proven' => false, 'text' => Text::_('ADVANS_TASKS_TRIGGER_LAZY')];
        }

        return ['runs' => false, 'proven' => false, 'text' => Text::_('ADVANS_TASKS_TRIGGER_CLI_ONLY')];
    }

    /** Plain-language schedule of execution rules, for example "every day at 02:15 UTC". */
    public static function schedule(array $rules): string
    {
        switch ($rules['rule-type']) {
            case 'interval-minutes':
                return Text::sprintf('ADVANS_TASKS_EVERY_MINUTES', (int) $rules['interval-minutes']);
            case 'interval-hours':
                $hours = (int) $rules['interval-hours'];

                return $hours === 1 ? Text::_('ADVANS_TASKS_EVERY_HOUR') : ($hours === 24 ? Text::_('ADVANS_TASKS_DAILY') : Text::sprintf('ADVANS_TASKS_EVERY_HOURS', $hours));
            case 'interval-days':
                return Text::sprintf('ADVANS_TASKS_DAILY_AT', (string) $rules['exec-time']);
        }

        throw new \InvalidArgumentException('Unsupported execution rule.');
    }

    /**
     * Create the task through the Scheduler's own model, exactly like a task saved in the backend.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed> The parameters of the created task.
     */
    private static function create(array $row): array
    {
        $spec = $row['spec'];
        $db = self::db();
        $params = self::formDefaults(JPATH_PLUGINS . '/' . $spec['plugin'][0] . '/' . $spec['plugin'][1] . '/' . ($spec['form'] ?? ''));

        if (isset($spec['inherit'])) {
            $stored = self::params($db, $spec['inherit'][0], $spec['inherit'][2], $spec['inherit'][1]);
            $names = $spec['inherit'][3] ?? array_keys($params);

            foreach ($names as $name) {
                if (!array_key_exists($name, $params)) {
                    continue;
                }
                if (isset($stored[$name]) && is_scalar($stored[$name]) && trim((string) $stored[$name]) !== '') {
                    $params[$name] = is_int($params[$name]) ? (int) $stored[$name] : trim((string) $stored[$name]);
                }
            }
        }

        $app = Factory::getApplication();
        $model = $app->bootComponent('com_scheduler')->getMVCFactory()
            ->createModel('Task', 'Administrator', ['ignore_request' => true]);
        // On the command line nobody is signed in: the guest user (id 0) creates the task, set
        // explicitly so that neither the model nor its table has to guess.
        $identity = $app->getIdentity()
            ?? Factory::getContainer()->get(\Joomla\CMS\User\UserFactoryInterface::class)->loadUserById(0);

        if ($model instanceof \Joomla\CMS\User\CurrentUserInterface) {
            $model->setCurrentUser($identity);
        }

        $rules = $spec['rules'] + ['exec-day' => gmdate('d'), 'exec-time' => gmdate('H:i')];
        $saved = $model && $model->save([
            'title'           => Text::_($spec['title']),
            'type'            => $row['routine'],
            'execution_rules' => $rules,
            'state'           => 1,
            'params'          => $params,
            'created_by'      => (int) ($identity->id ?? 0),
        ]);

        if (!$saved) {
            throw new \RuntimeException('The Scheduler did not save the task.');
        }

        return $params;
    }

    /** Prefer a task that has run, then the most recent execution, for duplicate routine rows. */
    private static function preferredTask(array $tasks): ?array
    {
        if ($tasks === []) {
            return null;
        }

        usort($tasks, static function (array $left, array $right): int {
            $leftRuns = (int) ($left['times_executed'] ?? 0);
            $rightRuns = (int) ($right['times_executed'] ?? 0);

            if ($leftRuns > 0 !== $rightRuns > 0) {
                return $leftRuns > 0 ? -1 : 1;
            }

            $execution = strcmp((string) ($right['last_execution'] ?? ''), (string) ($left['last_execution'] ?? ''));

            return $execution !== 0 ? $execution : ((int) ($right['id'] ?? 0) <=> (int) ($left['id'] ?? 0));
        });

        return $tasks[0];
    }

    /**
     * @param string|array $need
     */
    private static function need(object $db, $need): string
    {
        if (!is_array($need)) {
            return $need;
        }

        [$component, $option, $test, $default] = $need['when'];
        $value = self::params($db, 'component', $component)[$option] ?? $default;
        $value = is_scalar($value) ? trim((string) $value) : '';
        $met = $test === 'on' ? (int) $value === 1 : trim($value, " ,") !== '';

        return $met ? self::REQUIRED : self::NOT_NEEDED;
    }

    /** @return array<string, int|string> Field defaults of the task plugin's form, as the form would store them. */
    private static function formDefaults(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }

        $form = simplexml_load_file($file);

        if (!$form instanceof \SimpleXMLElement) {
            throw new \RuntimeException('Cannot read the task form.');
        }

        $defaults = [];

        foreach ($form->xpath('//fields[@name="params"]//field[@name][@default]') ?: [] as $field) {
            $value = (string) $field['default'];
            $defaults[(string) $field['name']] = in_array((string) $field['filter'], ['int', 'integer'], true) ? (int) $value : $value;
        }

        return $defaults;
    }

    /** @return list<array<string, mixed>> */
    private static function tasks(object $db, string $routine): array
    {
        $query = self::query($db)
            ->select($db->quoteName(['id', 'state', 'last_execution', 'last_exit_code', 'times_executed']))
            ->from($db->quoteName('#__scheduler_tasks'))
            ->where($db->quoteName('type') . ' = ' . $db->quote($routine))
            ->order($db->quoteName('id') . ' ASC');

        return $db->setQuery($query)->loadAssocList() ?: [];
    }

    private static function extension(object $db, string $type, string $element, ?string $folder = null): ?object
    {
        $query = self::query($db)
            ->select($db->quoteName(['extension_id', 'enabled']))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote($type))
            ->where($db->quoteName('element') . ' = ' . $db->quote($element));

        if ($folder !== null) {
            $query->where($db->quoteName('folder') . ' = ' . $db->quote($folder));
        }

        return $db->setQuery($query)->loadObject() ?: null;
    }

    /** @return array<string, mixed> Stored options of an extension (read from the database, not a cache). */
    private static function params(object $db, string $type, string $element, ?string $folder = null): array
    {
        $query = self::query($db)
            ->select($db->quoteName('params'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote($type))
            ->where($db->quoteName('element') . ' = ' . $db->quote($element));

        if ($folder !== null) {
            $query->where($db->quoteName('folder') . ' = ' . $db->quote($folder));
        }

        $params = json_decode((string) $db->setQuery($query)->loadResult(), true);

        return is_array($params) ? $params : [];
    }

    /** The task plugin's language file holds the routine title. */
    private static function loadLanguage(array $spec): void
    {
        $language = Factory::getApplication()->getLanguage();

        foreach ([JPATH_ADMINISTRATOR, JPATH_PLUGINS . '/' . $spec['plugin'][0] . '/' . $spec['plugin'][1]] as $root) {
            if (glob($root . '/language/*/' . $spec['language'] . '.ini') ?: []) {
                $language->load($spec['language'], $root);
            }
        }
    }

    /** @param array{0: string, 1: string} $plugin [group, element] */
    private static function isExtension(string $id, array $plugin): bool
    {
        $definition = SystemCheck::definition($id);

        return $definition['type'] === 'plugin' && ($definition['group'] ?? '') === $plugin[0] && $definition['element'] === $plugin[1];
    }

    private static function db(): object
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }

    private static function query(object $db): object
    {
        return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

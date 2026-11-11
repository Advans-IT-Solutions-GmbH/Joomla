<?php

/*
 * Site menu items for the suites.
 *
 * The public pages of the component open only through a published menu item of their type
 * (Site\Dispatcher\Dispatcher, MenuViews), as on a real site. A suite that opens a booking page,
 * the list of event types or «My calendars» therefore goes through such a menu item:
 *
 * - appointments_menu_item($link) returns a published public site menu item in "All" languages with
 *   exactly that link, an existing one or a new row (inserted like a menu item saved without a
 *   position in the tree, which the site menu loads all the same); with $reuse = false always a new
 *   row that belongs to the caller, so the caller may change or remove it;
 * - appointments_page($event, $query) returns the non-SEF address of the booking page through it.
 *
 * Rows this helper inserts are removed when the script ends, unless $keep is set by a fixture that
 * outlives its process and removes the row itself. The database connection opens on first use, so
 * a script may load configuration.php itself before or after including this file.
 */

/** @return array{0: mysqli, 1: string} */
function appointments_menu_db(): array
{
    static $connection = null;

    if ($connection === null) {
        if (!class_exists('JConfig', false)) {
            require_once '/var/www/html/configuration.php';
        }

        $config = new JConfig();
        $connection = [new mysqli($config->host, $config->user, $config->password, $config->db), $config->dbprefix];
    }

    return $connection;
}

function appointments_menu_item(string $link, bool $keep = false, bool $reuse = true): int
{
    [$db, $prefix] = appointments_menu_db();
    $escaped = $db->real_escape_string($link);
    $row = !$reuse ? null : $db->query("SELECT id FROM {$prefix}menu WHERE client_id=0 AND published=1 AND type='component' AND access=1"
        . " AND language='*' AND link='{$escaped}' ORDER BY id LIMIT 1")->fetch_row();

    if ($row) {
        return (int) $row[0];
    }

    $component = (int) $db->query("SELECT extension_id FROM {$prefix}extensions WHERE type='component' AND element='com_appointments'")->fetch_row()[0];
    $menutype = $db->real_escape_string((string) ($db->query("SELECT menutype FROM {$prefix}menu WHERE client_id=0 AND home=1 ORDER BY id LIMIT 1")->fetch_row()[0] ?? 'mainmenu'));
    $alias = 'appointments-suite-' . bin2hex(random_bytes(5));
    $db->query("INSERT INTO {$prefix}menu (menutype,title,alias,note,path,link,type,published,parent_id,level,component_id,browserNav,access,img,template_style_id,params,lft,rgt,home,language,client_id)"
        . " VALUES ('{$menutype}','{$alias}','{$alias}','','{$alias}','{$escaped}','component',1,1,1,{$component},0,1,'',0,'{}',0,0,0,'*',0)");
    $id = (int) $db->insert_id;

    if ($id < 1) {
        throw new RuntimeException('The menu item for ' . $link . ' could not be created.');
    }

    if (!$keep) {
        register_shutdown_function(static function () use ($id): void {
            [$db, $prefix] = appointments_menu_db();
            $db->query("DELETE FROM {$prefix}menu WHERE id={$id}");
        });
    }

    return $id;
}

/**
 * Non-SEF address of the booking page of $event through its "Book an appointment" menu item, with
 * further parameters ("&timezone=UTC&start=…").
 */
function appointments_page(int $event, string $query = ''): string
{
    $link = 'index.php?option=com_appointments&view=eventtype&id=' . $event;

    return '/' . $link . '&Itemid=' . appointments_menu_item($link) . $query;
}

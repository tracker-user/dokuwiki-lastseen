<?php
/**
 * Last Seen plugin — admin panel page.
 *
 * Lists every registered user with the time of their last authenticated
 * activity. Appears in the Admin panel right after the User Manager.
 */

class admin_plugin_lastseen extends DokuWiki_Admin_Plugin
{
    /** Admin-only — last-seen data is mildly sensitive activity information. */
    public function forAdminOnly()
    {
        return true;
    }

    /** Position in the admin menu. */
    public function getMenuSort()
    {
        return 1000;
    }

    public function getMenuText($language)
    {
        return $this->getLang('menu');
    }

    /** Read-only page — no form submissions to process. */
    public function handle()
    {
    }

    /**
     * Render the admin page.
     */
    public function html()
    {
        global $auth, $INPUT, $ID;

        echo '<h1>' . hsc($this->getLang('menu')) . '</h1>';

        /** @var helper_plugin_lastseen $hlp */
        $hlp = plugin_load('helper', 'lastseen');
        if ($hlp === null) {
            echo '<div class="error">Helper component could not be loaded.</div>';
            return;
        }

        // Some auth backends (certain LDAP/AD setups) cannot enumerate users.
        // authplain can; degrade gracefully for the rest.
        if (!$auth || !$auth->canDo('getUsers')) {
            echo '<div class="error">' . hsc($this->getLang('no_userlist')) . '</div>';
            return;
        }

        // retrieveUsers(0, 0): start at 0, limit 0 == all users.
        // Returns [username => ['name' => ..., 'mail' => ..., 'grps' => []]].
        $users = $auth->retrieveUsers(0, 0);
        $seen  = $hlp->getAll();

        // ---- sorting -------------------------------------------------
        $sortable = ['login', 'name', 'grps', 'lastseen'];
        $sort  = $INPUT->str('sort', 'lastseen');
        if (!in_array($sort, $sortable, true)) {
            $sort = 'lastseen';
        }
        $order = ($INPUT->str('order', 'desc') === 'asc') ? 'asc' : 'desc';

        $rows = [];
        foreach ($users as $login => $info) {
            $rows[] = [
                'login'    => $login,
                'name'     => $info['name'] ?? '',
                'grps'     => isset($info['grps']) ? implode(', ', (array) $info['grps']) : '',
                'lastseen' => isset($seen[$login]) ? (int) $seen[$login] : 0, // 0 == never
            ];
        }

        usort($rows, function ($a, $b) use ($sort, $order) {
            switch ($sort) {
                case 'login':
                    $cmp = strcasecmp($a['login'], $b['login']);
                    break;
                case 'name':
                    $cmp = strcasecmp($a['name'], $b['name']);
                    break;
                case 'grps':
                    $cmp = strcasecmp($a['grps'], $b['grps']);
                    break;
                case 'lastseen':
                default:
                    $cmp = $a['lastseen'] <=> $b['lastseen'];
                    break;
            }
            return ($order === 'asc') ? $cmp : -$cmp;
        });

        $showNever = (bool) $this->getConf('show_never');

        // ---- render --------------------------------------------------
        echo '<p>' . hsc($this->getLang('intro')) . '</p>';
        echo '<div class="table">';
        echo '<table class="inline plugin_lastseen">';
        echo '<thead><tr>';
        $this->headerCell('login',    $this->getLang('col_login'),    $sort, $order, $ID);
        $this->headerCell('name',     $this->getLang('col_name'),     $sort, $order, $ID);
        $this->headerCell('grps',     $this->getLang('col_grps'),     $sort, $order, $ID);
        $this->headerCell('lastseen', $this->getLang('col_lastseen'), $sort, $order, $ID);
        echo '</tr></thead><tbody>';

        $count = 0;
        foreach ($rows as $row) {
            if ($row['lastseen'] === 0 && !$showNever) {
                continue;
            }
            $count++;
            echo '<tr>';
            echo '<td>' . hsc($row['login']) . '</td>';
            echo '<td>' . hsc($row['name']) . '</td>';
            echo '<td>' . hsc($row['grps']) . '</td>';
            if ($row['lastseen'] === 0) {
                echo '<td class="lastseen_never">' . hsc($this->getLang('never')) . '</td>';
            } else {
                echo '<td>' . hsc(dformat($row['lastseen']))
                    . ' <span class="lastseen_rel">('
                    . hsc($this->relativeTime($row['lastseen'])) . ')</span></td>';
            }
            echo '</tr>';
        }

        echo '</tbody></table></div>';
        echo '<p class="lastseen_count">' . sprintf($this->getLang('total'), $count) . '</p>';
    }

    /**
     * Emit a sortable column header. Clicking a header sorts by that column;
     * clicking the already-active column flips the direction.
     *
     * @param string $key   column key
     * @param string $label visible header text
     * @param string $sort  currently active sort column
     * @param string $order currently active order (asc|desc)
     * @param string $id    current page id (for the link target)
     */
    protected function headerCell($key, $label, $sort, $order, $id)
    {
        // If this column is already active, clicking flips the order;
        // otherwise a fresh column starts ascending.
        $newOrder = ($sort === $key && $order === 'asc') ? 'desc' : 'asc';

        $arrow = '';
        if ($sort === $key) {
            // ▲ U+25B2 / ▼ U+25BC as HTML entities (concatenated raw, not hsc'd)
            $arrow = ($order === 'asc') ? ' &#9650;' : ' &#9660;';
        }

        // wl() already returns an HTML-safe URL — its default separator is the
        // pre-encoded "&amp;". It must NOT be passed through hsc(): doing so
        // double-encodes the ampersands ("&amp;" -> "&amp;amp;"), the browser
        // then navigates to a URL containing a literal "&amp;", and the query
        // parameters arrive mis-named ("amp;sort" instead of "sort") — which
        // silently breaks sorting. The label, being plain text, IS hsc()'d.
        $url = wl($id, [
            'do'    => 'admin',
            'page'  => 'lastseen',
            'sort'  => $key,
            'order' => $newOrder,
        ]);

        echo '<th><a href="' . $url . '">' . hsc($label) . $arrow . '</a></th>';
    }

    /**
     * Human-readable "time ago" string for a timestamp.
     *
     * @param int $timestamp
     * @return string
     */
    protected function relativeTime($timestamp)
    {
        $diff = time() - $timestamp;
        if ($diff < 0) {
            $diff = 0;
        }

        if ($diff < 60) {
            return $this->getLang('rel_now');
        }
        if ($diff < 3600) {
            return sprintf($this->getLang('rel_minutes'), (int) floor($diff / 60));
        }
        if ($diff < 86400) {
            return sprintf($this->getLang('rel_hours'), (int) floor($diff / 3600));
        }
        if ($diff < 86400 * 30) {
            return sprintf($this->getLang('rel_days'), (int) floor($diff / 86400));
        }
        if ($diff < 86400 * 365) {
            return sprintf($this->getLang('rel_months'), (int) floor($diff / (86400 * 30)));
        }
        return sprintf($this->getLang('rel_years'), (int) floor($diff / (86400 * 365)));
    }
}

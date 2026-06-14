<?php

if (!defined('DOKU_INC')) die();

use dokuwiki\Extension\AdminPlugin;
use dokuwiki\Utf8\PhpString;

/**
 * Last Seen plugin — admin panel page.
 *
 * Lists every registered user with the time of their last authenticated
 * activity. Appears in the Admin panel right after the User Manager.
 *
 * The table is sortable (any column), filterable (a per-column text-filter
 * row, substring and case-insensitive, like the User Manager but JS-free) and
 * paginated with numbered page links. Which columns appear and how many rows
 * fill a page are configurable.
 */

class admin_plugin_lastseen extends AdminPlugin
{
    /** @var string[] columns that may be sorted (subject to visibility) */
    protected $sortable = ['login', 'name', 'mail', 'grps', 'lastseen'];

    /**
     * Admin-only — last-seen data is mildly sensitive activity information.
     *
     * @return bool
     */
    public function forAdminOnly()
    {
        return true;
    }

    /**
     * Position in the admin menu.
     *
     * @return int
     */
    public function getMenuSort()
    {
        return 1000;
    }

    /**
     * @param string $language
     * @return string
     */
    public function getMenuText($language)
    {
        return $this->getLang('menu');
    }

    /**
     * Read-only page — no form submissions to process.
     *
     * @return void
     */
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
            echo '<div class="error">' . hsc($this->getLang('helper_missing')) . '</div>';
            return;
        }

        // Some auth backends (certain LDAP/AD setups) cannot enumerate users.
        // authplain can; degrade gracefully for the rest.
        if (!$auth || !$auth->canDo('getUsers')) {
            echo '<div class="error">' . hsc($this->getLang('no_userlist')) . '</div>';
            return;
        }

        $showMail  = (bool) $this->getConf('show_mail');
        $showGrps  = (bool) $this->getConf('show_grps');
        $showNever = (bool) $this->getConf('show_never');
        $perPage   = (int) $this->getConf('entries_per_page');

        // visible columns, in display order
        $cols = ['login', 'name'];
        if ($showMail) {
            $cols[] = 'mail';
        }
        if ($showGrps) {
            $cols[] = 'grps';
        }
        $cols[] = 'lastseen';

        // every visible column except "lastseen" is text-filterable
        $filterCols = array_values(array_filter($cols, static function ($c) {
            return $c !== 'lastseen';
        }));

        // ---- request parameters --------------------------------------
        $sort = $INPUT->str('sort', 'lastseen');
        if (!in_array($sort, $this->sortable, true)) {
            $sort = 'lastseen';
        }
        // never sort by a hidden column
        if (($sort === 'mail' && !$showMail) || ($sort === 'grps' && !$showGrps)) {
            $sort = 'lastseen';
        }
        $order   = ($INPUT->str('order', 'desc') === 'asc') ? 'asc' : 'desc';
        $filters = $this->activeFilters($filterCols);

        // ---- data ----------------------------------------------------
        // retrieveUsers(0, 0): start at 0, limit 0 == all users.
        // Returns [username => ['name' => ..., 'mail' => ..., 'grps' => []]].
        $users = $auth->retrieveUsers(0, 0);
        $seen  = $hlp->getAll();

        $rows = [];
        foreach ($users as $login => $info) {
            $rows[] = [
                'login'    => $login,
                'name'     => $info['name'] ?? '',
                'mail'     => $info['mail'] ?? '',
                'grps'     => isset($info['grps']) ? implode(', ', (array) $info['grps']) : '',
                'lastseen' => isset($seen[$login]) ? (int) $seen[$login] : 0, // 0 == never
            ];
        }

        // "never seen" rows are dropped before filtering/paging so the counts
        // and page numbers reflect what is actually shown.
        if (!$showNever) {
            $rows = array_values(array_filter($rows, static function ($r) {
                return $r['lastseen'] !== 0;
            }));
        }

        $rows  = $this->applyFilters($rows, $filters);
        $rows  = $this->sortRows($rows, $sort, $order);
        $total = count($rows);

        [$pageRows, $page, $totalPages, $from, $to] = $this->paginate($rows, $perPage);

        // ---- render --------------------------------------------------
        echo '<p>' . hsc($this->getLang('intro')) . '</p>';

        $labels = [
            'login'    => $this->getLang('col_login'),
            'name'     => $this->getLang('col_name'),
            'mail'     => $this->getLang('col_mail'),
            'grps'     => $this->getLang('col_grps'),
            'lastseen' => $this->getLang('col_lastseen'),
        ];

        // GET form so the filter combines with sort links and bookmarks cleanly.
        // The action URL's query string is dropped on submit, so every standing
        // parameter travels as an explicit hidden field.
        echo '<form class="lastseen_filter" method="get" action="' . DOKU_BASE . DOKU_SCRIPT . '">';
        echo '<input type="hidden" name="id" value="' . hsc($ID) . '" />';
        echo '<input type="hidden" name="do" value="admin" />';
        echo '<input type="hidden" name="page" value="lastseen" />';
        echo '<input type="hidden" name="sort" value="' . hsc($sort) . '" />';
        echo '<input type="hidden" name="order" value="' . hsc($order) . '" />';
        echo '<input type="hidden" name="pg" value="1" />'; // a new search lands on page 1

        echo '<div class="table">';
        echo '<table class="inline plugin_lastseen">';
        echo '<thead>';
        echo '<tr>';
        foreach ($cols as $c) {
            echo $this->headerCell($c, $labels[$c], $sort, $order, $filters, $ID);
        }
        echo '</tr>';
        echo $this->renderFilterRow($cols, $filterCols, $filters, $sort, $order, $ID);
        echo '</thead>';
        echo '<tbody>';

        if ($total === 0) {
            echo '<tr><td colspan="' . count($cols) . '" class="lastseen_none">'
               . hsc($this->getLang('none')) . '</td></tr>';
        } else {
            foreach ($pageRows as $row) {
                echo '<tr>';
                foreach ($cols as $c) {
                    if ($c !== 'lastseen') {
                        echo '<td>' . hsc($row[$c]) . '</td>';
                    } elseif ($row['lastseen'] === 0) {
                        echo '<td class="lastseen_never">' . hsc($this->getLang('never')) . '</td>';
                    } else {
                        echo '<td>' . hsc(dformat($row['lastseen']))
                            . ' <span class="lastseen_rel">('
                            . hsc($this->relativeTime($row['lastseen'])) . ')</span></td>';
                    }
                }
                echo '</tr>';
            }
        }

        echo '</tbody></table></div>';
        echo '</form>';

        echo $this->renderPager($page, $totalPages, $sort, $order, $filters, $ID);

        if ($total > 0) {
            echo '<p class="lastseen_count">'
               . hsc(sprintf($this->getLang('shown'), $from, $to, $total)) . '</p>';
        }
    }

    // ---------------------------------------------------------------------
    //  Filtering
    // ---------------------------------------------------------------------

    /**
     * Read the active text filters from the request (the q[] array), keeping
     * only the filterable columns and dropping blanks.
     *
     * @param string[] $filterCols column keys that accept a text filter
     * @return array [column => trimmed search term]
     */
    protected function activeFilters(array $filterCols)
    {
        global $INPUT;
        $raw = $INPUT->arr('q');
        $out = [];
        foreach ($filterCols as $c) {
            if (isset($raw[$c]) && is_string($raw[$c])) {
                $term = trim($raw[$c]);
                if ($term !== '') {
                    $out[$c] = $term;
                }
            }
        }
        return $out;
    }

    /**
     * Keep only rows that match every active filter (substring, case-insensitive).
     *
     * @param array $rows
     * @param array $filters [column => term]
     * @return array
     */
    protected function applyFilters(array $rows, array $filters)
    {
        if ($filters === []) {
            return $rows;
        }
        return array_values(array_filter($rows, function ($row) use ($filters) {
            foreach ($filters as $col => $term) {
                if (!$this->matches($row[$col] ?? '', $term)) {
                    return false;
                }
            }
            return true;
        }));
    }

    /**
     * Case-insensitive UTF-8 substring test.
     *
     * @param string $haystack
     * @param string $needle
     * @return bool
     */
    protected function matches($haystack, $needle)
    {
        if ($needle === '') {
            return true;
        }
        $h = PhpString::strtolower((string) $haystack);
        $n = PhpString::strtolower((string) $needle);
        return PhpString::strpos($h, $n) !== false;
    }

    // ---------------------------------------------------------------------
    //  Sorting & pagination
    // ---------------------------------------------------------------------

    /**
     * Sort rows by the given column and direction.
     *
     * @param array  $rows
     * @param string $sort  column key
     * @param string $order 'asc' or 'desc'
     * @return array
     */
    protected function sortRows(array $rows, $sort, $order)
    {
        usort($rows, static function ($a, $b) use ($sort) {
            if ($sort === 'lastseen') {
                return $a['lastseen'] <=> $b['lastseen'];
            }
            return strcasecmp((string) ($a[$sort] ?? ''), (string) ($b[$sort] ?? ''));
        });
        if ($order === 'desc') {
            $rows = array_reverse($rows);
        }
        return $rows;
    }

    /**
     * Slice the rows for the current page.
     *
     * @param array $rows    all rows (already filtered + sorted)
     * @param int   $perPage rows per page; <= 0 means "all on one page"
     * @return array [pageRows, page, totalPages, from, to] — from/to are 1-based
     *               row numbers of the slice (0 when there are no rows)
     */
    protected function paginate(array $rows, $perPage)
    {
        global $INPUT;
        $total = count($rows);

        if ($perPage <= 0) {
            return [$rows, 1, 1, $total > 0 ? 1 : 0, $total];
        }

        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = $INPUT->int('pg', 1);
        if ($page < 1) {
            $page = 1;
        }
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $offset = ($page - 1) * $perPage;
        $slice  = array_slice($rows, $offset, $perPage);
        $from   = $total > 0 ? $offset + 1 : 0;
        $to     = min($total, $offset + $perPage);

        return [$slice, $page, $totalPages, $from, $to];
    }

    // ---------------------------------------------------------------------
    //  Rendering helpers
    // ---------------------------------------------------------------------

    /**
     * Build the standing parameter set for an in-table link, with $overrides
     * applied last. The active filters travel as the q[] array.
     *
     * @param array $overrides
     * @param array $filters
     * @return array
     */
    protected function linkParams(array $overrides, array $filters)
    {
        $params = ['do' => 'admin', 'page' => 'lastseen'];
        if ($filters !== []) {
            $params['q'] = $filters;
        }
        return array_merge($params, $overrides);
    }

    /**
     * Emit a sortable column header. Clicking a header sorts by that column;
     * clicking the already-active column flips the direction. The current
     * filter is preserved and the page resets to 1.
     *
     * @param string $key     column key
     * @param string $label   visible header text
     * @param string $sort    currently active sort column
     * @param string $order   currently active order (asc|desc)
     * @param array  $filters active filters (preserved in the link)
     * @param string $id      current page id (for the link target)
     * @return string
     */
    protected function headerCell($key, $label, $sort, $order, array $filters, $id)
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
        // parameters arrive mis-named ("amp;sort" instead of "sort"). The label,
        // being plain text, IS hsc()'d.
        $url = wl($id, $this->linkParams(['sort' => $key, 'order' => $newOrder], $filters));

        return '<th><a href="' . $url . '">' . hsc($label) . $arrow . '</a></th>';
    }

    /**
     * Emit the per-column text-filter row: a text input under each filterable
     * column, and the Search/Clear controls in the (non-filterable) last-seen
     * cell.
     *
     * @param string[] $cols       visible columns in order
     * @param string[] $filterCols columns that accept a text filter
     * @param array    $filters    active filters
     * @param string   $sort
     * @param string   $order
     * @param string   $id
     * @return string
     */
    protected function renderFilterRow(array $cols, array $filterCols, array $filters, $sort, $order, $id)
    {
        $html = '<tr class="lastseen_filterrow">';
        foreach ($cols as $c) {
            if (in_array($c, $filterCols, true)) {
                $val = isset($filters[$c]) ? hsc($filters[$c]) : '';
                $html .= '<td><input type="text" name="q[' . hsc($c) . ']" class="edit" value="'
                       . $val . '" /></td>';
            } else {
                // the last-seen column carries the action controls
                $html .= '<td class="lastseen_filteractions">';
                $html .= '<button type="submit" class="button">'
                       . hsc($this->getLang('filter_search')) . '</button>';
                if ($filters !== []) {
                    $clear = wl($id, $this->linkParams(['sort' => $sort, 'order' => $order], []));
                    $html .= ' <a class="lastseen_clear" href="' . $clear . '">'
                           . hsc($this->getLang('filter_clear')) . '</a>';
                }
                $html .= '</td>';
            }
        }
        return $html . '</tr>';
    }

    /**
     * Render the numbered pager: « prev  1 … 4 [5] 6 … 20  next ». Returns the
     * empty string when there is only one page.
     *
     * @param int    $page
     * @param int    $totalPages
     * @param string $sort
     * @param string $order
     * @param array  $filters
     * @param string $id
     * @return string
     */
    protected function renderPager($page, $totalPages, $sort, $order, array $filters, $id)
    {
        if ($totalPages <= 1) {
            return '';
        }

        $html = '<nav class="lastseen_pager" aria-label="' . hsc($this->getLang('pager_label')) . '">';

        if ($page > 1) {
            $html .= $this->pagerLink($id, $page - 1, $sort, $order, $filters, '&#8249;', 'pager_prev');
        } else {
            $html .= '<span class="pager_btn pager_disabled">&#8249;</span>';
        }

        foreach ($this->pageWindow($page, $totalPages) as $p) {
            if ($p === 0) {
                $html .= '<span class="pager_gap">&#8230;</span>';
            } elseif ($p === $page) {
                $html .= '<span class="pager_cur">' . $p . '</span>';
            } else {
                $html .= $this->pagerLink($id, $p, $sort, $order, $filters, (string) $p, '');
            }
        }

        if ($page < $totalPages) {
            $html .= $this->pagerLink($id, $page + 1, $sort, $order, $filters, '&#8250;', 'pager_next');
        } else {
            $html .= '<span class="pager_btn pager_disabled">&#8250;</span>';
        }

        return $html . '</nav>';
    }

    /**
     * One pager link (number or arrow), preserving sort + filter.
     *
     * @param string $id
     * @param int    $p        target page
     * @param string $sort
     * @param string $order
     * @param array  $filters
     * @param string $text     already-safe link text (number or entity)
     * @param string $titleKey lang key for the title attribute, or '' for none
     * @return string
     */
    protected function pagerLink($id, $p, $sort, $order, array $filters, $text, $titleKey)
    {
        $url   = wl($id, $this->linkParams(['sort' => $sort, 'order' => $order, 'pg' => $p], $filters));
        $title = ($titleKey !== '') ? ' title="' . hsc($this->getLang($titleKey)) . '"' : '';
        return '<a class="pager_btn" href="' . $url . '"' . $title . '>' . $text . '</a>';
    }

    /**
     * Page numbers to show around the current page, with 0 marking an elided
     * gap. Always includes the first and last page.
     *
     * @param int $page
     * @param int $totalPages
     * @return int[]
     */
    protected function pageWindow($page, $totalPages)
    {
        $window = 2;
        $keep   = [];
        for ($i = 1; $i <= $totalPages; $i++) {
            if ($i === 1 || $i === $totalPages || ($i >= $page - $window && $i <= $page + $window)) {
                $keep[] = $i;
            }
        }

        $out  = [];
        $prev = 0;
        foreach ($keep as $p) {
            if ($prev && ($p - $prev) > 1) {
                $out[] = 0; // gap marker
            }
            $out[] = $p;
            $prev  = $p;
        }
        return $out;
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
            $n = (int) floor($diff / 60);
            return sprintf($this->getLang($n === 1 ? 'rel_minute' : 'rel_minutes'), $n);
        }
        if ($diff < 86400) {
            $n = (int) floor($diff / 3600);
            return sprintf($this->getLang($n === 1 ? 'rel_hour' : 'rel_hours'), $n);
        }
        if ($diff < 86400 * 30) {
            $n = (int) floor($diff / 86400);
            return sprintf($this->getLang($n === 1 ? 'rel_day' : 'rel_days'), $n);
        }
        if ($diff < 86400 * 365) {
            $n = (int) floor($diff / (86400 * 30));
            return sprintf($this->getLang($n === 1 ? 'rel_month' : 'rel_months'), $n);
        }
        $n = (int) floor($diff / (86400 * 365));
        return sprintf($this->getLang($n === 1 ? 'rel_year' : 'rel_years'), $n);
    }
}

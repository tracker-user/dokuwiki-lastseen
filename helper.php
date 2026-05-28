<?php

if (!defined('DOKU_INC')) die();

use dokuwiki\Extension\Plugin;

/**
 * Last Seen plugin — storage helper.
 *
 * Owns the on-disk format and path for the per-user last-seen timestamps,
 * shared between the action component (which records activity) and the admin
 * component (which displays it).
 */

class helper_plugin_lastseen extends Plugin
{
    /**
     * Absolute path to the storage file.
     *
     * Lives in the meta directory: it must survive cache clears (so not
     * cachedir) and DokuWiki upgrades (so not inside the plugin folder).
     * Format: a serialized [username => unix_timestamp] map.
     *
     * @return string
     */
    public function getStorePath()
    {
        global $conf;
        return $conf['metadir'] . '/_lastseen.dat';
    }

    /**
     * Read the full [username => timestamp] map.
     *
     * @return array empty array if there is no data yet or the file is corrupt
     */
    public function getAll()
    {
        $path = $this->getStorePath();
        if (!file_exists($path)) {
            return [];
        }
        $raw = io_readFile($path, false);
        if ($raw === '') {
            return [];
        }
        // allowed_classes => false: we only ever store scalars, and this
        // blocks object-injection if the file is ever tampered with.
        $data = unserialize($raw, ['allowed_classes' => false]);
        return is_array($data) ? $data : [];
    }

    /**
     * Last-seen timestamp for one user.
     *
     * @param string $user
     * @return int|null unix timestamp, or null if never recorded
     */
    public function getTimestamp($user)
    {
        $all = $this->getAll();
        return $all[$user] ?? null;
    }

    /**
     * Record $user as seen "now" — throttled.
     *
     * The store is only rewritten if the user's existing timestamp is older
     * than the configured interval. Under heavy browsing this turns hundreds
     * of page views into at most one write per interval per user.
     *
     * @param string $user
     * @return bool true if the store was updated, false if throttled/skipped
     */
    public function record($user)
    {
        if ($user === '' || $user === null) {
            return false;
        }

        $now      = time();
        $interval = (int) $this->getConf('update_interval');

        // Fast path: read without locking. If the stored timestamp is still
        // within the throttle window there is nothing to do — and this is
        // what the vast majority of requests hit.
        $current = $this->getTimestamp($user);
        if ($current !== null && ($now - $current) < $interval) {
            return false;
        }

        // Slow path: a write is due. Lock, re-read (a concurrent request may
        // have just updated it), update, save atomically, unlock.
        $path = $this->getStorePath();
        io_lock($path);

        $all = $this->getAll();
        if (isset($all[$user]) && ($now - $all[$user]) < $interval) {
            // Lost the race — another request updated it while we waited.
            io_unlock($path);
            return false;
        }
        $all[$user] = $now;
        io_saveFile($path, serialize($all));

        io_unlock($path);
        return true;
    }
}

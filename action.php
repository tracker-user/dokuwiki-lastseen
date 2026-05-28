<?php

if (!defined('DOKU_INC')) die();

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\EventHandler;
use dokuwiki\Extension\Event;

/**
 * Last Seen plugin — activity tracker.
 *
 * Records the timestamp of each authenticated request so the admin component
 * can show when every registered user was last active.
 */

class action_plugin_lastseen extends ActionPlugin
{
    /**
     * @param EventHandler $controller
     * @return void
     */
    public function register(EventHandler $controller)
    {
        // DOKUWIKI_STARTED fires on essentially every request, after
        // authentication has resolved. By this point REMOTE_USER is set for
        // any logged-in user — whether they just submitted the login form or
        // arrived with a persistent ("remember me") cookie. Hooking here
        // therefore records last *activity*, the superset of last *login*.
        $controller->register_hook('DOKUWIKI_STARTED', 'AFTER', $this, 'recordActivity');
    }

    /**
     * @param Event $event  unused (DOKUWIKI_STARTED carries no data)
     * @return void
     */
    public function recordActivity(Event $event)
    {
        global $INPUT;

        $user = $INPUT->server->str('REMOTE_USER');
        if ($user === '') {
            return; // anonymous request — nothing to record
        }

        /** @var helper_plugin_lastseen $hlp */
        $hlp = plugin_load('helper', 'lastseen');
        if ($hlp === null) {
            return;
        }

        // record() is internally throttled — for most requests this is a
        // cheap read-and-return with no disk write.
        $hlp->record($user);
    }
}

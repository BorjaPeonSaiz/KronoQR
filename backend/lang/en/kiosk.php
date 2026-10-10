<?php

declare(strict_types=1);

/*
 * Kiosk and pairing texts (RF-PD-06, task 5.6) and the `php artisan
 * kiosk:health` report (RF-PA-07, task 5.11).
 *
 * WHAT BELONGS HERE. Only messages a PERSON reads in a management form or in the
 * console. The generic rejections —`PairingRejected` and `PairingCodeRejected`—
 * are NOT translated and must not be: their `detail` is pinned field by field in
 * the contract, and any variation, language included, would be a channel to tell
 * the causes apart (hard rule 17, RS-03). Clients render their own i18n text
 * from `type`.
 */

return [

    'errors' => [
        'device_name_taken' => 'A kiosk with that name is already active. Choose another name or unpair the previous one first.',
    ],

    /*
     * The `php artisan kiosk:health` report (RF-PA-07, doc 02 Annex C).
     *
     * WHO IT IS WRITTEN FOR. The person at the hotel with an unresponsive tablet
     * in front of them, or who has just mounted a new one. Every line with a
     * finding says WHAT TO LOOK AT, not just that something is wrong: the vendor
     * has no access to this server (ADR-016), so a message that does not say what
     * to do leaves only a phone call.
     */
    'health' => [

        'title' => 'Kiosk health — :moment (:zone)',

        /*
         * The absolute date format is a TRANSLATABLE STRING, not a constant:
         * `09/09/2026` and `2026-09-09` are the same day for a machine and two
         * different days for two people.
         */
        'absolute_format' => 'Y-m-d H:i:s',

        'column' => [
            'name' => 'Kiosk',
            'status' => 'Status',
            'version' => 'Version',
            'last_seen' => 'Last contact',
            'queue' => 'Queue',
            // ADR-047: unknown, never zero.
            'queue_unknown' => '?',
            'battery' => 'Battery',
            'verdict' => 'Verdict',
        ],

        /*
         * The tablet battery (task 3.3).
         *
         * `unknown` is NOT zero: it means "this browser does not report it",
         * which is every browser other than Chrome on Android. Printing "0 %"
         * there would put half the fleet in warning on day one.
         */
        'battery' => [
            'level' => ':level %',
            'charging' => ':level % (charging)',
            'unknown' => '—',
        ],

        // The real `devices.status` catalogue (doc 01 §5.5): two values.
        'status' => [
            'active' => 'active',
            'revoked' => 'revoked',
        ],

        'verdict' => [
            'ok' => 'ok',
            'warning' => 'warning',
            'failure' => 'FAILURE',
            'revoked' => '—',
        ],

        /*
         * SEPARATE FROM `relative` on purpose: the same duration reads ":3 h
         * 4 min ago" in the column and "no sign of it for 3 h 4 min" in the
         * advice, and the "ago" goes after in English and before in Spanish.
         */
        'duration' => [
            'seconds' => ':count s',
            'minutes' => ':count min',
            'hours' => ':hours h :minutes min',
            'days' => ':count d',
        ],

        'relative' => [
            'never' => 'never',
            'ago' => ':duration ago',
        ],

        'advice_header' => 'What to look at',

        'advice' => [
            'queue_pending' => 'It is beating normally, but it reports :queue clock-in(s) not yet sent. '
                .'They are sent on their own once the network is back. DO NOT UNPAIR it until the queue is 0: '
                .'unsent clock-ins are lost when the token is revoked, and they are the legal time record of real people.',
            'late' => 'No sign of it for :elapsed, and the heartbeat runs every 60 s. '
                .'Look at the network where it is mounted first: wi-fi, kiosk VLAN, and whether the tablet is still on.',
            'silent' => 'No sign of it for :elapsed. Go and check it: screen on, application open, wi-fi. '
                .'It keeps clocking in and queueing locally in the meantime (hard rule 19), but nobody sees those clock-ins until it speaks again.',
            'awaiting_first_heartbeat' => 'Just paired and still without its first heartbeat. This is normal for a few seconds: '
                .'the tablet picks up its token on its own. Run this command again in a minute.',
            'never_seen' => 'Paired and NEVER seen. The tablet either never picked up its token or cannot reach the server: '
                .'check that the application is open and that the server URL is correct.',
            'battery_low' => 'It has :level % battery left and is NOT charging. It is almost always an unplugged charger '
                .'or a cable that came loose: plug it in before it dies mid-shift. '
                .'While it has battery it keeps recording clock-ins normally.',
            'queue_storage_degraded' => 'Its clock-in queue has fallen back to memory (or has nowhere to be stored): whatever is queued now is lost if the tablet restarts, '
                .'and whatever was on disk cannot be seen. DO NOT RESTART OR UNPAIR IT: let it reopen its storage and sync, '
                .'and follow the stuck queue runbook, section 7.',
            'discards_unreported' => 'It has :discards clock-in(s) the server declared invalid whose report has not arrived yet: '
                .'nobody will review them until it does. It is usually an outdated app after an update; '
                .'DO NOT UNPAIR IT and follow the stuck queue runbook, section 8.',
            'app_version_behind' => 'Its application (:version) is older than the server. It keeps recording clock-ins normally. '
                .'If it reports 2.2.1 or later, it catches up by itself, at any time, as soon as its queue is empty and on disk '
                .'and nobody has clocked in KIOSK_UPDATE_QUIET_MINUTES. If it reports 2.2.0, during its update window '
                .'(KIOSK_UPDATE_WINDOW, 03:00 to 05:00 by default) if it has already downloaded the new version. If it reports 2.1.0 or earlier '
                .'(or 0.0.0), it will not update itself: '
                .'unregister its service worker ONLY with the tablet online, its pending queue at 0 and no «in-memory queue» warning, '
                .'because reloading with an in-memory queue deletes the unsent clock-ins. NEVER clear the site data: '
                .'it would take the clock-in queue and the pairing with it. Step by step: docs/cliente/en/operation.md §11.1.',
        ],

        'fleet_empty' => 'No kiosk is paired yet. Pair the first one from the panel, under "Kiosks", '
            .'or with: php artisan kiosk:pairing-code {code} --name="Reception"',

        'fleet_all_revoked' => 'No kiosk is active: right now nobody can clock in with a card. '
            .'If you are replacing a broken tablet, pair the new one to close the gap.',

        'result' => 'Result: :label (exit code :code)',

        'status_ok' => 'OK',
        'status_warning' => 'WITH WARNINGS',
        'status_failure' => 'WITH FAILURES',

        'meaning_ok' => 'Every active kiosk responds and has nothing queued.',
        'meaning_warning' => 'Nothing is broken and clocking in still works. Look at the above when you can.',
        'meaning_failure' => 'At least one kiosk is not responding. Follow the "What to look at" line for each of them. '
            .'If you need help, generate the diagnostics package with `php artisan product:diagnostics` and send it to support.',
    ],

];

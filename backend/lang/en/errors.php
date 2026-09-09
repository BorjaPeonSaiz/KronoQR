<?php

declare(strict_types=1);

/*
 * English texts for `php artisan product:errors` and `product:errors:prune`
 * (RF-PD-15, task 5.12). Mirror of `lang/es/errors.php`: same keys, same
 * placeholders, same audience — the hotel's IT person, who does not know this
 * system and probably already has a problem.
 */

return [

    'report' => [
        'title' => 'KronoQR errors since :since',
        'none' => 'No open errors in this period.',
        'found' => 'Showing :shown group(s) of :total. A group is every time the same error happened.',
        'tag_error' => 'error',
        'tag_critical' => 'CRITICAL',
        'seen' => 'Happened :count time(s). First: :first. Last: :last.',
        'trace' => 'Trace: :trace_id (look it up in the technical log if you keep the observability stack)',
        'open_totals' => 'Across the installation there are :errors unresolved error(s) and :critical critical one(s).',
        'what_to_do' => 'What to do: CRITICAL ones first — they are failures nobody sees (a nightly task, a queued '
            .'job) or failures that stop people from clocking in (camera, scanner, tablet storage). If you do not '
            .'know where to start, run `php artisan product:doctor`, and if the problem persists generate the '
            .'diagnostics bundle with `php artisan product:diagnostics` and send it to support: it carries these '
            .'same errors inside and contains no data about your staff.',
        'bad_since' => 'I do not understand the period ":value". Write it as a number and a unit: 30m, 24h, 7d or 2w.',
        'bad_level' => 'Unknown level. The available ones are: :values.',
        'bad_source' => 'Unknown source. The available ones are: :values.',
    ],

    'prune' => [
        'dry_run' => ':rows error group(s) older than :days days without recurring would be deleted. '
            .'Nothing was deleted. Drop --dry-run to do it.',
        'done' => 'Deleted :rows error group(s) older than :days days without recurring. '
            .'This does not affect clock-ins or the audit trail.',
        'failed' => 'Could not prune the error history (:failure). Check that the database responds with '
            .'`php artisan product:doctor`; the old errors are still there and get in nobody\'s way.',
    ],

];

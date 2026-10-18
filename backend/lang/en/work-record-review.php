<?php

declare(strict_types=1);

/*
 * Texts for reviewing an out-of-bounds work-record purge
 * (`compliance:reconcile-work-record --acknowledge`, RL-04, RS-07, ADR-057
 * amendment of 10-10-2026 §6).
 */

return [

    'not_a_purge' => 'Audit entry :id is not a work-record purge (retention.purge_executed). Only those can be reviewed.',
    'already_reviewed' => 'Audit entry :id was already reviewed by entry :review. A review is not repeated or amended; anything else belongs in the incident report.',
    'admissible' => 'Audit entry :id is an admissible purge: reconciliation already accepts it and there is nothing to review.',
    'reason_too_short' => 'The reason must explain what was investigated: at least :min characters.',
    'reason_too_long' => 'The reason accepts at most :max characters.',
    'responsible_inactive' => 'The --responsible account is not an active management account with the admin or rrhh role: it may have been deactivated or changed role while confirming. A review is signed by a person who answers for it. Nothing was written.',
    'missing_options' => 'To mark entry :id as reviewed, --reason and --responsible are required. Nothing was written.',
    'incompatible_options' => '--acknowledge cannot be combined with --full or --days: either reconcile or review.',
    'invalid_purge_id' => '--acknowledge must be the audit entry id, a positive integer; ":value" is not. Nothing was written.',
    'done' => 'Entry :review written: purge :id is now reviewed and no longer counted as pending.',
    'confirm_purge' => 'Mark purge :id as reviewed with this signature?',
    'explains' => 'It also explains again deletions of work days before :cutoff at site :site.',
    'explains_nothing' => 'It explains no deletion: its defects (:violations) are not only the retention period.',

    'site_change_not_pending' => ':target is not a pending site time zone discrepancy: a site_timezone_changes row without its signed entry (change:<id>) or an unsigned site.updated (audit:<id>). The current time zone is not reviewed: it is set back through the product. Nothing was written.',
    'site_change_already_reviewed' => ':target was already reviewed. A review is not repeated or amended; anything else belongs in the incident report.',
    'site_change_incompatible' => '--acknowledge-site-change cannot be combined with --full, --days or --acknowledge: either reconcile or review one thing.',
    'invalid_site_change' => '--acknowledge-site-change must be change:<id> (a site_timezone_changes row) or audit:<id> (a site.updated entry); ":value" is not. Nothing was written.',
    'site_change_missing_options' => 'To mark :target as reviewed, --reason and --responsible are required. Nothing was written.',
    'site_change_preview' => 'Pending discrepancy :target of site :site.',
    'site_change_done' => 'Entry :review written: :target is now reviewed and no longer counted as pending.',
    'confirm_site_change' => 'Mark :target as reviewed with this signature?',

];

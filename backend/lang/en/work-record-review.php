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
    'responsible_inactive' => 'Account :id is not an active management account with the administration or HR role. A review is signed by a person who answers for it. Nothing was written.',
    'missing_options' => 'To mark entry :id as reviewed, --reason and --responsible are required. Nothing was written.',
    'incompatible_options' => '--acknowledge cannot be combined with --full or --days: either reconcile or review.',
    'invalid_purge_id' => '--acknowledge must be the audit entry id, a positive integer; ":value" is not. Nothing was written.',
    'invalid_responsible' => '--responsible must be the account id, a positive integer; ":value" is not. Nothing was written.',
    'done' => 'Entry :review written: purge :id is now reviewed and no longer counted as pending.',
    'explains' => 'It also explains again deletions of work days before :cutoff at site :site.',
    'explains_nothing' => 'It explains no deletion: its defects (:violations) are not only the retention period.',

];

<?php

declare(strict_types=1);

/*
 * What the purges of generated files print on the console (ADR-045):
 * `product:export-all --purge`, `reporting:purge-expired-exports` and
 * `compliance:purge-legal-export-temp`.
 *
 * Read by whoever runs the server, in the installation language. Figures only:
 * no file names, paths or `uuid` (hard rule 21), because the output of a
 * scheduled task ends up in the technical log.
 */

return [

    'data_exports' => [
        'nothing_expired' => 'There is no expired full data export to purge.',
        'purged' => 'Purged :count expired full data exports. The rows are kept with their status.',
        'released' => 'Released :count exports left half-done (reason «stale»): a new one can be requested now.',
        'orphans' => 'Deleted :count leftovers with no live export (a ZIP with no row, or the work directory of an '
            .'interrupted generation).',
        'missing' => 'WARNING: :count exports lost their file before expiring. A «data_export.file_missing» entry '
            .'is left in the audit log. If you have not just restored a backup, find out who deleted or took it.',
        'diagnostics' => 'Deleted :count diagnostics bundles past their period (PRODUCT_DIAGNOSTICS_RETENTION_DAYS).',
    ],

    'report_exports' => [
        'summary' => 'Deferred reports purged: :purged. Stuck jobs released: :released. Orphan files deleted: '
            .':orphans.',
        'missing' => 'WARNING: :count reports lost their file before expiring. A «report_export.file_missing» entry '
            .'is left in the audit log. If you have not just restored a backup, find out who deleted or took it.',
    ],

    'legal_exports' => [
        'temporaries' => 'Orphan temporary files deleted: :count (window: :hours h).',
        'console_overdue' => 'There are :count console legal exports older than :days days on the server. They are '
            .'not deleted automatically: delete them as soon as you have delivered them.',
    ],

];

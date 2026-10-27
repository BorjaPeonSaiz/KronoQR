<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * Las observaciones que puede dejar una pasada de retencion. El valor es la
 * clave de su texto en `retention.report.observations`.
 */
enum RetentionNoteKind: string
{
    case EmptyAuditPartition = 'empty_audit_partition';

    case OrphanedPersonnelPhotos = 'orphaned_personnel_photos';
}

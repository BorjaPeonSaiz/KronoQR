<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * Las cuatro formas en las que el registro horario puede dejar de cuadrar con su
 * auditoria (ADR-057 §4).
 *
 * Cada una es la huella de un ataque distinto con la credencial de la
 * aplicacion, y por eso tienen nombre propio y no un «no cuadra» generico: el
 * runbook (`docs/runbooks/discrepancia-registro-auditoria.md`) empieza por aqui.
 *
 * | Valor | Que se ve | Que suele ser |
 * |---|---|---|
 * | `entry_without_audit` | Un tramo sin ningun asiento `shift_entry.*` | Un `INSERT` inventado |
 * | `entry_differs_from_audit` | Las marcas, la persona, el estado o la version no son las del ultimo asiento | Un `UPDATE` con un valor falso |
 * | `retired_without_correction` | Un tramo anulado o sustituido sin la correccion que lo justifica | Un `UPDATE status` que hace desaparecer horas de la exportacion |
 * | `audit_without_entry` | Un asiento cuyo tramo ya no existe, dentro del plazo de conservacion | Un `DELETE` |
 */
enum WorkRecordDiscrepancyKind: string
{
    case EntryWithoutAudit = 'entry_without_audit';
    case EntryDiffersFromAudit = 'entry_differs_from_audit';
    case RetiredWithoutCorrection = 'retired_without_correction';
    case AuditWithoutEntry = 'audit_without_entry';
}

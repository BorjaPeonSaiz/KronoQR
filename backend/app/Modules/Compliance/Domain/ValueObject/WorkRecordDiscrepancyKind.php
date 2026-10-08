<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * Las cinco formas en las que el registro horario puede dejar de cuadrar con su
 * auditoria (ADR-057 §4).
 *
 * Cada una es la huella de un ataque distinto con la credencial de la
 * aplicacion, y por eso tienen nombre propio y no un «no cuadra» generico: el
 * runbook (`docs/runbooks/discrepancia-registro-auditoria.md`) empieza por aqui.
 *
 * | Valor | Que se ve | Que suele ser |
 * |---|---|---|
 * | `entry_without_audit` | Un tramo sin ningun asiento `shift_entry.*` | Un `INSERT` inventado |
 * | `entry_differs_from_audit` | Las marcas, la persona, el origen, el estado, la version o la correccion no son las de los asientos | Un `UPDATE` con un valor falso |
 * | `retired_without_correction` | Un tramo anulado o sustituido sin la correccion que lo justifica | Un `UPDATE status` que hace desaparecer horas de la exportacion |
 * | `audit_without_entry` | Un asiento cuyo tramo ya no existe y que ninguna purga admisible explica | Un `DELETE` |
 * | `purge_out_of_bounds` | Un asiento `retention.purge_executed` con un corte que la purga real no podia usar | Un asiento de purga añadido para tapar un borrado |
 */
enum WorkRecordDiscrepancyKind: string
{
    case EntryWithoutAudit = 'entry_without_audit';
    case EntryDiffersFromAudit = 'entry_differs_from_audit';
    case RetiredWithoutCorrection = 'retired_without_correction';
    case AuditWithoutEntry = 'audit_without_entry';
    case PurgeOutOfBounds = 'purge_out_of_bounds';
}

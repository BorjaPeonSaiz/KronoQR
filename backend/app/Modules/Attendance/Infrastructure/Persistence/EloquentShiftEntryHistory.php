<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure\Persistence;

use App\Modules\Attendance\Application\Port\ShiftEntryHistory;
use App\Modules\Attendance\Domain\ValueObject\ShiftEntryStatus;

/**
 * {@see ShiftEntryHistory} sobre `shift_entries`.
 *
 * Una sola consulta por identificador unico, y solo la primera columna: aqui no
 * se rehidrata ningun tramo porque nadie va a operar sobre el. Lo que se
 * pregunta es en que estado quedo, para elegir entre `404` y `409` (ADR-035).
 *
 * El predicado es el mismo de ADR-026 —los dos estados no vigentes— y lo dice el
 * propio enum del dominio en vez de repetir los literales, que es como uno de
 * los dos acaba divergiendo.
 */
final readonly class EloquentShiftEntryHistory implements ShiftEntryHistory
{
    public function isRetired(string $shiftEntryUuid): bool
    {
        return ShiftEntry::query()
            ->where('uuid', $shiftEntryUuid)
            ->whereIn('status', [ShiftEntryStatus::VOIDED->value, ShiftEntryStatus::SUPERSEDED->value])
            ->exists();
    }
}

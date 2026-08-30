<?php

declare(strict_types=1);

namespace Tests\Support\Compliance;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\Attendance\AttendanceFixtures;

/**
 * Incidencias escritas directamente en la tabla, para las pruebas de la bandeja
 * (RF-PA-05).
 *
 * **Sin pasar por la deteccion a proposito.** Lo que aqui se prueba es el
 * listado, el filtrado y el flujo de resolucion; montar cada escenario a base de
 * turnos de trece horas y pasadas del detector ataria estas pruebas al camino de
 * la tarea 2.6 —que ya tiene el suyo— y haria imposible escribir combinaciones
 * que la deteccion tarda dias en producir, como una incidencia de hace una
 * semana ya resuelta.
 *
 * Datos ficticios: nada especifico de un cliente entra en el repositorio (regla
 * dura 13).
 */
final class IncidentFixtures
{
    /**
     * Una incidencia abierta, con su tipo, su severidad y su momento de
     * deteccion.
     *
     * **La severidad se pasa a mano y no se deduce del tipo**, al contrario que
     * en el producto: aqui hace falta poder escribir una fila con la combinacion
     * exacta que la prueba necesita, incluida alguna que el catalogo no
     * produciria. Lo que si comprueba que el producto la decide bien es la
     * prueba unitaria de `IncidentType::defaultSeverity()`.
     *
     * @param  array<string, int>  $context
     * @return int `incidents.id`
     */
    public static function open(
        string $employeeUuid,
        string $type = 'insufficient_rest',
        string $severity = 'high',
        string $workDate = '2026-03-14',
        string $detectedAt = '2026-03-15 03:30:00+00',
        ?int $assignedToUserId = null,
        ?string $shiftEntryUuid = null,
        array $context = ['rest_minutes' => 420, 'threshold_minutes' => 720],
    ): int {
        return DB::table('incidents')->insertGetId([
            'employee_id' => AttendanceFixtures::employeeIdOf($employeeUuid),
            'work_date' => $workDate,
            'shift_entry_id' => self::shiftEntryIdOf($shiftEntryUuid),
            'type' => $type,
            'severity' => $severity,
            'status' => 'open',
            'assigned_to_user_id' => $assignedToUserId,
            'detected_at' => $detectedAt,
            'context' => json_encode($context, JSON_THROW_ON_ERROR),
            'created_at' => $detectedAt,
            'updated_at' => $detectedAt,
        ]);
    }

    /**
     * Una incidencia ya cerrada.
     *
     * Existe para el control negativo del filtro por omision —la bandeja de
     * `open` no la enseña— y para el `409` de la segunda resolucion.
     */
    public static function closed(
        string $employeeUuid,
        int $resolvedByUserId,
        string $outcome = 'resolved',
        string $type = 'short_shift',
        string $severity = 'low',
        string $workDate = '2026-03-10',
        string $detectedAt = '2026-03-11 03:30:00+00',
        string $resolvedAt = '2026-03-11 09:00:00+00',
        string $note = 'Revisado con el parte de turno.',
    ): int {
        return DB::table('incidents')->insertGetId([
            'employee_id' => AttendanceFixtures::employeeIdOf($employeeUuid),
            'work_date' => $workDate,
            'shift_entry_id' => null,
            'type' => $type,
            'severity' => $severity,
            'status' => $outcome,
            'assigned_to_user_id' => $resolvedByUserId,
            'detected_at' => $detectedAt,
            'context' => json_encode(['worked_minutes' => 12, 'threshold_minutes' => 15], JSON_THROW_ON_ERROR),
            'resolved_at' => $resolvedAt,
            'resolved_by_user_id' => $resolvedByUserId,
            'resolution_note' => $note,
            'created_at' => $detectedAt,
            'updated_at' => $resolvedAt,
        ]);
    }

    /**
     * La fila tal y como esta escrita, para afirmar sobre columnas que la API no
     * devuelve.
     *
     * @return object{status: string, resolved_at: string|null, resolved_by_user_id: int|null, resolution_note: string|null}
     */
    public static function stored(int $incidentId): object
    {
        $row = DB::table('incidents')
            ->select(['status', 'resolved_at', 'resolved_by_user_id', 'resolution_note'])
            ->where('id', $incidentId)
            ->first();

        if ($row === null) {
            throw new RuntimeException('No existe la incidencia '.$incidentId.'.');
        }

        /** @var object{status: string, resolved_at: string|null, resolved_by_user_id: int|null, resolution_note: string|null} $row */
        return $row;
    }

    private static function shiftEntryIdOf(?string $shiftEntryUuid): ?int
    {
        if ($shiftEntryUuid === null) {
            return null;
        }

        $id = DB::table('shift_entries')->where('uuid', $shiftEntryUuid)->value('id');

        return is_numeric($id) ? (int) $id : null;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Los hechos que alimentan el cuadro de impacto, escritos **directamente en las
 * tablas** (RF-IN-08, tarea 3.13).
 *
 * ## Por que no pasan por los casos de uso
 *
 * Por lo mismo que {@see PeriodReportFixtures} con los contratos y las ausencias:
 * lo que estas pruebas ejercitan es **el cuadro**, no el fichaje ni la bandeja de
 * incidencias. Yendo por el camino real, un cambio en las validaciones del
 * escaneo —una tarjeta que caduca, un anti-rebote que se traga una marca— pondria
 * en rojo las pruebas del cuadro sin que el cuadro hubiera cambiado.
 *
 * Y hay estados que por el camino real costarian horas de reloj: un escaneo que
 * llego cuarenta minutos tarde por la cola offline, una incidencia detectada el 3
 * y resuelta el 5, un grupo de errores de camara con mil ocurrencias.
 *
 * **Las jornadas si van por su camino** ({@see PeriodReportFixtures::workDay()}),
 * porque de ahi salen `shift_entries` y `daily_totals`, que son la proyeccion que
 * la regla dura 7 prohibe fabricar a mano.
 */
final class AdoptionFixtures
{
    /**
     * Un escaneo ya registrado.
     *
     * @param  string  $result  `clock_in`, `clock_out`, `break_start`, `break_end` o uno de los
     *                          cuatro `rejected_*`. Todo lo que NO empieza por `rejected_` cuenta
     *                          como fichaje aceptado (decision 2(b) de la ficha 3.13).
     * @param  int  $lagSeconds  Retraso entre `occurred_at` y `recorded_at`. Por encima de 60 s el
     *                           cuadro lo cuenta como **resuelto sin servidor**: llego por la cola
     *                           offline (ADR-008).
     */
    public static function scan(
        int $deviceId,
        ?int $employeeId,
        string $occurredAt,
        string $origin = 'qr_kiosk',
        string $result = 'clock_in',
        int $lagSeconds = 0,
    ): void {
        DB::table('scan_events')->insert([
            'scan_id' => Str::uuid7()->toString(),
            'device_id' => $deviceId,
            // Nulo en un rechazo por credencial desconocida: no hay a quien
            // atribuirlo, y aun asi es un intento ATENDIDO.
            'employee_id' => $employeeId,
            'occurred_at' => $occurredAt,
            'recorded_at' => self::plusSeconds($occurredAt, $lagSeconds),
            'origin' => $origin,
            'intent' => 'auto',
            'result' => $result,
            // `scan_events_chk_worked_minutes` exige que el acumulado sea nulo
            // EXACTAMENTE en los tres rechazos de verdad (RS-03) y no nulo en todo
            // lo demas, incluido el anti-rebote, que se escribe `rejected_debounce`
            // pero es un desenlace aceptado (ADR-031). Cero es un acumulado
            // legitimo: es lo que trae un `clock_in`, que abre el tramo.
            'worked_minutes' => self::carriesTotal($result) ? 0 : null,
            'client_meta' => '{}',
        ]);
    }

    /**
     * Si ese desenlace lleva acumulado de la jornada.
     *
     * La lista sale del `CHECK` de la migracion y no de una intuicion: son los tres
     * rechazos que RS-03 obliga a responder sin revelar nada, y el resto —incluido
     * `rejected_debounce`— si acumula.
     */
    private static function carriesTotal(string $result): bool
    {
        return ! in_array($result, ['rejected_unknown', 'rejected_revoked', 'rejected_signature'], true);
    }

    /**
     * Una correccion manual sobre un tramo, con la fecha en la que se hizo.
     *
     * El cuadro las cuenta por `created_at` y no por la jornada corregida: lo que
     * mide es el trabajo manual del periodo, no la calidad del registro de aquel
     * mes. Ver `adoption.criteria.corrections`.
     */
    public static function correction(int $shiftEntryId, int $userId, string $createdAt): void
    {
        DB::table('shift_corrections')->insert([
            'shift_entry_id' => $shiftEntryId,
            'performed_by_user_id' => $userId,
            'action' => 'modified',
            // Los dos JSON son obligatorios en un 'modified'
            // ('shift_corrections_chk_shape_by_action'): nada se sobrescribe en
            // este producto, asi que una correccion siempre conserva la version
            // anterior junto a la nueva (regla dura 5, RN-13).
            'before' => json_encode(['clocked_out_at' => null], JSON_THROW_ON_ERROR),
            'after' => json_encode(['clocked_out_at' => '2026-03-02T13:00:00Z'], JSON_THROW_ON_ERROR),
            'reason_code' => 'OLVIDO_FICHAJE_SALIDA',
            'created_at' => $createdAt,
        ]);
    }

    /**
     * Una incidencia, abierta o ya resuelta.
     *
     * `$resolvedAt` a `null` la deja abierta, que es lo que cuenta en la foto de
     * hoy. Con fecha, entra en el tiempo de resolucion **del periodo en el que se
     * resolvio**, no en el de deteccion.
     */
    public static function incident(
        int $employeeId,
        string $workDate,
        string $detectedAt,
        ?string $resolvedAt = null,
        string $type = 'open_shift_expired',
    ): void {
        DB::table('incidents')->insert([
            'employee_id' => $employeeId,
            'work_date' => $workDate,
            'type' => $type,
            'severity' => 'medium',
            'status' => $resolvedAt === null ? 'open' : 'resolved',
            'detected_at' => $detectedAt,
            'resolved_at' => $resolvedAt,
            'context' => '{}',
            'created_at' => $detectedAt,
            'updated_at' => $resolvedAt ?? $detectedAt,
        ]);
    }

    /**
     * Un grupo de errores del quiosco con sus ocurrencias.
     *
     * `error_events` agrupa por huella (RF-PD-15): mil camaras caidas son **una
     * fila** con `occurrences = 1000`. Por eso el cuadro suma `occurrences` y no
     * cuenta filas, y por eso el grupo se atribuye al periodo de su **ultima**
     * aparicion — la unica atribucion posible sin cambiar el modelo, y asi lo dice
     * `adoption.criteria.availability`.
     */
    public static function kioskError(string $code, int $occurrences, string $lastSeenAt): void
    {
        DB::table('error_events')->insert([
            'fingerprint' => hash('sha256', $code.$lastSeenAt),
            'level' => 'error',
            'source' => 'kiosk',
            'code' => $code,
            'message' => 'Fallo del quiosco en una prueba.',
            'app_version' => '1.0.0',
            'occurrences' => $occurrences,
            'first_seen_at' => $lastSeenAt,
            'last_seen_at' => $lastSeenAt,
            'context' => '{}',
            'created_at' => $lastSeenAt,
            'updated_at' => $lastSeenAt,
        ]);
    }

    /**
     * Una credencial **entregada**, que es la unica que hace que su dueño deje de
     * contar como «sin tarjeta».
     *
     * La impresa y todavia en el cajon de RRHH no cuenta: el indicador mide quien
     * puede fichar con tarjeta hoy, no cuantas se han impreso.
     */
    public static function deliveredCredential(int $employeeId, int $deliveredByUserId, string $deliveredAt): void
    {
        DB::table('credentials')->insert([
            'uuid' => Str::uuid7()->toString(),
            'employee_id' => $employeeId,
            'key_id' => 'k1',
            'secret_hash' => hash('sha256', 'credencial-'.$employeeId),
            'issued_at' => $deliveredAt,
            // `credentials_chk_delivery_is_signed`: una entrega SIN firma no existe
            // en este producto. La tarjeta es la credencial (ADR-014), asi que
            // ponerla en la mano de alguien tiene que dejar dicho quien lo hizo.
            'printed_at' => $deliveredAt,
            'delivered_at' => $deliveredAt,
            'delivered_by_user_id' => $deliveredByUserId,
        ]);
    }

    /**
     * El identificador interno de un tramo de una jornada, para colgarle una
     * correccion.
     */
    public static function shiftEntryIdOf(string $employeeUuid, string $workDate): int
    {
        $id = DB::table('shift_entries')
            ->join('employees', 'employees.id', '=', 'shift_entries.employee_id')
            ->where('employees.uuid', $employeeUuid)
            ->where('shift_entries.work_date', $workDate)
            ->value('shift_entries.id');

        if (! is_numeric($id)) {
            // Se rompe en voz alta y con el dato dentro: una correccion colgada del
            // tramo cero fallaria mas tarde por la clave ajena, y el mensaje de
            // aquello no diria que la jornada no existe.
            throw new \RuntimeException('No hay tramo del '.$workDate.' para el empleado indicado.');
        }

        return (int) $id;
    }

    private static function plusSeconds(string $instant, int $seconds): string
    {
        return (new \DateTimeImmutable($instant))
            ->modify('+'.$seconds.' seconds')
            ->format('Y-m-d H:i:sP');
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure\Persistence;

use App\Modules\Attendance\Application\Port\CredentialScans;
use App\Modules\Attendance\Domain\ValueObject\CredentialScan;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;

/**
 * Los usos de credencial en un quiosco, leidos hacia atras (RF-PR-06, RN-16,
 * tarea 3.11).
 *
 * **Devuelve hechos, no veredictos.** Aqui no hay ninguna comparacion contra un
 * umbral: quien decide si dos escaneos coinciden o si un transito es imposible
 * es `CredentialPatternPolicy`, con los valores vigentes del centro (regla dura
 * 14). Lo unico que esta consulta decide es **que filas son un uso de
 * credencial**, que es una propiedad del dato y no una regla.
 *
 * ## Los dos filtros, y su porque (decision 4 de la ficha)
 *
 * - `origin IN ('qr_kiosk', 'pin_kiosk')` — solo lo que paso por una tablet. El
 *   PIN de emergencia (RF-AT-11) entra porque tambien es una credencial
 *   personal, y se presta con mas facilidad que la tarjeta. `manual_admin` e
 *   `import` no pasan por ningun quiosco: no hay tarjeta que prestar.
 * - **`result` sin los tres rechazos de verdad** —`rejected_unknown`,
 *   `rejected_revoked` y `rejected_signature`—, que no son un uso de credencial
 *   y muchos ni siquiera resuelven a una persona.
 *
 * ## `rejected_debounce` SI entra, y es lo que hace ver a RN-16
 *
 * Bloqueante B3 de la revision. El anti-rebote de RF-AT-06 es **por persona y no
 * por quiosco**: la segunda presentacion de la misma tarjeta en **otro** quiosco
 * dentro de `ATTENDANCE_DEBOUNCE_SECONDS` —60 s de serie— se escribe
 * `rejected_debounce`. Excluirla dejaba a RN-16 ciega justo en la franja de
 * 10–30 s, la que nadie puede explicar, y activa solo entre los 60 s y los 120 s
 * del transito minimo. Y es un desenlace **aceptado** (ADR-031): siempre resolvio
 * a una persona y siempre fue una tarjeta presentada en una tablet. Lo unico que
 * no tiene es tramo, de ahi el respaldo de `shift_entry_id` de la decision 14.
 *
 * Se une con `employees` y con `devices` para devolver el **UUID publico** del
 * empleado y el rotulo del quiosco, y con `shift_entries` para el UUID del tramo
 * cuando lo hubo. Una clave interna filtrada hacia arriba obligaria a quien la
 * recibe a saber de que tabla salio.
 *
 * ## Una consulta, y sin indice que la sirva
 *
 * Los dos hallazgos miran las mismas filas —la coincidencia agrupa por quiosco y
 * RN-16 por persona— y el conjunto de una ventana de treinta dias cabe
 * holgadamente en memoria: son los fichajes de un hotel, no un historico. Dos
 * consultas obligarian a mantener dos filtros que tienen que decir lo mismo.
 *
 * **Ningun indice de `scan_events` sirve este rango** (R2 de la revision), y
 * conviene decirlo en vez de citar los que hay: los dos compuestos empiezan por
 * `employee_id` y por `device_id`, asi que un `WHERE occurred_at BETWEEN …` sin
 * columna de cabecera termina en recorrido secuencial. **Se acepta**: corre una
 * vez al dia, de madrugada, fuera del camino de fichaje, y a cuatro anos un
 * hotel de doscientas personas tiene del orden de 1,2 M de filas. El indice
 * parcial por `occurred_at` queda anotado como pendiente con su umbral de coste;
 * anadirlo hoy seria pagar escritura en **cada** fichaje para ahorrar segundos
 * en un proceso nocturno.
 */
final readonly class EloquentCredentialScans implements CredentialScans
{
    public function __construct(private ConnectionInterface $connection) {}

    public function kioskScansBetween(DateTimeImmutable $from, DateTimeImmutable $to, DateTimeZone $timezone): array
    {
        /** @var list<object{employee_uuid: string, device_id: int, device_name: string, occurred_at: string, scan_id: string, shift_entry_uuid: string|null, clock_skew_seconds: int|null}> $rows */
        $rows = $this->connection->table('scan_events')
            ->join('employees', 'employees.id', '=', 'scan_events.employee_id')
            ->join('devices', 'devices.id', '=', 'scan_events.device_id')
            ->leftJoin('shift_entries', 'shift_entries.id', '=', 'scan_events.shift_entry_id')
            ->whereIn('scan_events.origin', ['qr_kiosk', 'pin_kiosk'])
            // La lista de lo que NO entra, escrita entera: con un `NOT LIKE
            // 'rejected%'` el anti-rebote se caia dentro sin que nadie lo
            // decidiera, y ahi estaba el agujero de RN-16 (decision 15).
            ->whereNotIn('scan_events.result', ['rejected_unknown', 'rejected_revoked', 'rejected_signature'])
            ->whereBetween('scan_events.occurred_at', [
                $from->format('Y-m-d H:i:s.uP'),
                $to->format('Y-m-d H:i:s.uP'),
            ])
            ->orderBy('scan_events.occurred_at')
            ->orderBy('scan_events.scan_id')
            ->select([
                'employees.uuid as employee_uuid',
                'scan_events.device_id',
                'devices.name as device_name',
                'scan_events.occurred_at',
                'scan_events.scan_id',
                'shift_entries.uuid as shift_entry_uuid',
                // El DESFASE, no la marca (decision 16): `flagged_for_review` es
                // verdadera para todo fichaje por PIN, y filtrar por ella dejaba
                // sin RN-16 justo al camino que mas facil es prestar.
                'scan_events.clock_skew_seconds',
            ])
            ->get()
            ->all();

        $scans = [];

        foreach ($rows as $row) {
            $occurredAt = $this->toUtc($row->occurred_at);

            $scans[] = CredentialScan::of(
                employeeUuid: $row->employee_uuid,
                deviceId: $row->device_id,
                deviceName: $row->device_name,
                occurredAt: $occurredAt,
                // RN-05: la fecha civil del escaneo en la zona del centro. La
                // zona llega por parametro porque quien la conoce es el caso de
                // uso, que resuelve el centro de la instalacion.
                workDate: WorkDate::fromInstant($occurredAt, $timezone),
                scanId: $row->scan_id,
                shiftEntryUuid: $row->shift_entry_uuid,
                clockSkewSeconds: $row->clock_skew_seconds,
            );
        }

        return $scans;
    }

    /**
     * La misma firma y el mismo cuerpo que {@see EloquentOutOfOrderScans::toUtc()},
     * a proposito: son dos adaptadores de la misma tabla y una conversion que se
     * escribe distinta en cada uno es una conversion que acaba difiriendo.
     */
    private function toUtc(string|DateTimeInterface $value): DateTimeImmutable
    {
        $instant = $value instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($value)
            : new DateTimeImmutable($value);

        // Regla dura 3: hacia arriba solo salen instantes en UTC. La columna es
        // `TIMESTAMPTZ` y PostgreSQL la devuelve en la zona de la sesion, que no
        // tiene por que ser la misma manana.
        return $instant->setTimezone(new DateTimeZone('UTC'));
    }
}

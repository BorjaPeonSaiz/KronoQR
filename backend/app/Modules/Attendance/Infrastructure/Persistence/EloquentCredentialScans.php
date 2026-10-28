<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure\Persistence;

use App\Modules\Attendance\Application\Port\CredentialScans;
use App\Modules\Attendance\Application\Port\ScanResult;
use App\Modules\Attendance\Domain\ValueObject\CredentialScan;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;

/**
 * Los usos de credencial en un quiosco, leidos hacia atras (RF-PR-06, RN-16).
 *
 * **Devuelve hechos, no veredictos.** Aqui no hay ninguna comparacion contra un
 * umbral: quien decide si dos escaneos coinciden o si un transito es imposible
 * es `CredentialPatternPolicy`, con los valores vigentes del centro (regla dura
 * 14). Lo unico que esta consulta decide es **que filas son un uso de
 * credencial**, que es una propiedad del dato y no una regla.
 *
 * ## Los dos filtros, y su porque
 *
 * - `origin IN ('qr_kiosk', 'pin_kiosk')` — solo lo que paso por una tablet. El
 *   PIN de emergencia (RF-AT-11) entra porque tambien es una credencial
 *   personal, y se presta con mas facilidad que la tarjeta. `manual_admin` e
 *   `import` no pasan por ningun quiosco: no hay tarjeta que prestar.
 * - **`result` sin los cuatro rechazos de credencial** —`rejected_unknown`,
 *   `rejected_revoked`, `rejected_signature` y `rejected_suspended` (RN-25)—,
 *   que no son un uso de credencial y muchos ni siquiera resuelven a una persona.
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
 * no tiene es tramo, de ahi el respaldo de `shift_entry_id`.
 *
 * Se une con `employees` y con `devices` para devolver el **UUID publico** del
 * empleado y el rotulo del quiosco, y busca en `shift_entries` el UUID del tramo
 * cuando lo hubo. Una clave interna filtrada hacia arriba obligaria a quien la
 * recibe a saber de que tabla salio.
 *
 * ## Una consulta, por el indice de la hora UTC
 *
 * Los dos hallazgos miran las mismas filas —la coincidencia agrupa por quiosco y
 * RN-16 por persona— y el conjunto de una ventana de treinta dias cabe
 * holgadamente en memoria: son los fichajes de un hotel, no un historico. Dos
 * consultas obligarian a mantener dos filtros que tienen que decir lo mismo.
 *
 * **La ventana se filtra por `(occurred_at AT TIME ZONE 'UTC')`**, la
 * expresion de `scan_events_occurred_at_utc_index`, y no por `occurred_at`: los
 * dos compuestos de la tabla empiezan por `employee_id` y por `device_id`, y un
 * `occurred_at BETWEEN` sin columna de cabecera recorria la tabla entera cada
 * noche. El indice ya existia para el cuadro de adopcion, asi que no cuesta
 * escritura nueva en el fichaje; la hora de pared UTC es la misma escala que el
 * instante, porque UTC no cambia de hora. Los extremos se pasan ya en UTC y sin
 * zona, con la misma forma que la expresion.
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
            ->whereIn('scan_events.origin', ['qr_kiosk', 'pin_kiosk'])
            // La lista de lo que NO entra, clasificada caso a caso en
            // `ScanResult::credentialRejections()`: con un `NOT LIKE
            // 'rejected%'` el anti-rebote se caia dentro sin que nadie lo
            // decidiera, y ahi estaba el agujero de RN-16. El
            // descarte del servidor (ADR-061) tampoco es un uso de la credencial
            // que el servidor decidiera: no entra.
            ->whereNotIn('scan_events.result', [...ScanResult::credentialRejections(), ScanResult::DISCARDED_UNPROCESSABLE->value])
            // La expresion del indice, tal cual (ver el docblock).
            ->whereRaw("(scan_events.occurred_at AT TIME ZONE 'UTC') BETWEEN ?::timestamp AND ?::timestamp", [
                $this->utcWallTime($from),
                $this->utcWallTime($to),
            ])
            ->orderBy('scan_events.occurred_at')
            ->orderBy('scan_events.scan_id')
            ->select([
                'employees.uuid as employee_uuid',
                'scan_events.device_id',
                'devices.name as device_name',
                'scan_events.occurred_at',
                'scan_events.scan_id',
                // Por la clave primaria, escaneo a escaneo, y no con un `LEFT
                // JOIN`: con la union el planificador preferia leer
                // `shift_entries` entera, un coste que crece con el historico y
                // no con la ventana.
                $this->connection->raw('(SELECT se.uuid FROM shift_entries se WHERE se.id = scan_events.shift_entry_id) AS shift_entry_uuid'),
                // El DESFASE, no la marca: `flagged_for_review` es
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

    /**
     * El instante como hora de pared UTC sin zona, la forma de
     * `occurred_at AT TIME ZONE 'UTC'`. Con microsegundos: el extremo es
     * inclusivo y no puede perder el escaneo del ultimo instante.
     */
    private function utcWallTime(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}

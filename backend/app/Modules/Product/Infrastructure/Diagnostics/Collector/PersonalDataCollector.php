<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics\Collector;

use App\Modules\Product\Application\Port\DiagnosticsCollector;
use App\Modules\Product\Domain\ValueObject\DiagnosticsOptions;
use App\Modules\Product\Domain\ValueObject\FieldAllowlist;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\ValueObject\UtcInstant;
use DateTimeInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use stdClass;

/**
 * Seccion `personal_data`: **la unica del paquete que lleva datos de personas**,
 * y solo cuando el cliente la pide expresamente (RL-19, RF-PD-09, ADR-020).
 *
 * ## Existe porque a veces no hay otra forma
 *
 * Hay incidencias que no se pueden diagnosticar sin ver el dato: «a esta persona
 * le salen cuatro horas de mas el martes». Negarlo del todo obligaria al cliente
 * a mandar capturas de pantalla por WhatsApp, que es peor por todos los lados.
 *
 * ## Lo que la hace legitima es todo lo que la rodea
 *
 * 1. **Nunca es el valor por defecto ni un efecto secundario** de otra opcion.
 * 2. **Solo la puede pedir una cuenta de gestion con rol `admin`.** Un token de
 *    soporte recibe `403`: decidir que los datos de su plantilla salen de su
 *    servidor es del cliente y de nadie mas.
 * 3. **Deja asiento propio** en `audit_log`
 *    (`diagnostics.personal_data_included`), distinto del de generacion.
 * 4. **El panel avisa antes**, en texto llano, de que se incluye.
 * 5. `manifest.anonymized` pasa a `false`, para que quien reciba el fichero lo
 *    sepa en la primera linea.
 *
 * ## Y aun asi, lista de permitidos
 *
 * `national_id_hash`, `email`, `pin_hash`, `photo_path` y `client_meta` **no
 * salen ni pidiendolo**. Que el cliente autorice enviar su plantilla no
 * convierte el hash de un DNI ni el hash de un PIN en algo que soporte necesite:
 * son material de autenticacion, no de diagnostico. `client_meta` queda fuera
 * porque lleva lo que la tablet quiso mandar y su forma no la controla nadie.
 */
final readonly class PersonalDataCollector implements DiagnosticsCollector
{
    /** Tope de las fichas incluidas. Con el filtro por actividad no se alcanza casi nunca. */
    private const int MAX_EMPLOYEES = 2000;

    /** Tope de filas de jornada por coleccion. */
    private const int MAX_ROWS = 5000;

    public function __construct(
        private ConnectionInterface $database,
        private Clock $clock,
    ) {}

    public function section(): string
    {
        return 'personal_data';
    }

    public function collect(DiagnosticsOptions $options): array
    {
        $since = $this->clock->now()->modify('-'.$options->periodDays.' days');

        return [
            'period_days' => $options->periodDays,
            'since' => UtcInstant::format($since),
            'employees' => $this->employees($since->format(DateTimeInterface::ATOM)),
            'shift_entries' => $this->shiftEntries($since->format(DateTimeInterface::ATOM)),
            'scan_events' => $this->scanEvents($since->format(DateTimeInterface::ATOM)),
            'incidents' => $this->openIncidents(),
        ];
    }

    /**
     * La plantilla **de las personas que aparecen en el periodo**, no la
     * plantilla entera (RL-19, minimizacion).
     *
     * ## Por que no salen las 500
     *
     * Porque no hacen falta y porque sacarlas seria enviar al fabricante la
     * plantilla completa del hotel para diagnosticar el problema de una persona.
     * Las unicas fichas que sirven para leer el resto de la seccion son las de
     * quien tiene un tramo, un escaneo o una incidencia abierta dentro de la
     * ventana: sin ellas, `employee_uuid` de un tramo no se puede poner en cara
     * a nadie; con las demas, el paquete lleva datos de gente que no tiene nada
     * que ver con la incidencia.
     *
     * Es la diferencia entre «los datos necesarios» y «los datos que hay», y es
     * exactamente lo que RL-19 exige cuando el cliente autoriza la salida.
     *
     * Un periodo corto puede dejar la lista vacia —una instalacion parada—, y
     * eso tambien es correcto: el recuento lo dice.
     *
     * @return array<string, mixed>
     */
    private function employees(string $since): array
    {
        $allowlist = new FieldAllowlist('uuid', 'employee_code', 'full_name', 'status', 'department_id');

        $active = $this->database->table('employees')
            ->where(function (Builder $query) use ($since): void {
                $query
                    ->whereExists(fn (Builder $sub) => $sub
                        ->selectRaw('1')
                        ->from('shift_entries')
                        ->whereColumn('shift_entries.employee_id', 'employees.id')
                        ->where('shift_entries.clocked_in_at', '>=', $since))
                    ->orWhereExists(fn (Builder $sub) => $sub
                        ->selectRaw('1')
                        ->from('scan_events')
                        ->whereColumn('scan_events.employee_id', 'employees.id')
                        ->where('scan_events.occurred_at', '>=', $since))
                    ->orWhereExists(fn (Builder $sub) => $sub
                        ->selectRaw('1')
                        ->from('incidents')
                        ->whereColumn('incidents.employee_id', 'employees.id')
                        ->where('incidents.status', '=', 'open'));
            });

        return $this->collection(
            $allowlist,
            (clone $active)
                ->selectRaw("uuid, employee_code, (first_name || ' ' || last_name) AS full_name, status, department_id")
                ->orderBy('id')
                ->limit(self::MAX_EMPLOYEES)
                ->get(),
            (clone $active)->count(),
            self::MAX_EMPLOYEES,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function shiftEntries(string $since): array
    {
        $allowlist = new FieldAllowlist(
            'uuid', 'employee_uuid', 'work_date', 'clocked_in_at', 'clocked_out_at',
            'duration_minutes', 'status', 'clock_in_source', 'clock_out_source', 'version',
        );

        $query = $this->database->table('shift_entries')
            ->join('employees', 'employees.id', '=', 'shift_entries.employee_id')
            ->where('shift_entries.clocked_in_at', '>=', $since);

        return $this->collection(
            $allowlist,
            (clone $query)
                ->select(
                    'shift_entries.uuid',
                    'employees.uuid as employee_uuid',
                    'shift_entries.work_date',
                    'shift_entries.clocked_in_at',
                    'shift_entries.clocked_out_at',
                    'shift_entries.duration_minutes',
                    'shift_entries.status',
                    'shift_entries.clock_in_source',
                    'shift_entries.clock_out_source',
                    'shift_entries.version',
                )
                ->orderBy('shift_entries.clocked_in_at')
                ->limit(self::MAX_ROWS)
                ->get(),
            (clone $query)->count(),
            self::MAX_ROWS,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function scanEvents(string $since): array
    {
        // Sin `client_meta`: lo compone la tablet y su forma no la controla el
        // producto. Es el campo por el que se filtraria lo que nadie reviso.
        $allowlist = new FieldAllowlist(
            'scan_id', 'employee_uuid', 'device_uuid', 'occurred_at', 'recorded_at',
            'origin', 'intent', 'result', 'clock_skew_seconds', 'flagged_for_review',
        );

        $query = $this->database->table('scan_events')
            ->leftJoin('employees', 'employees.id', '=', 'scan_events.employee_id')
            ->leftJoin('devices', 'devices.id', '=', 'scan_events.device_id')
            ->where('scan_events.occurred_at', '>=', $since);

        return $this->collection(
            $allowlist,
            (clone $query)
                ->select(
                    'scan_events.scan_id',
                    'employees.uuid as employee_uuid',
                    'devices.uuid as device_uuid',
                    'scan_events.occurred_at',
                    'scan_events.recorded_at',
                    'scan_events.origin',
                    'scan_events.intent',
                    'scan_events.result',
                    'scan_events.clock_skew_seconds',
                    'scan_events.flagged_for_review',
                )
                ->orderBy('scan_events.occurred_at')
                ->limit(self::MAX_ROWS)
                ->get(),
            (clone $query)->count(),
            self::MAX_ROWS,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function openIncidents(): array
    {
        // Sin `resolution_note` ni `context`: la nota la escribe una persona en
        // texto libre y puede llevar cualquier cosa.
        $allowlist = new FieldAllowlist(
            'id', 'employee_uuid', 'work_date', 'type', 'severity', 'status', 'detected_at',
        );

        $query = $this->database->table('incidents')
            ->leftJoin('employees', 'employees.id', '=', 'incidents.employee_id')
            ->where('incidents.status', '=', 'open');

        return $this->collection(
            $allowlist,
            (clone $query)
                ->select(
                    'incidents.id',
                    'employees.uuid as employee_uuid',
                    'incidents.work_date',
                    'incidents.type',
                    'incidents.severity',
                    'incidents.status',
                    'incidents.detected_at',
                )
                ->orderByDesc('incidents.detected_at')
                ->limit(self::MAX_ROWS)
                ->get(),
            (clone $query)->count(),
            self::MAX_ROWS,
        );
    }

    /**
     * Una coleccion con su recuento total y si se trunco.
     *
     * **El total va siempre**, tambien cuando no se trunca. Sin el, soporte no
     * puede distinguir «hay 40 fichajes» de «hay 40 de los 9.000 que caben».
     *
     * @param  Collection<int, stdClass>  $rows
     * @return array<string, mixed>
     */
    private function collection(FieldAllowlist $allowlist, Collection $rows, int $total, int $cap): array
    {
        $items = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;

            $items[] = $allowlist->apply($values);
        }

        return [
            'total' => $total,
            'truncated' => $total > $cap,
            'items' => $items,
        ];
    }
}

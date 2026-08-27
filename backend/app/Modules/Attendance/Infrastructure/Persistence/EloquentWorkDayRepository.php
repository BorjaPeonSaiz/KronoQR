<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure\Persistence;

use App\Modules\Attendance\Application\Port\SiteCalendar;
use App\Modules\Attendance\Application\Port\WorkDayRepository;
use App\Modules\Attendance\Domain\Exception\OverlappingShiftEntry;
use App\Modules\Attendance\Domain\Exception\ShiftAlreadyOpen;
use App\Modules\Attendance\Domain\Model\ShiftEntry as ShiftEntryEntity;
use App\Modules\Attendance\Domain\Model\WorkDay;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Attendance\Domain\ValueObject\ShiftEntryStatus;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Shared\Application\Port\Clock;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * El agregado `WorkDay` sobre Eloquent y PostgreSQL.
 *
 * **Traduce en los dos sentidos y no deja salir un modelo Eloquent.** Hacia
 * arriba solo viajan `WorkDay` y sus `ShiftEntry` de dominio. Si el caso de uso
 * tuviera el modelo tendria tambien `->save()`, `->delete()` y la tentacion de
 * usarlos, y con eso RN-01 y la regla dura 5 durarian hasta la primera prisa.
 * Los dos `ShiftEntry` —el de dominio y el de persistencia— se distinguen con un
 * alias, igual que hace `Workforce` con `Employee`.
 *
 * **La zona horaria entra por el puerto `SiteCalendar`** y no leyendo
 * `sites.timezone` desde aqui: esa tabla es de `Workforce` (ADR-025). Sin la
 * zona no se puede reconstruir un `WorkDate`, porque una fecha civil sin la zona
 * en la que es civil no dice a que dia pertenece un turno de noche (RN-05).
 *
 * **La unica clave de otro modulo que se resuelve aqui es `employees.id`**, y se
 * hace con una consulta directa a esa tabla. Es deliberado y acotado:
 * `shift_entries.employee_id` es una clave foranea a `employees`, asi que el
 * esquema ya declara esa relacion; lo que no se hace es importar el modelo
 * Eloquent de `Workforce` ni conocer ninguna otra de sus columnas. El puerto
 * `EmployeeDirectory` no sirve para esto a proposito: `EmployeeSnapshot` **no
 * lleva la clave interna**, porque en la API nunca viaja.
 *
 * **Las violaciones de RN-01 y RN-02 se traducen a excepciones de dominio.** Las
 * restricciones de PostgreSQL son la ultima linea de defensa (doc 02 §3.2) y
 * bajo concurrencia **saltan**: dos escaneos simultaneos del mismo empleado
 * llegan los dos a una jornada sin turno abierto. El caso de uso sabe que hacer
 * con `ShiftAlreadyOpen` —reintentar, y resolverlo por anti-rebote—, pero no con
 * un `SQLSTATE 23505`.
 */
final readonly class EloquentWorkDayRepository implements WorkDayRepository
{
    public function __construct(
        private ConnectionInterface $connection,
        private SiteCalendar $calendar,
        private Clock $clock,
    ) {}

    public function findOpenWorkDayFor(string $employeeUuid): ?WorkDay
    {
        $employeeId = $this->employeeIdOf($employeeUuid);

        if ($employeeId === null) {
            return null;
        }

        // El indice unico parcial `one_open_shift_per_employee` cubre justo esta
        // consulta: entran solo las filas vigentes sin salida, unas pocas
        // centenas aunque la tabla tenga millones.
        $open = ShiftEntry::query()
            ->where('employee_id', $employeeId)
            ->whereNull('clocked_out_at')
            ->whereNotIn('status', self::historicalStatuses())
            ->first();

        if (! $open instanceof ShiftEntry) {
            return null;
        }

        $timezone = $this->timezoneOf($open->site_id);

        return $this->load($employeeUuid, $employeeId, $open->site_id, WorkDate::fromIsoDate(
            $open->work_date->format('Y-m-d'),
            $timezone,
        ));
    }

    public function findWorkDayFor(string $employeeUuid, WorkDate $workDate): ?WorkDay
    {
        $employeeId = $this->employeeIdOf($employeeUuid);

        if ($employeeId === null) {
            return null;
        }

        $siteId = $this->siteOf($employeeId, $workDate);

        if ($siteId === null) {
            return null;
        }

        return $this->load($employeeUuid, $employeeId, $siteId, $workDate);
    }

    /**
     * La jornada que contiene un tramo **vigente** (RF-PA-04, tarea 1.15).
     *
     * Se entra por el tramo y no por la fecha porque el panel solo conoce el
     * identificador del tramo, y porque deducir la jornada de la hora de entrada
     * partiria los turnos de noche por la puerta de atras (RN-05, ADR-006). La
     * `work_date` se lee de la propia fila: es la fecha civil que se decidio
     * cuando el turno se abrio, no una que se recalcule ahora.
     *
     * Devuelve `null` tambien cuando el tramo existe pero ya es historico
     * —anulado o sustituido—, porque entonces no hay jornada que pueda
     * corregirlo (ADR-026). Quien llama distingue el `404` del `409` mirando el
     * historico.
     */
    public function findWorkDayOfShiftEntry(string $shiftEntryUuid): ?WorkDay
    {
        $row = ShiftEntry::query()
            ->where('uuid', $shiftEntryUuid)
            ->whereNotIn('status', self::historicalStatuses())
            ->first();

        if (! $row instanceof ShiftEntry) {
            return null;
        }

        $employeeUuid = $this->employeeUuidOf($row->employee_id);

        if ($employeeUuid === null) {
            return null;
        }

        return $this->load($employeeUuid, $row->employee_id, $row->site_id, WorkDate::fromIsoDate(
            $row->work_date->format('Y-m-d'),
            $this->timezoneOf($row->site_id),
        ));
    }

    /**
     * Guarda los tramos de la jornada.
     *
     * **La proyeccion `daily_totals` no se escribe aqui, y no es un
     * incumplimiento del contrato del puerto.** El agregado emite
     * `DailyTotalsRecalculated` con el estado completo del dia y quien lo
     * escribe es `Infrastructure/Projection/DailyTotalsProjector`, un listener.
     * Que no sea este metodo lo decide la arquitectura y no la comodidad:
     * `Infrastructure/Persistence` **no puede** depender de
     * `Infrastructure/Projection` —Deptrac lo verifica— y el §2 del doc 02
     * describe ese directorio literalmente como *«listeners que mantienen
     * `daily_totals`»*.
     *
     * **Lo que el contrato promete de verdad se sigue cumpliendo**: la
     * proyeccion se recalcula **en la misma transaccion** que el tramo (RN-06,
     * ADR-007, regla dura 7). `RegisterScanHandler` publica los eventos del
     * agregado antes de confirmar y el despachador de Laravel es sincrono, asi
     * que el `UPSERT` del proyector cae dentro de esta escritura. Si el tramo
     * revierte, el total revierte con el.
     *
     * Consecuencia para quien escriba el proximo caso de uso que guarde una
     * jornada —la correccion de la tarea 1.15—: **tiene que publicar los eventos
     * del agregado dentro de su transaccion**. Guardar sin publicar deja la
     * proyeccion vieja.
     */
    public function save(WorkDay $workDay): void
    {
        $employeeId = $this->employeeIdOf($workDay->employeeUuid())
            ?? throw new RuntimeException('Unknown employee '.$workDay->employeeUuid().' while saving a work day.');

        // `transaction()` sobre una ya abierta crea un `SAVEPOINT`, no una
        // segunda transaccion: si el caso de uso abrio la suya, esto entra en
        // ella; si nadie la abrio, se abre una propia.
        $this->connection->transaction(function () use ($workDay, $employeeId): void {
            try {
                // 1. Los retirados **primero**, y sin encadenar todavia. Mientras
                //    la version anterior siga siendo vigente para PostgreSQL, la
                //    nueva pisa su intervalo y `shift_entries_no_overlap` aborta
                //    la correccion entera (ADR-026).
                $this->persistEntries($workDay->retiredEntries(), $employeeId, $workDay->siteId());

                // 2. Ahora la version nueva cabe: el hueco esta libre.
                $this->persistEntries($workDay->entries(), $employeeId, $workDay->siteId());

                // 3. Y solo ahora se puede apuntar a ella, porque `superseded_by_id`
                //    es una clave foranea y la fila destino tiene que existir.
                $this->linkSupersededEntries($workDay);
            } catch (QueryException $exception) {
                throw $this->translate($exception, $workDay);
            }
        });
    }

    /**
     * @param  list<ShiftEntryEntity>  $entries
     */
    private function persistEntries(array $entries, int $employeeId, int $siteId): void
    {
        $rows = [];

        foreach ($entries as $entry) {
            $rows[] = $this->toRow($entry, $employeeId, $siteId);
        }

        if ($rows === []) {
            return;
        }

        // `upsert` por `uuid` y no `insert` o `update` por separado: una
        // jornada guardada trae tramos nuevos —la entrada que se acaba de
        // abrir— y tramos que cambian —el que se acaba de cerrar—, y
        // distinguirlos aqui obligaria a preguntar antes cuales existen.
        ShiftEntry::query()->upsert($rows, ['uuid'], [
            'clocked_out_at',
            'duration_minutes',
            'status',
            'clock_out_source',
            'version',
            'updated_at',
        ]);
    }

    /**
     * Encadena cada version sustituida con la que la sustituye (RL-04).
     *
     * Va en una pasada aparte y no en el `upsert` porque `superseded_by_id`
     * referencia a `shift_entries.id`, que no existe hasta que la version nueva
     * esta escrita. Se resuelve por uuid en la propia sentencia: el dominio
     * encadena versiones por identificador publico y traducirlo es trabajo de
     * esta capa.
     *
     * Una anulacion no entra aqui: no hay version posterior de un hecho que no
     * ocurrio, y por eso `superseded_by_id` se queda nulo (ADR-026).
     */
    private function linkSupersededEntries(WorkDay $workDay): void
    {
        foreach ($workDay->retiredEntries() as $entry) {
            $replacementUuid = $entry->supersededByUuid();

            if ($replacementUuid === null) {
                continue;
            }

            $replacementId = ShiftEntry::query()->where('uuid', $replacementUuid)->value('id');

            if (! is_numeric($replacementId)) {
                throw new RuntimeException(
                    'Replacement shift entry '.$replacementUuid.' was not persisted before chaining the correction.',
                );
            }

            ShiftEntry::query()
                ->where('uuid', $entry->uuid())
                ->update([
                    'superseded_by_id' => (int) $replacementId,
                    'updated_at' => $this->toTimestamp($this->clock->now()),
                ]);
        }
    }

    /**
     * Los tramos **vigentes** de esa jornada, rehidratados.
     *
     * Los `voided` y los `superseded` no entran: son historico y no forman parte
     * del agregado (ADR-026). Cargarlos haria que el recalculo de RN-06 sumara
     * dos versiones del mismo tramo y que la restriccion de exclusion pareciera
     * violada donde no lo esta.
     */
    private function load(string $employeeUuid, int $employeeId, int $siteId, WorkDate $workDate): ?WorkDay
    {
        $rows = ShiftEntry::query()
            ->where('employee_id', $employeeId)
            ->where('work_date', $workDate->isoDate)
            ->whereNotIn('status', self::historicalStatuses())
            ->orderBy('clocked_in_at')
            ->orderBy('id')
            ->get()
            ->all();

        if ($rows === []) {
            return null;
        }

        $entries = [];

        foreach ($rows as $row) {
            $entries[] = $this->toEntity($row, $employeeUuid, $workDate);
        }

        // `reconstitute` vuelve a comprobar las invariantes: una fila escrita
        // por una importacion o por una version anterior del codigo entra por
        // aqui, y cargar dos turnos abiertos sin protestar convertiria RN-01 en
        // una sugerencia el dia que hiciera falta de verdad.
        return WorkDay::reconstitute($employeeUuid, $siteId, $workDate, $entries);
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(ShiftEntryEntity $entry, int $employeeId, int $siteId): array
    {
        $clockedOutAt = $entry->clockedOutAt();
        // Del puerto `Clock` y no de `NOW()`: una expresion SQL dentro de un
        // `upsert` no se compila como valor, y ademas el reloj inyectado es lo
        // que permite fijar estas dos marcas en una prueba (regla dura 2).
        $now = $this->toTimestamp($this->clock->now());

        return [
            'uuid' => $entry->uuid(),
            'employee_id' => $employeeId,
            // El centro se guarda en el tramo y no se deduce del empleado: un
            // traslado de centro no puede reescribir donde ocurrieron las
            // jornadas anteriores.
            'site_id' => $siteId,
            'work_date' => $entry->workDate()->isoDate,
            'clocked_in_at' => $this->toTimestamp($entry->clockedInAt()),
            'clocked_out_at' => $clockedOutAt === null ? null : $this->toTimestamp($clockedOutAt),
            // Nulo mientras el tramo sigue abierto: un cero significaria «se
            // trabajaron cero minutos», que es otra cosa muy distinta de «aun no
            // se sabe».
            'duration_minutes' => $clockedOutAt === null ? null : $entry->workedDuration()->minutes,
            'status' => $entry->status()->value,
            'clock_in_source' => $entry->clockInSource()->value,
            'clock_out_source' => $entry->clockOutSource()?->value,
            'version' => $entry->version(),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function toEntity(ShiftEntry $row, string $employeeUuid, WorkDate $workDate): ShiftEntryEntity
    {
        $clockOutSource = $row->clock_out_source;

        return ShiftEntryEntity::reconstitute(
            $row->uuid,
            $employeeUuid,
            $workDate,
            $this->toUtc($row->clocked_in_at),
            $row->clocked_out_at === null ? null : $this->toUtc($row->clocked_out_at),
            ScanOrigin::from($row->clock_in_source),
            $clockOutSource === null ? null : ScanOrigin::from($clockOutSource),
            ShiftEntryStatus::from($row->status),
            $row->version,
        );
    }

    /**
     * La columna es `TIMESTAMPTZ` y PostgreSQL la devuelve en la zona de la
     * sesion; el dominio solo acepta UTC (RN-04, regla dura 3). La conversion es
     * de cristal, no de instante: `setTimezone` no mueve nada.
     */
    private function toUtc(DateTimeInterface $value): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Precision de microsegundos y desplazamiento explicito. Sin los seis
     * decimales, la hora leida no seria la escrita, y en un registro con valor
     * legal eso no es aceptable.
     */
    private function toTimestamp(DateTimeImmutable $instant): string
    {
        return $instant->format('Y-m-d H:i:s.uP');
    }

    /**
     * `employees.id` a partir del UUID publico.
     *
     * Es la unica columna de otro modulo que se lee desde aqui, y solo porque
     * `shift_entries.employee_id` es una clave foranea a ella.
     */
    private function employeeIdOf(string $employeeUuid): ?int
    {
        $id = $this->connection->table('employees')->where('uuid', $employeeUuid)->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * El UUID publico a partir de `employees.id`, el camino inverso de
     * {@see employeeIdOf()}. Lo necesita la correccion, que entra por el tramo y
     * no por el empleado.
     */
    private function employeeUuidOf(int $employeeId): ?string
    {
        $uuid = $this->connection->table('employees')->where('id', $employeeId)->value('uuid');

        return is_string($uuid) ? $uuid : null;
    }

    /**
     * El centro en el que ocurrio esa jornada, leido del propio tramo.
     */
    private function siteOf(int $employeeId, WorkDate $workDate): ?int
    {
        $siteId = ShiftEntry::query()
            ->where('employee_id', $employeeId)
            ->where('work_date', $workDate->isoDate)
            ->whereNotIn('status', self::historicalStatuses())
            ->value('site_id');

        return is_numeric($siteId) ? (int) $siteId : null;
    }

    private function timezoneOf(int $siteId): DateTimeZone
    {
        return $this->calendar->timezoneOf($siteId)
            ?? throw new RuntimeException('Site '.$siteId.' has no timezone; RN-05 cannot be resolved.');
    }

    /**
     * El predicado de ADR-026, escrito una sola vez.
     *
     * @return list<string>
     */
    private static function historicalStatuses(): array
    {
        return [ShiftEntryStatus::VOIDED->value, ShiftEntryStatus::SUPERSEDED->value];
    }

    /**
     * Las dos garantias declarativas del esquema, dichas en el lenguaje del
     * dominio.
     *
     * Cualquier otro fallo de base de datos sube tal cual: convertirlo en un
     * conflicto de negocio ocultaria un problema real.
     */
    private function translate(QueryException $exception, WorkDay $workDay): QueryException|ShiftAlreadyOpen|OverlappingShiftEntry
    {
        $message = $exception->getMessage();

        if (str_contains($message, 'one_open_shift_per_employee')) {
            return ShiftAlreadyOpen::forEmployee($workDay->employeeUuid());
        }

        if (str_contains($message, 'shift_entries_no_overlap')) {
            $open = $workDay->openEntry();

            return OverlappingShiftEntry::at(
                $workDay->employeeUuid(),
                $open?->clockedInAt() ?? new DateTimeImmutable('@0'),
            );
        }

        return $exception;
    }
}

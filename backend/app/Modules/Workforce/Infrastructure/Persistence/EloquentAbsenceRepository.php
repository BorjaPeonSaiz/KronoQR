<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure\Persistence;

use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\ValueObject\AccessScope;
use App\Modules\Workforce\Application\Port\AbsenceFilter;
use App\Modules\Workforce\Application\Port\AbsenceRepository;
use App\Modules\Workforce\Application\Port\AbsenceView;
use App\Modules\Workforce\Domain\Exception\AbsenceNotActive;
use App\Modules\Workforce\Domain\Exception\AbsenceWriteFailed;
use App\Modules\Workforce\Domain\Exception\OverlappingAbsence;
use App\Modules\Workforce\Domain\Model\Absence as AbsenceEntity;
use App\Modules\Workforce\Domain\ValueObject\AbsenceStatus;
use App\Modules\Workforce\Domain\ValueObject\AbsenceType;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Las ausencias sobre PostgreSQL (**RF-GP-04**).
 *
 * **Traduce en los dos sentidos y no deja salir una fila**: hacia arriba viajan
 * {@see AbsenceEntity} y {@see AbsenceView}, nunca el modelo Eloquent. Si el
 * caso de uso tuviera el modelo, tendria tambien `->delete()` y la tentacion de
 * usarlo — y aqui no se borra nada (regla dura 5).
 *
 * ## El solape se detecta por la excepcion de PostgreSQL
 *
 * `absences_no_overlap` es una restriccion de exclusion y su violacion llega
 * como `SQLSTATE 23P01`; traducirla aqui —y no dejarla salir como `500`— es lo
 * que convierte una carrera entre dos altas simultaneas en un `409` con
 * significado. Un `SELECT` previo seria una comprobacion con aspecto de
 * comprobacion: dos peticiones simultaneas la pasan las dos.
 *
 * ## Las lecturas van por el constructor de consultas y con `JOIN`
 *
 * Y no por Eloquent con relaciones: la pantalla necesita el codigo, el nombre y
 * el departamento de la persona en la misma fila, y resolverlos con relaciones
 * serian tres consultas por ausencia —el `N+1` con otro nombre— en un listado
 * que se abre todos los dias. Se seleccionan **columnas explicitas** y no `*`:
 * asi lo que sale de esta clase esta escrito, y nadie añade un campo a la
 * respuesta cambiando el esquema.
 *
 * ## El alcance entra en el `WHERE`
 *
 * {@see self::withinScope()}, con el mismo criterio y las mismas tres ramas que
 * {@see EloquentEmployeeRepository}: sin restriccion, «nadie» o la lista de
 * departamentos. Filtrar despues daria un `meta.total` que describe a personas
 * que quien pregunta no puede ver.
 *
 * ## `created_at` sale del puerto `Clock`
 *
 * Y no del reloj del proceso, que es la misma razon por la que el modelo lleva
 * `$timestamps = false`.
 */
final readonly class EloquentAbsenceRepository implements AbsenceRepository
{
    /** `exclusion_violation`: el `SQLSTATE` con el que PostgreSQL rechaza un solape. */
    private const string EXCLUSION_VIOLATION = '23P01';

    /** `check_violation`: cualquier `CHECK` de la tabla. */
    private const string CHECK_VIOLATION = '23514';

    /**
     * La unica restriccion cuyo incumplimiento **es** un caso de negocio: si
     * salta, alguien sustituyo esa version entre la lectura y la escritura.
     */
    private const string SUPERSEDED_CONSISTENCY = 'absences_chk_superseded_consistency';

    /**
     * Tope de versiones que se recorren hacia atras al reconstruir el historial.
     *
     * No es un limite de negocio: es el corte que impide que un ciclo en
     * `supersedes_id` —que las restricciones del esquema no pueden descartar del
     * todo— convierta la lectura de un detalle en un bucle infinito. Cien
     * correcciones de la misma ausencia no ocurren.
     */
    private const int MAX_HISTORY_DEPTH = 100;

    public function __construct(
        private Clock $clock,
        private ConnectionInterface $connection,
    ) {}

    public function findByUuid(string $uuid): ?AbsenceEntity
    {
        $row = $this->baseQuery()->where('absences.uuid', $uuid)->first();

        return $row instanceof stdClass ? $this->toEntity($row) : null;
    }

    public function findForUpdate(string $uuid): ?AbsenceEntity
    {
        /*
         * `lockForUpdate()` sobre `absences` y **no sobre el `JOIN` entero**: el
         * `baseQuery()` une `employees` y `departments` solo para traducir
         * identificadores, y `FOR UPDATE` sin `OF` bloquearia tambien la ficha
         * de la persona —dejando al alta de personal esperando por la correccion
         * de una ausencia—. Es el mismo criterio que `FOR UPDATE OF d` en la
         * proyeccion de `daily_totals`.
         *
         * Por eso la fila se toma con una consulta propia y estrecha, y despues
         * se lee por el camino de siempre: el candado ya esta puesto y la
         * segunda lectura ve lo ultimo confirmado.
         */
        $locked = $this->connection->table('absences')
            ->select('id')
            ->where('uuid', $uuid)
            ->lockForUpdate()
            ->first();

        if (! $locked instanceof stdClass) {
            return null;
        }

        return $this->findByUuid($uuid);
    }

    public function viewOf(string $uuid): ?AbsenceView
    {
        $row = $this->baseQuery()->where('absences.uuid', $uuid)->first();

        return $row instanceof stdClass ? $this->toView($row) : null;
    }

    public function historyOf(string $uuid): array
    {
        $row = $this->baseQuery()->where('absences.uuid', $uuid)->first();

        if (! $row instanceof stdClass) {
            return [];
        }

        $history = [];
        $previousId = self::optionalInt($row, 'supersedes_id');
        $depth = 0;

        // Se recorre la cadena hacia atras: no hay columna de «cadena» y no hace
        // falta, porque la longitud es el numero de veces que alguien ha
        // corregido esa ausencia. El tope existe por si el dato se corrompiera.
        while ($previousId !== null && $depth < self::MAX_HISTORY_DEPTH) {
            $previous = $this->baseQuery()->where('absences.id', $previousId)->first();

            if (! $previous instanceof stdClass) {
                break;
            }

            $history[] = $this->toView($previous);
            $previousId = self::optionalInt($previous, 'supersedes_id');
            $depth++;
        }

        // De la mas antigua a la mas reciente: es como se lee un historial, y el
        // recorrido ha ido al reves.
        return array_reverse($history);
    }

    public function countMatching(AbsenceFilter $filter): int
    {
        return $this->filtered($filter)->count('absences.id');
    }

    public function search(AbsenceFilter $filter, int $limit, int $offset): array
    {
        $rows = $this->filtered($filter)
            // De la mas reciente a la mas antigua, que es como se mira un
            // cuadro de ausencias. `id` al final para que el orden sea estable
            // entre paginas: dos ausencias del mismo dia no pueden cambiar de
            // sitio entre la pagina 1 y la 2.
            ->orderByDesc('absences.starts_on')
            ->orderByDesc('absences.id')
            ->limit($limit)
            ->offset($offset)
            ->get();

        return array_values(array_map(
            fn (mixed $row): AbsenceView => $this->toView(self::asRow($row)),
            $rows->all(),
        ));
    }

    public function activeWithin(string $employeeUuid, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $rows = $this->baseQuery()
            ->where('employees.uuid', $employeeUuid)
            ->where('absences.status', AbsenceStatus::Active->value)
            // Se tocan si empieza antes de que el rango acabe y acaba despues de
            // que el rango empiece. Los dos extremos son inclusivos.
            ->where('absences.starts_on', '<=', $to->format('Y-m-d'))
            ->where('absences.ends_on', '>=', $from->format('Y-m-d'))
            ->orderBy('absences.starts_on')
            ->get();

        return array_values(array_map(
            fn (mixed $row): AbsenceEntity => $this->toEntity(self::asRow($row)),
            $rows->all(),
        ));
    }

    public function add(AbsenceEntity $absence, ?int $registeredByUserId): AbsenceEntity
    {
        $employeeId = $this->employeeIdOf($absence->employeeUuid);

        if ($employeeId === null) {
            // El caso de uso ya ha comprobado que la persona existe, asi que
            // llegar aqui sin clave interna no es un caso de negocio: es una
            // incoherencia, y escribir la ausencia de nadie es peor que romper.
            throw new RuntimeException('No existe el empleado '.$absence->employeeUuid.' al registrar su ausencia.');
        }

        try {
            $row = Absence::query()->create([
                'uuid' => $absence->uuid,
                'employee_id' => $employeeId,
                'type' => $absence->type->value,
                'starts_on' => $absence->isoStartsOn(),
                'ends_on' => $absence->isoEndsOn(),
                'note' => $absence->note,
                'status' => $absence->status->value,
                'version' => $absence->version,
                'supersedes_id' => $this->internalIdOf($absence->supersedesUuid),
                'superseded_by_id' => $this->internalIdOf($absence->supersededByUuid),
                'change_reason' => $absence->changeReason,
                'voided_at' => $absence->voidedAt,
                'void_reason' => $absence->voidReason,
                'created_at' => $this->clock->now(),
                'created_by_user_id' => $registeredByUserId,
            ]);
        } catch (QueryException $exception) {
            // `note` viaja en los bindings de este `INSERT`: su valor entra en
            // el mensaje de la excepcion y de ahi al log (regla dura 21).
            throw $this->translate($exception, $absence, 'add');
        }

        return $absence->withId($row->id);
    }

    /**
     * Las dos mitades de una correccion, con la restriccion de exclusion
     * diferida entre ellas (**RN-13**, regla dura 5).
     *
     * ## Por que hace falta diferirla
     *
     * La fila vieja tiene que apuntar a la nueva —`absences_chk_superseded_
     * consistency` no admite «sustituida por nadie»— y la nueva no tiene clave
     * interna hasta que se inserta. Con la fila vieja todavia `active`, las dos
     * cubren los mismos dias durante un instante y `absences_no_overlap` lo
     * rechazaria. Es el caso exacto para el que existe `DEFERRABLE`.
     *
     * ## Y por que se vuelve a `IMMEDIATE` aqui y no al confirmar
     *
     * `SET CONSTRAINTS ... IMMEDIATE` **comprueba en ese momento** lo diferido.
     * Asi, si el periodo nuevo pisa una tercera ausencia activa, la violacion
     * aparece dentro de este metodo —donde se traduce a un `409` con
     * significado— y no al confirmar la transaccion, lejos de su causa y sin
     * forma de decir cual fue.
     *
     * El ambito de `SET CONSTRAINTS` es **la transaccion en curso**, que la abre
     * el caso de uso: nada de esto se filtra a la peticion siguiente.
     */
    public function supersedeWith(
        AbsenceEntity $previous,
        AbsenceEntity $corrected,
        ?int $correctedByUserId,
    ): AbsenceEntity {
        if ($previous->id === null) {
            throw new InvalidArgumentException('Solo se puede sustituir una ausencia ya persistida.');
        }

        if ($previous->status !== AbsenceStatus::Superseded) {
            // Quien llama tiene que traer la transicion ya declarada por el
            // dominio (`Absence::supersededBy()`). Si el adaptador la decidiera
            // por su cuenta, la regla dura 5 estaria escrita aqui y no donde se
            // puede probar sin base de datos.
            throw new InvalidArgumentException('La version anterior tiene que llegar ya marcada como sustituida.');
        }

        $this->connection->statement('SET CONSTRAINTS absences_no_overlap DEFERRED');

        $stored = $this->add($corrected, $correctedByUserId);

        /*
         * Las DOS UNICAS columnas que se escriben sobre una fila anterior
         * (regla dura 5): ni el tipo, ni las fechas, ni la nota, ni el autor.
         *
         * **`WHERE status = 'active'` y recuento de filas afectadas**: es la red
         * bajo el candado de `findForUpdate()`. El candado es lo que impide la
         * carrera; esto es lo que la convierte en un `409` honesto si alguna vez
         * se escribe un camino que se salte la lectura bloqueante —y lo que
         * evita que la carrera acabe en `23514` sobre
         * `absences_chk_superseded_consistency`, que es un `500` sin
         * significado—. Una escritura sin predicado de estado es una escritura
         * que cree lo que leyo hace un rato.
         */
        $afectadas = $this->connection->table('absences')
            ->where('id', $previous->id)
            ->where('status', AbsenceStatus::Active->value)
            ->update([
                'status' => AbsenceStatus::Superseded->value,
                'superseded_by_id' => $stored->id,
            ]);

        if ($afectadas === 0) {
            throw AbsenceNotActive::forAbsence($previous->uuid, AbsenceStatus::Superseded->value);
        }

        try {
            $this->connection->statement('SET CONSTRAINTS absences_no_overlap IMMEDIATE');
        } catch (QueryException $exception) {
            throw $this->translate($exception, $corrected, 'supersede');
        }

        return $stored;
    }

    public function markVoided(AbsenceEntity $absence, ?int $voidedByUserId): void
    {
        if ($absence->voidedAt === null || $absence->voidReason === null) {
            throw new InvalidArgumentException('Una anulacion lleva siempre momento y motivo.');
        }

        try {
            // Mismo predicado de estado y mismo recuento que al sustituir, y por
            // la misma razon: anular una version que otra peticion acaba de
            // sustituir tiene que ser un `409`, no un `500`.
            $afectadas = $this->rowQuery($absence)
                ->where('status', AbsenceStatus::Active->value)
                ->update([
                    'status' => AbsenceStatus::Voided->value,
                    'voided_at' => $absence->voidedAt,
                    'voided_by_user_id' => $voidedByUserId,
                    'void_reason' => $absence->voidReason,
                ]);
        } catch (QueryException $exception) {
            // `void_reason` es texto libre de quien anula: su valor va en los
            // bindings y de ahi al mensaje de la excepcion (regla dura 21).
            throw $this->translate($exception, $absence, 'void');
        }

        if ($afectadas === 0) {
            throw AbsenceNotActive::forAbsence($absence->uuid, AbsenceStatus::Superseded->value);
        }
    }

    /**
     * La consulta base: la ausencia con el codigo, el nombre y el departamento
     * de la persona.
     *
     * El `JOIN` con `employees` es interno porque `absences.employee_id` es
     * obligatorio y con clave ajena; el de `departments` es `LEFT` porque una
     * persona puede no tener departamento y su ausencia sigue existiendo.
     */
    private function baseQuery(): Builder
    {
        return $this->connection->table('absences')
            ->join('employees', 'employees.id', '=', 'absences.employee_id')
            ->leftJoin('departments', 'departments.id', '=', 'employees.department_id')
            ->select([
                'absences.id',
                'absences.uuid',
                'absences.type',
                'absences.starts_on',
                'absences.ends_on',
                'absences.note',
                'absences.status',
                'absences.version',
                'absences.supersedes_id',
                'absences.superseded_by_id',
                'absences.change_reason',
                'absences.voided_at',
                'absences.void_reason',
                'absences.created_at',
                'employees.uuid as employee_uuid',
                'employees.employee_code',
                'employees.first_name',
                'employees.last_name',
                'employees.department_id',
                'departments.name as department_name',
                // El UUID de las dos referencias de version, resueltos en la
                // misma consulta: la API habla en UUID y no en claves internas
                // (doc 01 §5.5), y resolverlos despues serian dos consultas por
                // fila en un listado.
                $this->connection->raw(
                    '(SELECT p.uuid FROM absences p WHERE p.id = absences.supersedes_id) AS supersedes_uuid'
                ),
                $this->connection->raw(
                    '(SELECT n.uuid FROM absences n WHERE n.id = absences.superseded_by_id) AS superseded_by_uuid'
                ),
            ]);
    }

    private function filtered(AbsenceFilter $filter): Builder
    {
        $query = $this->withinScope($this->baseQuery(), $filter->scope)
            // «Toca el periodo», no «empieza dentro»: una ausencia del 28 de
            // febrero al 3 de marzo tiene que aparecer preguntando por marzo.
            ->where('absences.starts_on', '<=', $filter->to->format('Y-m-d'))
            ->where('absences.ends_on', '>=', $filter->from->format('Y-m-d'));

        if ($filter->employeeUuid !== null) {
            $query->where('employees.uuid', $filter->employeeUuid);
        }

        if ($filter->departmentId !== null) {
            $query->where('employees.department_id', $filter->departmentId);
        }

        if ($filter->type instanceof AbsenceType) {
            $query->where('absences.type', $filter->type->value);
        }

        if ($filter->status instanceof AbsenceStatus) {
            $query->where('absences.status', $filter->status->value);
        }

        return $query;
    }

    /**
     * El alcance por departamento (**RF-ID-03**), **en la consulta**.
     *
     * Las mismas tres ramas que {@see EloquentEmployeeRepository::withinScope()},
     * y `whereRaw('false')` por la misma razon: un responsable sin departamento
     * asignado no alcanza a nadie, y escribirlo asi no admite otra lectura.
     *
     * **El alcance mira el departamento de la PERSONA, no el de la ausencia**:
     * una ausencia no tiene departamento propio. Quien cambia de departamento
     * cambia tambien quien puede ver sus ausencias, y es lo correcto: quien
     * organiza su turno hoy es quien necesita saber que falta.
     */
    private function withinScope(Builder $query, AccessScope $scope): Builder
    {
        if ($scope->isUnrestricted()) {
            return $query;
        }

        if ($scope->reachesNobody()) {
            return $query->whereRaw('false');
        }

        return $query->whereIn('employees.department_id', $scope->departmentIds());
    }

    private function rowQuery(AbsenceEntity $absence): Builder
    {
        if ($absence->id === null) {
            // Actualizar algo que no se ha persistido no es un caso de negocio:
            // es una incoherencia del llamante, y dejarla pasar tocaria cero
            // filas en silencio.
            throw new InvalidArgumentException('Solo se puede marcar una ausencia ya persistida.');
        }

        return $this->connection->table('absences')->where('id', $absence->id);
    }

    private function employeeIdOf(string $employeeUuid): ?int
    {
        $id = $this->connection->table('employees')->where('uuid', $employeeUuid)->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

    private function internalIdOf(?string $absenceUuid): ?int
    {
        if ($absenceUuid === null) {
            return null;
        }

        $id = $this->connection->table('absences')->where('uuid', $absenceUuid)->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

    private function toEntity(stdClass $row): AbsenceEntity
    {
        return new AbsenceEntity(
            uuid: self::text($row, 'uuid'),
            employeeUuid: self::text($row, 'employee_uuid'),
            type: AbsenceType::from(self::text($row, 'type')),
            startsOn: self::asDate(self::text($row, 'starts_on')),
            endsOn: self::asDate(self::text($row, 'ends_on')),
            note: self::optionalText($row, 'note'),
            status: AbsenceStatus::from(self::text($row, 'status')),
            version: (int) self::text($row, 'version'),
            supersedesUuid: self::optionalText($row, 'supersedes_uuid'),
            supersededByUuid: self::optionalText($row, 'superseded_by_uuid'),
            changeReason: self::optionalText($row, 'change_reason'),
            voidedAt: self::optionalMoment($row, 'voided_at'),
            voidReason: self::optionalText($row, 'void_reason'),
            id: self::optionalInt($row, 'id'),
        );
    }

    private function toView(stdClass $row): AbsenceView
    {
        return new AbsenceView(
            absence: $this->toEntity($row),
            employeeCode: self::text($row, 'employee_code'),
            // Se compone aqui y no en el `Resource` para que la pantalla no
            // tenga que decidir el orden del nombre. **No baja de la capa
            // HTTP**: no entra en `audit_log` ni en ningun log (regla dura 21).
            employeeName: trim(self::text($row, 'first_name').' '.self::text($row, 'last_name')),
            departmentId: self::optionalInt($row, 'department_id'),
            departmentName: self::optionalText($row, 'department_name'),
            // Nunca es nulo: la columna es obligatoria. El respaldo existe
            // porque el tipo de la fila lo permite, no porque el caso exista.
            createdAt: self::optionalMoment($row, 'created_at') ?? self::asDate(self::text($row, 'starts_on')),
        );
    }

    /**
     * Una fila del constructor de consultas, tipada.
     *
     * `get()` devuelve una coleccion de `mixed` para el analizador, y PHPStan 9
     * no admite darlo por hecho. Reventar aqui es preferible a dejar pasar algo
     * que no es una fila.
     */
    private static function asRow(mixed $row): stdClass
    {
        return $row instanceof stdClass
            ? $row
            : throw new RuntimeException('La consulta de ausencias ha devuelto algo que no es una fila.');
    }

    private static function text(stdClass $row, string $column): string
    {
        /** @var mixed $value */
        $value = $row->{$column} ?? null;

        return \is_scalar($value) ? (string) $value : '';
    }

    private static function optionalText(stdClass $row, string $column): ?string
    {
        /** @var mixed $value */
        $value = $row->{$column} ?? null;

        return \is_scalar($value) ? (string) $value : null;
    }

    private static function optionalInt(stdClass $row, string $column): ?int
    {
        /** @var mixed $value */
        $value = $row->{$column} ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    private static function optionalMoment(stdClass $row, string $column): ?DateTimeImmutable
    {
        $value = self::optionalText($row, $column);

        return $value === null ? null : new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    /**
     * Las fechas de una ausencia son calendario: se fijan a medianoche UTC para
     * que la comparacion sea entre fechas y no una pregunta sobre husos.
     */
    private static function asDate(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable(substr($value, 0, 10).' 00:00:00', new DateTimeZone('UTC'));
    }

    /**
     * Convierte el fallo del motor en algo que se puede responder **y que se
     * puede registrar sin filtrar nada** (regla dura 21).
     *
     * ## Ninguna `QueryException` de esta tabla sale con su mensaje
     *
     * Y es la mitad que importa de este metodo. Laravel compone el mensaje de
     * una `QueryException` con la sentencia **y los valores enlazados**: aqui
     * esos valores son la nota de una ausencia y su tipo, es decir texto libre
     * que puede llevar un diagnostico y una categoria que puede ser
     * `sick_leave` —dato de salud del art. 9 del RGPD—. Ese mensaje no llega al
     * cliente, pero **si se escribe en `storage/logs`** en cuanto la excepcion
     * sube sin controlar: `error_events` esta saneado y el log de fichero no.
     *
     * Asi que el respaldo ya no es `return $exception`: es
     * {@see AbsenceWriteFailed}, que nombra el `uuid`, la operacion y el
     * `SQLSTATE` y **cuelga la original de `previous`**, que es donde una traza
     * de depuracion la encuentra y donde el manejador de produccion no la
     * vuelca.
     *
     * ## Los dos casos que si son de negocio
     *
     * - `23P01` (`exclusion_violation`) → el periodo pisa otra ausencia activa.
     * - `23514` (`check_violation`) **sobre `absences_chk_superseded_
     *   consistency`** → alguien sustituyo esa version entre la lectura y la
     *   escritura. Es la firma de una carrera que el candado de
     *   {@see self::findForUpdate()} ya impide; se traduce igualmente porque un
     *   `409` que invita a releer es una respuesta mejor que un `500`.
     *
     * Cualquier otro `23514` —una nota mas larga de lo admitido, un periodo
     * invertido que no paso por el dominio— **no** se disfraza de conflicto: es
     * un defecto del producto y su respuesta honesta es un `500` con entrada en
     * el historico de errores.
     *
     * El nombre de la restriccion se busca **dentro** del mensaje y no se
     * propaga: leerlo no es divulgarlo.
     */
    private function translate(QueryException $exception, AbsenceEntity $absence, string $operation): Throwable
    {
        $sqlState = $exception->errorInfo[0] ?? null;

        if ($sqlState === self::EXCLUSION_VIOLATION) {
            return OverlappingAbsence::forEmployee(
                $absence->employeeUuid,
                $absence->isoStartsOn(),
                $absence->isoEndsOn(),
            );
        }

        if ($sqlState === self::CHECK_VIOLATION
            && str_contains($exception->getMessage(), self::SUPERSEDED_CONSISTENCY)) {
            return AbsenceNotActive::forAbsence($absence->uuid, AbsenceStatus::Superseded->value);
        }

        return AbsenceWriteFailed::during(
            $operation,
            $absence->uuid,
            \is_string($sqlState) ? $sqlState : 'desconocido',
            $exception,
        );
    }
}

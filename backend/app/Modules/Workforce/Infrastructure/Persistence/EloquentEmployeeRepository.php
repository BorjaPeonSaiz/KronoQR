<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure\Persistence;

use App\Modules\Shared\Domain\ValueObject\AccessScope;
use App\Modules\Shared\Domain\ValueObject\EmploymentPeriod;
use App\Modules\Shared\Domain\ValueObject\EmploymentStatus;
use App\Modules\Shared\Domain\ValueObject\NationalId;
use App\Modules\Shared\Infrastructure\Persistence\NationalIdDigest;
use App\Modules\Shared\Infrastructure\Persistence\PersonNameSearch;
use App\Modules\Workforce\Application\Port\EmployeeRepository;
use App\Modules\Workforce\Application\Port\PinStatus;
use App\Modules\Workforce\Domain\Exception\EmployeeAlreadyTerminated;
use App\Modules\Workforce\Domain\Exception\EmployeeCodeAlreadyTaken;
use App\Modules\Workforce\Domain\Exception\EmployeeEmailAlreadyTaken;
use App\Modules\Workforce\Domain\Exception\EmployeeNationalIdAlreadyTaken;
use App\Modules\Workforce\Domain\Exception\EmployeeNotTerminated;
use App\Modules\Workforce\Domain\Model\Employee as EmployeeEntity;
use App\Modules\Workforce\Domain\Model\EmploymentHistory;
use App\Modules\Workforce\Domain\Model\TerminationDateCorrection;
use App\Modules\Workforce\Domain\ValueObject\EmployeeCode;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * La plantilla sobre Eloquent y PostgreSQL.
 *
 * **Traduce en los dos sentidos y no deja salir un modelo Eloquent.** Hacia
 * arriba solo viaja {@see EmployeeEntity}: si el caso de uso tuviera el modelo,
 * tendria tambien `->save()`, `->delete()` y la tentacion de usarlos, y con eso
 * la baja logica de RF-GP-03 duraria hasta la primera prisa.
 *
 * **El documento de identidad se hashea con `pgcrypto` en la propia sentencia**
 * (RL-08, doc 02 §3.2) y con parametro enlazado, no interpolado: asi el valor en
 * claro no aparece en el texto de la consulta que podria acabar en un log lento
 * de PostgreSQL. En ningun momento existe una columna, un atributo ni un objeto
 * PHP que lo contenga.
 *
 * **Los choques de unicidad se detectan por la excepcion de PostgreSQL, no por
 * un `SELECT` previo**: entre la consulta y la insercion cabe otra alta, y esa
 * comprobacion solo daria una falsa sensacion de seguridad.
 */
final readonly class EloquentEmployeeRepository implements EmployeeRepository
{
    public function add(EmployeeEntity $employee, ?string $nationalId = null): void
    {
        // Las dos sentencias son un solo hecho: un empleado con ficha pero sin
        // digest de documento, o al reves, es un dato a medias.
        DB::transaction(function () use ($employee, $nationalId): void {
            try {
                Employee::query()->create($this->toRow($employee));
            } catch (QueryException $exception) {
                throw $this->translate($exception);
            }

            $this->openFirstPeriod($employee);

            if ($nationalId !== null) {
                $this->storeNationalIdDigest($employee->uuid, $nationalId);
            }
        });
    }

    public function findByUuid(string $uuid): ?EmployeeEntity
    {
        $row = Employee::query()->where('uuid', $uuid)->first();

        return $row instanceof Employee ? $this->toEntity($row) : null;
    }

    /**
     * `FOR NO KEY UPDATE` y no `lockForUpdate()` (ADR-046 §2): el segundo choca
     * con el `FOR KEY SHARE` con el que cada fichaje, ausencia o tarjeta de esta
     * persona comprueba su clave ajena, y la haria esperar a la baja. Solo sobre
     * `employees` y sin `JOIN`, para no bloquear de paso ninguna fila padre.
     */
    public function findForUpdate(string $uuid): ?EmployeeEntity
    {
        $row = Employee::query()->where('uuid', $uuid)->lock('for no key update')->first();

        return $row instanceof Employee ? $this->toEntity($row) : null;
    }

    public function saveProfile(EmployeeEntity $employee, bool $statusChanged): void
    {
        $columns = [
            'first_name' => $employee->firstName,
            'last_name' => $employee->lastName,
            'email' => $employee->email,
            'department_id' => $employee->departmentId,
            'locale' => $employee->locale,
            'teleworking' => $employee->teleworking,
        ];

        if ($statusChanged) {
            $columns['status'] = $employee->status->value;
        }

        try {
            $affected = $this->stillEmployed($employee)->update($columns);
        } catch (QueryException $exception) {
            throw $this->translate($exception);
        }

        $this->refuseIfNothingWritten($affected, $employee);
    }

    public function saveTermination(EmployeeEntity $employee): void
    {
        $affected = $this->stillEmployed($employee)->update([
            'status' => $employee->status->value,
            'terminated_at' => $employee->terminatedAt?->format('Y-m-d'),
        ]);

        $this->refuseIfNothingWritten($affected, $employee);
        $this->saveCurrentPeriodTermination($employee);
    }

    public function findManyForUpdate(array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }

        $rows = Employee::query()->whereIn('uuid', $uuids)->orderBy('id')->lock('for no key update')->get();

        $employees = [];

        foreach ($rows as $row) {
            $employees[$row->uuid] = $this->toEntity($row);
        }

        return $employees;
    }

    public function employmentHistory(string $uuid): EmploymentHistory
    {
        /** @var list<object{uuid: string, hired_on: string, terminated_on: string|null, offboarded: bool}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT p.uuid::text AS uuid,
                   p.hired_on::text AS hired_on,
                   p.terminated_on::text AS terminated_on,
                   (p.offboarded_at IS NOT NULL) AS offboarded
              FROM employment_periods p
              JOIN employees e ON e.id = p.employee_id
             WHERE e.uuid = ?
             ORDER BY p.hired_on
        SQL, [$uuid]);

        return new EmploymentHistory(array_map(
            static fn (object $row): EmploymentPeriod => new EmploymentPeriod(
                hiredOn: $row->hired_on,
                terminatedOn: $row->terminated_on,
                offboarded: $row->offboarded,
                uuid: $row->uuid,
            ),
            $rows,
        ));
    }

    /**
     * La ficha vuelve a estar en alta y el periodo nuevo se abre en la misma
     * transaccion (ADR-062 §1): el disparador diferido
     * `employees_current_period_matches` comprueba al confirmar que coinciden, y
     * `employees_guard_employment_transition` que la fecha de alta es posterior
     * al cese.
     */
    public function saveRehire(EmployeeEntity $employee, string $periodUuid, ?int $createdByUserId): void
    {
        $affected = Employee::query()
            ->where('uuid', $employee->uuid)
            ->where('status', EmploymentStatus::TERMINATED->value)
            ->whereNull('anonymized_at')
            ->update([
                'status' => $employee->status->value,
                'hired_at' => $employee->hiredAt->format('Y-m-d'),
                'terminated_at' => null,
                'department_id' => $employee->departmentId,
                'locale' => $employee->locale,
            ]);

        if ($affected === 0) {
            throw EmployeeNotTerminated::withUuid($employee->uuid);
        }

        DB::table('employment_periods')->insert([
            'uuid' => $periodUuid,
            'employee_id' => $this->idOf($employee->uuid),
            'hired_on' => $employee->hiredAt->format('Y-m-d'),
            'terminated_on' => null,
            'offboarded_at' => null,
            'created_by_user_id' => $createdByUserId,
        ]);
    }

    /**
     * La traza primero y el cambio despues, en la misma transaccion (RN-13): no
     * hay un instante en que la fecha nueva exista sin la anterior guardada.
     */
    public function saveTerminationCorrection(
        EmployeeEntity $employee,
        TerminationDateCorrection $correction,
        int $correctedByUserId,
        DateTimeImmutable $correctedAt,
    ): int {
        $periodId = DB::table('employment_periods')->where('uuid', $correction->period->uuid)->value('id');

        if (! is_numeric($periodId)) {
            throw new RuntimeException('El periodo de alta '.$correction->period->uuid.' no existe.');
        }

        $id = DB::table('employment_period_corrections')->insertGetId([
            'employment_period_id' => (int) $periodId,
            'previous_terminated_on' => $correction->previousTerminatedOn,
            'terminated_on' => $correction->terminatedOn,
            'reason' => $correction->reason,
            'corrected_by_user_id' => $correctedByUserId,
            'corrected_at' => $correctedAt->format('Y-m-d H:i:s.uP'),
        ]);

        DB::table('employment_periods')->where('id', (int) $periodId)->update(['terminated_on' => $correction->terminatedOn]);

        if ($correction->ofCurrentPeriod) {
            Employee::query()
                ->where('uuid', $employee->uuid)
                ->where('status', EmploymentStatus::TERMINATED->value)
                ->update(['terminated_at' => $correction->terminatedOn]);
        }

        return (int) $id;
    }

    public function rehirableUuidByNationalId(string $nationalId): ?string
    {
        $normalised = NationalId::fromInput($nationalId);

        if (! $normalised instanceof NationalId) {
            return null;
        }

        $uuid = Employee::query()
            ->whereRaw(NationalIdDigest::matches(), NationalIdDigest::bindings($normalised))
            ->where('status', EmploymentStatus::TERMINATED->value)
            ->whereNull('anonymized_at')
            ->value('uuid');

        return \is_string($uuid) ? $uuid : null;
    }

    /**
     * El periodo en curso sigue a la ficha (ADR-062 §1): la misma fecha de cese
     * y, si la baja se hace efectiva, el instante en que lo hizo. Solo el
     * periodo sin baja efectiva: `offboarded_at` no se reescribe.
     */
    private function saveCurrentPeriodTermination(EmployeeEntity $employee): void
    {
        $effective = $employee->status === EmploymentStatus::TERMINATED;

        DB::table('employment_periods')
            ->where('employee_id', $this->idOf($employee->uuid))
            ->whereNull('offboarded_at')
            ->update([
                'terminated_on' => $employee->terminatedAt?->format('Y-m-d'),
                'offboarded_at' => $effective ? DB::raw('now()') : null,
            ]);
    }

    /**
     * El periodo con el que nace la ficha (RN-27): el alta, sin cese.
     */
    private function openFirstPeriod(EmployeeEntity $employee): void
    {
        DB::table('employment_periods')->insert([
            'uuid' => Str::uuid7()->toString(),
            'employee_id' => $this->idOf($employee->uuid),
            'hired_on' => $employee->hiredAt->format('Y-m-d'),
            'terminated_on' => $employee->terminatedAt?->format('Y-m-d'),
            'offboarded_at' => $employee->status === EmploymentStatus::TERMINATED ? DB::raw('now()') : null,
        ]);
    }

    private function idOf(string $uuid): int
    {
        $id = Employee::query()->where('uuid', $uuid)->value('id');

        if (! is_numeric($id)) {
            throw new RuntimeException('El empleado '.$uuid.' no existe.');
        }

        return (int) $id;
    }

    public function dueScheduledOffboardings(string $today): array
    {
        /** @var list<string> $uuids */
        $uuids = $this->scheduledOffboardingsDueBefore($today)
            ->orderBy('id')
            ->pluck('uuid')
            ->all();

        return $uuids;
    }

    public function countScheduledOffboardingsDueBefore(string $date): int
    {
        return $this->scheduledOffboardingsDueBefore($date)->count();
    }

    /**
     * La baja programada se deriva del par estado–fecha (ADR-062 §3). La consulta
     * la sirve el indice parcial `scheduled_offboardings`, que solo tiene esas
     * filas.
     *
     * @return Builder<Employee>
     */
    private function scheduledOffboardingsDueBefore(string $date): Builder
    {
        return Employee::query()
            ->where('status', '<>', EmploymentStatus::TERMINATED->value)
            ->whereNotNull('terminated_at')
            ->where('terminated_at', '<', $date);
    }

    /**
     * La ficha por su UUID, **si no esta de baja** (ADR-046 §3).
     *
     * Las dos escrituras de la ficha llevan este predicado. Ninguna escribe
     * `id`, `uuid` ni `employee_code` (A-5): los tres tienen indice unico
     * completo, y un `UPDATE` que **cambiara** su valor tomaria `FOR UPDATE` en
     * lugar de `FOR NO KEY UPDATE` y haria esperar a los fichajes de esta
     * persona. PostgreSQL compara el valor viejo con el nuevo: escribir el mismo
     * no cambia el candado, pero no escribirlos nunca es lo que impide que un
     * cambio futuro llegue a hacerlo.
     *
     * @return Builder<Employee>
     */
    private function stillEmployed(EmployeeEntity $employee): Builder
    {
        return Employee::query()
            ->where('uuid', $employee->uuid)
            ->where('status', '<>', EmploymentStatus::TERMINATED->value);
    }

    /**
     * Cero filas es que la ficha ya estaba de baja: la lectura bloqueante del
     * caso de uso lo impide, y esto lo convierte en un `409` si algun camino se
     * la saltara. La ficha no puede haber desaparecido —nada se borra (regla
     * dura 5)—.
     *
     * @throws EmployeeAlreadyTerminated
     */
    private function refuseIfNothingWritten(int $affected, EmployeeEntity $employee): void
    {
        if ($affected === 0) {
            throw EmployeeAlreadyTerminated::withUuid($employee->uuid);
        }
    }

    public function search(
        AccessScope $scope,
        ?int $departmentId,
        ?EmploymentStatus $status,
        ?string $search,
        ?PinStatus $pinStatus,
        ?bool $teleworking,
        int $limit,
        int $offset,
    ): array {
        $rows = $this->filtered($scope, $departmentId, $status, $search, $pinStatus, $teleworking)
            // Orden estable y previsible para quien pagina: dos personas con el
            // mismo apellido no pueden cambiar de sitio entre dos paginas.
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id')
            ->limit($limit)
            ->offset($offset)
            ->get();

        return array_values(array_map($this->toEntity(...), $rows->all()));
    }

    public function countMatching(
        AccessScope $scope,
        ?int $departmentId,
        ?EmploymentStatus $status,
        ?string $search,
        ?PinStatus $pinStatus,
        ?bool $teleworking,
    ): int {
        return $this->filtered($scope, $departmentId, $status, $search, $pinStatus, $teleworking)->count();
    }

    /**
     * @return Builder<Employee>
     */
    private function filtered(
        AccessScope $scope,
        ?int $departmentId,
        ?EmploymentStatus $status,
        ?string $search,
        ?PinStatus $pinStatus,
        ?bool $teleworking,
    ): Builder {
        $query = $this->withinScope(Employee::query(), $scope)
            ->when($departmentId !== null, static fn (Builder $query): Builder => $query->where('department_id', $departmentId))
            ->when($status instanceof EmploymentStatus, static fn (Builder $query): Builder => $query->where('status', $status?->value))
            ->when($search !== null, fn (Builder $query): Builder => $this->matchingSearch($query, (string) $search))
            // Sin indice, por lo mismo que el PIN: un booleano sobre cientos de
            // filas. Informativo (RF-GP-01): filtra el listado y nada mas.
            ->when($teleworking !== null, static fn (Builder $query): Builder => $query->where('teleworking', $teleworking));

        // Fuera de la cadena de `when()` a proposito: dentro de la clausura, el
        // analizador no puede saber que `$pinStatus` no es nulo, y el `match`
        // exhaustivo que traduce el estado a columnas exige que no lo sea.
        return $pinStatus instanceof PinStatus ? $this->withPinStatus($query, $pinStatus) : $query;
    }

    /**
     * El alcance por departamento (**RF-ID-03**), **en la consulta**.
     *
     * **Aqui y no despues.** Un filtro aplicado sobre la pagina ya traida daria un
     * `meta.total` que describe a personas que quien pregunta no puede ver —una
     * fuga por si misma— y una paginacion con huecos: pedir la pagina 2 devolveria
     * tres filas de veinticinco.
     *
     * **`whereRaw('false')` no es un truco: es la traduccion literal de «no
     * alcanza a nadie».** Un responsable sin departamento asignado existe —el
     * campo `departments.manager_user_id` es nullable— y la respuesta correcta es
     * una pagina vacia. Con un `whereIn` sobre una lista vacia el resultado seria
     * el mismo, pero depender de eso es depender de un detalle del constructor de
     * consultas; escrito asi, la intencion no admite lectura.
     *
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    private function withinScope(Builder $query, AccessScope $scope): Builder
    {
        if ($scope->isUnrestricted()) {
            return $query;
        }

        if ($scope->reachesNobody()) {
            return $query->whereRaw('false');
        }

        return $query->whereIn('department_id', $scope->departmentIds());
    }

    /**
     * Filtra por situacion del PIN (RF-ID-09) **en SQL**.
     *
     * **La misma regla, escrita una sola vez.** Estas tres ramas son la
     * traduccion literal de `EloquentEmployeePinRepository::statusOf()`, que es
     * lo que decide el `pin_status` que sale en cada ficha: entregado si consta
     * la entrega, emitido si hay emision sin entrega, pendiente si no hay nada.
     * Si divergieran, el panel filtraria por un criterio y pintaria otro, y el
     * sintoma seria una fila que aparece en «pendiente» rotulada como
     * «emitido». Las invariantes de la migracion `add_pin_provisioning` sostienen
     * la equivalencia: `pin_hash` y `pin_issued_at` son nulos a la vez, y no hay
     * entrega sin emision.
     *
     * **En SQL y no en PHP** porque el filtro tiene que actuar sobre la plantilla
     * entera. Resuelto sobre la pagina ya cargada —que es lo que hacia el panel—
     * el recuento describe lo que se habia descargado y no lo que hay.
     *
     * **Sin indice, a proposito.** Son dos columnas nulas o no sobre una tabla de
     * cientos de filas (doc 02, Anexo A). Un indice parcial aqui seria estructura
     * que mantener sin nada que ganar; si la plantilla creciera de orden de
     * magnitud, la palanca es un indice parcial sobre `pin_delivered_at` y
     * `pin_issued_at`, no cambiar esta consulta.
     *
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    private function withPinStatus(Builder $query, PinStatus $pinStatus): Builder
    {
        return match ($pinStatus) {
            PinStatus::Delivered => $query->whereNotNull('pin_delivered_at'),
            PinStatus::Issued => $query->whereNull('pin_delivered_at')->whereNotNull('pin_issued_at'),
            PinStatus::Pending => $query->whereNull('pin_delivered_at')->whereNull('pin_issued_at'),
        };
    }

    /**
     * Busqueda libre por nombre, apellidos, nombre completo y codigo (RF-GP-01).
     *
     * **`ILIKE` y no `LIKE`.** `employee_code` es `citext`, asi que ahi daria
     * igual, pero `first_name` y `last_name` son `text` normal: con `LIKE`,
     * buscar «amrani» no encontraria a «Amrani», que es como lo escribe todo el
     * mundo en un cuadro de busqueda.
     *
     * **`unaccent()` a los dos lados y `%`, `_` y `\` escapados despues de
     * normalizar**, con {@see PersonNameSearch}, la misma expresion que la
     * presencia en vivo, el directorio de cuentas y la busqueda de la
     * exportacion legal. El porque de cada pieza esta alli; aqui basta saber
     * que `'García'` casa con «garcia», que un `q` de `%` o de `％` (ancho
     * completo, que `unaccent()` convierte en `%`) no es un comodin, y que el
     * termino va SIEMPRE como parametro enlazado.
     *
     * El panel de credenciales ya normalizaba acentos filtrando en el navegador;
     * los dos cuadros llevan la misma etiqueta y ahora se comportan igual.
     *
     * **El nombre completo se concatena en SQL** en vez de partir el termino por
     * espacios: quien busca escribe «Youssef Amrani» de corrido, y esa cadena no
     * esta ni en el nombre ni en el apellido por separado.
     *
     * **Sin indice y a proposito**: ver {@see PersonNameSearch}.
     *
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    private function matchingSearch(Builder $query, string $search): Builder
    {
        // `employee_code` se lleva a `text` de forma explicita: `citext` tiene
        // cast implicito, pero dejarlo escrito evita que la eleccion de
        // sobrecarga de `unaccent()` dependa de el. La condicion sale entre
        // parentesis, asi que el `OR` no se mezcla con `site_id` ni con los
        // demas filtros: se combina con ellos con `AND`.
        [$condition, $bindings] = PersonNameSearch::anyOf([
            'first_name',
            'last_name',
            'employee_code::text',
            "first_name || ' ' || last_name",
        ], $search);

        return $query->whereRaw($condition, $bindings);
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(EmployeeEntity $employee): array
    {
        return [
            'uuid' => $employee->uuid,
            'site_id' => $employee->siteId,
            'department_id' => $employee->departmentId,
            'first_name' => $employee->firstName,
            'last_name' => $employee->lastName,
            'employee_code' => $employee->code->value,
            'email' => $employee->email,
            'status' => $employee->status->value,
            'hired_at' => $employee->hiredAt->format('Y-m-d'),
            'terminated_at' => $employee->terminatedAt?->format('Y-m-d'),
            'locale' => $employee->locale,
            'teleworking' => $employee->teleworking,
        ];
    }

    private function toEntity(Employee $row): EmployeeEntity
    {
        return new EmployeeEntity(
            uuid: $row->uuid,
            code: EmployeeCode::fromString($row->employee_code),
            firstName: $row->first_name,
            lastName: $row->last_name,
            email: $row->email,
            siteId: $row->site_id,
            departmentId: $row->department_id,
            status: EmploymentStatus::from($row->status),
            hiredAt: new DateTimeImmutable($row->hired_at->format('Y-m-d')),
            terminatedAt: $row->terminated_at === null
                ? null
                : new DateTimeImmutable($row->terminated_at->format('Y-m-d')),
            locale: $row->locale,
            teleworking: $row->teleworking,
            anonymizedAt: $row->anonymized_at?->toDateTimeImmutable(),
        );
    }

    /**
     * RL-08: el digest, nunca el documento.
     *
     * `digest()` es de `pgcrypto`, que la migracion de extensiones ya habilita.
     * Se hace en SQL y no en PHP porque asi el valor en claro no llega a existir
     * como cadena en memoria mas alla de la llamada, y porque es el mismo
     * algoritmo que usa el resto del producto para esta columna.
     *
     * ## Se NORMALIZA antes de hashear
     *
     * Un hash no admite comparaciones aproximadas: `12345678-Z` y `12345678Z`
     * dan digests distintos y, para la base de datos, son dos personas. El alta
     * individual hasheaba lo que se tecleara mientras que la importacion
     * masiva ya normalizaba, asi que **dar de alta a alguien por el panel y
     * despues importarlo desde el fichero de nominas creaba una ficha
     * duplicada** — con dos codigos de empleado, dos PIN y dos tarjetas para la
     * misma persona (regla dura 5).
     *
     * La forma canonica la decide {@see NationalId} y la expresion
     * {@see NationalIdDigest}, las dos en `Shared`, y **son las unicas**: los
     * sitios que las necesitan —el alta, la importacion, la busqueda de
     * duplicados de la importacion y la de la exportacion legal (RF-IN-05)—
     * las llaman. Es idempotente, asi que aplicarla al valor que la importacion
     * ya normalizo no cambia nada.
     */
    private function storeNationalIdDigest(string $uuid, string $nationalId): void
    {
        $normalised = NationalId::fromInput($nationalId);

        if (! $normalised instanceof NationalId) {
            // Un documento que se queda en nada al normalizar —espacios y
            // guiones— no es un documento. Mejor sin digest que con el de la
            // cadena vacia, que emparejaria a todo el mundo entre si.
            return;
        }

        try {
            DB::update(
                'UPDATE employees SET national_id_hash = '.NationalIdDigest::EXPRESSION.' WHERE uuid = ?',
                [...NationalIdDigest::bindings($normalised), $uuid],
            );
        } catch (QueryException $exception) {
            // El indice unico parcial del documento: `409` como el del correo, y
            // no un `500`. Cualquier otro fallo sube tal cual.
            if (str_contains($exception->getMessage(), 'employees_national_id_hash_unique')) {
                throw EmployeeNationalIdAlreadyTaken::make();
            }

            throw $exception;
        }
    }

    private function translate(QueryException $exception): QueryException|EmployeeCodeAlreadyTaken|EmployeeEmailAlreadyTaken
    {
        $message = $exception->getMessage();

        if (str_contains($message, 'employees_employee_code_unique')) {
            return EmployeeCodeAlreadyTaken::make();
        }

        if (str_contains($message, 'employees_email_unique')) {
            return EmployeeEmailAlreadyTaken::make();
        }

        // Cualquier otro fallo de base de datos sube tal cual: convertirlo en un
        // conflicto de negocio ocultaria un problema real.
        return $exception;
    }
}

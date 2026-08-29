<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure\Persistence;

use App\Modules\Shared\Domain\ValueObject\AccessScope;
use App\Modules\Shared\Domain\ValueObject\EmploymentStatus;
use App\Modules\Workforce\Application\Port\EmployeeRepository;
use App\Modules\Workforce\Application\Port\PinStatus;
use App\Modules\Workforce\Domain\Exception\EmployeeCodeAlreadyTaken;
use App\Modules\Workforce\Domain\Exception\EmployeeEmailAlreadyTaken;
use App\Modules\Workforce\Domain\Model\Employee as EmployeeEntity;
use App\Modules\Workforce\Domain\ValueObject\EmployeeCode;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

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
                throw $this->translate($exception, $employee->code);
            }

            if ($nationalId !== null) {
                $this->storeNationalIdDigest($employee->uuid, $nationalId);
            }
        });
    }

    public function save(EmployeeEntity $employee): void
    {
        try {
            Employee::query()
                ->where('uuid', $employee->uuid)
                ->update($this->toRow($employee));
        } catch (QueryException $exception) {
            throw $this->translate($exception, $employee->code);
        }
    }

    public function findByUuid(string $uuid): ?EmployeeEntity
    {
        $row = Employee::query()->where('uuid', $uuid)->first();

        return $row instanceof Employee ? $this->toEntity($row) : null;
    }

    public function search(
        AccessScope $scope,
        ?int $departmentId,
        ?EmploymentStatus $status,
        ?string $search,
        ?PinStatus $pinStatus,
        int $limit,
        int $offset,
    ): array {
        $rows = $this->filtered($scope, $departmentId, $status, $search, $pinStatus)
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
    ): int {
        return $this->filtered($scope, $departmentId, $status, $search, $pinStatus)->count();
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
    ): Builder {
        $query = $this->withinScope(Employee::query(), $scope)
            ->when($departmentId !== null, static fn (Builder $query): Builder => $query->where('department_id', $departmentId))
            ->when($status instanceof EmploymentStatus, static fn (Builder $query): Builder => $query->where('status', $status?->value))
            ->when($search !== null, fn (Builder $query): Builder => $this->matchingSearch($query, (string) $search));

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
     * **`unaccent()` a los dos lados de la comparacion.** `ILIKE` ignora las
     * mayusculas pero no los diacriticos: `'García' ILIKE '%garcia%'` es falso,
     * y `'Garcia' ILIKE '%garcía%'` tambien. Quien escribe en el cuadro pone la
     * tilde o no la pone segun le sale, y la persona es la misma. Normalizar
     * tambien el termino, y no solo el campo, es lo que hace que funcione en los
     * dos sentidos. La extension la habilita la migracion
     * `2026_08_28_100000_enable_unaccent_extension`.
     *
     * El panel de credenciales ya normalizaba acentos filtrando en el navegador;
     * los dos cuadros llevan la misma etiqueta y ahora se comportan igual.
     *
     * **El nombre completo se concatena en SQL** en vez de partir el termino por
     * espacios: quien busca escribe «Youssef Amrani» de corrido, y esa cadena no
     * esta ni en el nombre ni en el apellido por separado.
     *
     * **`%` y `_` se escapan.** Sin esto, un `q` de `%` seria un comodin que
     * casa con la plantilla entera, y `_` casaria con cualquier caracter: el
     * usuario acabaria filtrando por una sintaxis que nadie le ha contado. Se
     * escapa tambien la propia barra invertida, que es el caracter de escape por
     * defecto de `LIKE` en PostgreSQL. `unaccent()` no toca ninguno de los tres,
     * asi que el escape sigue en pie despues de normalizar. El termino va
     * SIEMPRE como parametro enlazado, nunca interpolado en el texto de la
     * consulta.
     *
     * **Sin indice y a proposito.** `ILIKE '%…%'` no puede usar un indice btree,
     * y `unaccent()` no es `IMMUTABLE` —depende del diccionario y del
     * `search_path`—, asi que tampoco se podria indexar sin envolverla. No hace
     * falta: la plantilla de una instalacion son cientos de filas (doc 02,
     * Anexo A), no millones. Si algun dia lo fuera, la palanca es `pg_trgm` con
     * un indice GIN sobre una envoltura `IMMUTABLE`, no partir esta consulta.
     *
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    private function matchingSearch(Builder $query, string $search): Builder
    {
        $pattern = '%'.addcslashes($search, '\\%_').'%';

        // El grupo anidado no es decorativo: sin el, el primer `orWhere` se
        // aplicaria al mismo nivel que `site_id` y la busqueda dejaria de
        // combinarse con `AND` con los demas filtros.
        return $query->where(static function (Builder $group) use ($pattern): void {
            // `employee_code` se lleva a `text` de forma explicita: `citext`
            // tiene cast implicito, pero dejarlo escrito evita que la eleccion
            // de sobrecarga de `unaccent()` dependa de el.
            $group->whereRaw('unaccent(first_name) ILIKE unaccent(?)', [$pattern])
                ->orWhereRaw('unaccent(last_name) ILIKE unaccent(?)', [$pattern])
                ->orWhereRaw('unaccent(employee_code::text) ILIKE unaccent(?)', [$pattern])
                ->orWhereRaw("unaccent(first_name || ' ' || last_name) ILIKE unaccent(?)", [$pattern]);
        });
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
        );
    }

    /**
     * RL-08: el digest, nunca el documento.
     *
     * `digest()` es de `pgcrypto`, que la migracion de extensiones ya habilita.
     * Se hace en SQL y no en PHP porque asi el valor en claro no llega a existir
     * como cadena en memoria mas alla de la llamada, y porque es el mismo
     * algoritmo que usa el resto del producto para esta columna.
     */
    private function storeNationalIdDigest(string $uuid, string $nationalId): void
    {
        DB::update(
            'UPDATE employees SET national_id_hash = digest(?, ?) WHERE uuid = ?',
            [$nationalId, 'sha256', $uuid],
        );
    }

    private function translate(QueryException $exception, EmployeeCode $code): QueryException|EmployeeCodeAlreadyTaken|EmployeeEmailAlreadyTaken
    {
        $message = $exception->getMessage();

        if (str_contains($message, 'employees_employee_code_unique')) {
            return EmployeeCodeAlreadyTaken::forCode($code->value);
        }

        if (str_contains($message, 'employees_email_unique')) {
            return EmployeeEmailAlreadyTaken::make();
        }

        // Cualquier otro fallo de base de datos sube tal cual: convertirlo en un
        // conflicto de negocio ocultaria un problema real.
        return $exception;
    }
}

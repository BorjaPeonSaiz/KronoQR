<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure\Persistence;

use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Workforce\Application\Port\EmploymentContractRepository;
use App\Modules\Workforce\Domain\Exception\OverlappingEmploymentContract;
use App\Modules\Workforce\Domain\Model\EmploymentContract as ContractEntity;
use App\Modules\Workforce\Domain\ValueObject\ScheduleType;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Los contratos sobre Eloquent y PostgreSQL (RF-GP-02).
 *
 * **Traduce en los dos sentidos y no deja salir un modelo Eloquent**, igual que
 * {@see EloquentEmployeeRepository}: hacia arriba solo viaja
 * {@see ContractEntity}.
 *
 * **El solape se detecta por la excepcion de PostgreSQL**, no por un `SELECT`
 * previo. `employment_contracts_no_overlap` es una restriccion de exclusion y su
 * violacion llega como `SQLSTATE 23P01`; traducirla aqui —y no dejarla salir
 * como `500`— es lo que convierte una carrera entre dos altas simultaneas en un
 * `409` con significado.
 *
 * **`created_at` sale del puerto `Clock`** y no del reloj del proceso: es la
 * misma razon por la que el modelo lleva `$timestamps = false`. Sin eso, una
 * prueba con el reloj congelado escribiria una fecha distinta de la del evento
 * que la acompaña.
 */
final readonly class EloquentEmploymentContractRepository implements EmploymentContractRepository
{
    /** `exclusion_violation`: el `SQLSTATE` con el que PostgreSQL rechaza un solape. */
    private const string EXCLUSION_VIOLATION = '23P01';

    public function __construct(private Clock $clock) {}

    public function forEmployee(string $employeeUuid): array
    {
        $rows = EmploymentContract::query()
            ->whereIn('employee_id', $this->employeeIdQuery($employeeUuid))
            // Del mas antiguo al mas reciente: es como se lee una serie
            // historica, y `valid_from` es unica por empleado gracias a la
            // restriccion de exclusion, asi que el orden es estable.
            ->orderBy('valid_from')
            ->get()
            ->all();

        return array_values(array_map(
            fn (EmploymentContract $row): ContractEntity => $this->toEntity($row, $employeeUuid),
            $rows,
        ));
    }

    public function openContractFor(string $employeeUuid): ?ContractEntity
    {
        $row = EmploymentContract::query()
            ->whereIn('employee_id', $this->employeeIdQuery($employeeUuid))
            ->whereNull('valid_to')
            ->first();

        return $row instanceof EmploymentContract ? $this->toEntity($row, $employeeUuid) : null;
    }

    public function add(ContractEntity $contract, ?int $registeredByUserId): int
    {
        $employeeId = $this->employeeIdOf($contract->employeeUuid);

        if ($employeeId === null) {
            // El caso de uso ya ha comprobado que la persona existe, asi que
            // llegar aqui sin clave interna no es un caso de negocio: es una
            // incoherencia, y escribir el contrato de nadie es peor que romper.
            throw new RuntimeException('No existe el empleado '.$contract->employeeUuid.' al registrar su contrato.');
        }

        try {
            $row = EmploymentContract::query()->create([
                'employee_id' => $employeeId,
                'weekly_hours' => $contract->weeklyHours,
                'annual_hours' => $contract->annualHours,
                'schedule_type' => $contract->scheduleType->value,
                'valid_from' => $contract->isoValidFrom(),
                'valid_to' => $contract->isoValidTo(),
                'created_at' => $this->clock->now(),
                'created_by_user_id' => $registeredByUserId,
            ]);
        } catch (QueryException $exception) {
            throw $this->translate($exception, $contract);
        }

        return $row->id;
    }

    public function close(ContractEntity $contract): void
    {
        if ($contract->id === null) {
            // Cerrar algo que no se ha persistido no es un caso de negocio: es
            // una incoherencia del llamante, y dejarla pasar cerraria en
            // silencio cero filas.
            throw new InvalidArgumentException('Solo se puede cerrar un contrato ya persistido.');
        }

        EmploymentContract::query()
            ->whereKey($contract->id)
            ->update(['valid_to' => $contract->isoValidTo()]);
    }

    /**
     * La clave interna del empleado como **subconsulta**, para no traerla.
     *
     * Se selecciona una sola columna y por el UUID publico: la clave interna no
     * cruza hacia el dominio (doc 01 §5.5) y traer la ficha entera para leer los
     * contratos de alguien pondria su nombre y su correo en memoria de algo que
     * no los necesita (regla dura 21).
     *
     * @return Builder<Employee>
     */
    private function employeeIdQuery(string $employeeUuid): Builder
    {
        return Employee::query()->select('id')->where('uuid', $employeeUuid);
    }

    private function employeeIdOf(string $employeeUuid): ?int
    {
        /** @var int|null $id */
        $id = Employee::query()->where('uuid', $employeeUuid)->value('id');

        return $id;
    }

    private function toEntity(EmploymentContract $row, string $employeeUuid): ContractEntity
    {
        return new ContractEntity(
            employeeUuid: $employeeUuid,
            weeklyHours: (float) $row->weekly_hours,
            annualHours: $row->annual_hours === null ? null : (float) $row->annual_hours,
            scheduleType: ScheduleType::from($row->schedule_type),
            validFrom: $this->asDate($row->valid_from->format('Y-m-d')),
            validTo: $row->valid_to === null ? null : $this->asDate($row->valid_to->format('Y-m-d')),
            id: $row->id,
        );
    }

    private function asDate(string $isoDate): DateTimeImmutable
    {
        return new DateTimeImmutable($isoDate.' 00:00:00', new DateTimeZone('UTC'));
    }

    private function translate(QueryException $exception, ContractEntity $contract): Throwable
    {
        if (($exception->errorInfo[0] ?? null) === self::EXCLUSION_VIOLATION) {
            return OverlappingEmploymentContract::forEmployee(
                $contract->employeeUuid,
                $contract->isoValidFrom(),
            );
        }

        return $exception;
    }
}

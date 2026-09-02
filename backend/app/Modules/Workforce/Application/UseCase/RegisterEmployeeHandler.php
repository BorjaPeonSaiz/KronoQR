<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\Exception\InstallationSiteMissing;
use App\Modules\Workforce\Application\Command\IssueEmployeePinCommand;
use App\Modules\Workforce\Application\Command\RegisterEmployeeCommand;
use App\Modules\Workforce\Application\Port\EmployeeRepository;
use App\Modules\Workforce\Application\Port\SiteRepository;
use App\Modules\Workforce\Application\Port\WorkforceEventPublisher;
use App\Modules\Workforce\Domain\Event\EmployeeHired;
use App\Modules\Workforce\Domain\Exception\EmployeeCodeAlreadyTaken;
use App\Modules\Workforce\Domain\Model\Employee;
use App\Modules\Workforce\Domain\Model\Site;
use App\Modules\Workforce\Domain\ValueObject\EmployeeCode;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Random\RandomException;
use RuntimeException;

/**
 * Alta de empleado (RF-GP-01) con su PIN (RF-ID-09).
 *
 * **El correo no hace falta.** El alta se completa sin el y ninguna
 * comprobacion de este caso de uso lo exige (regla dura 12, ADR-015): el acceso
 * al portal personal se resuelve despues con codigo de empleado y PIN.
 *
 * **El PIN se emite aqui y en la misma transaccion.** Un empleado sin PIN no
 * puede fichar por respaldo (RF-AT-11) ni entrar a su registro horario (RL-05),
 * y ese estado no debe poder existir: si la emision falla, el alta tampoco se
 * confirma. Es la razon por la que este caso de uso abre transaccion —antes no
 * la necesitaba— y por la que devuelve {@see RegisteredEmployee} en lugar de la
 * ficha sola.
 *
 * **El codigo se genera aqui y se reintenta contra el UNIQUE.** No hay `SELECT`
 * previo que pregunte si existe: entre la consulta y la insercion cabe otra alta
 * simultanea, y esa comprobacion daria una falsa sensacion de seguridad. Se
 * intenta insertar, y si PostgreSQL rechaza el choque se genera otro codigo. Con
 * 31^9 combinaciones, la probabilidad de un segundo choque es despreciable; los
 * tres intentos son una red, no una expectativa. El reintento sigue funcionando
 * dentro de la transaccion exterior porque la insercion corre en su propia
 * transaccion anidada, que en PostgreSQL es un `SAVEPOINT`: el choque revierte
 * hasta ahi y no aborta el alta entera.
 */
final readonly class RegisterEmployeeHandler
{
    /**
     * Tres intentos: uno es optimista, infinitos serian un bucle que nadie ve.
     */
    private const int MAX_CODE_ATTEMPTS = 3;

    public function __construct(
        private EmployeeRepository $employees,
        private SiteRepository $sites,
        private WorkforceEventPublisher $events,
        private Clock $clock,
        private IssueEmployeePinHandler $pins,
        private ConnectionInterface $connection,
    ) {}

    /**
     * @throws InstallationSiteMissing antes de la puesta en marcha: sin centro no hay alta (ADR-040)
     * @throws RandomException si el sistema no puede dar aleatoriedad para el PIN
     */
    public function handle(RegisterEmployeeCommand $command): RegisteredEmployee
    {
        // El centro no viene en el comando: es el de la instalacion (ADR-040).
        // Se resuelve fuera de la transaccion porque no cambia dentro de ella
        // y porque sin el no hay nada que abrir.
        $site = $this->sites->installationSite();

        if (! $site instanceof Site || $site->id === null) {
            throw InstallationSiteMissing::make();
        }

        $siteId = $site->id;

        return $this->connection->transaction(function () use ($command, $siteId): RegisteredEmployee {
            $employee = $this->persistWithFreshCode($command, $siteId);

            $pin = $this->pins->handle(new IssueEmployeePinCommand(
                employeeUuid: $employee->uuid,
                siteId: $employee->siteId,
                reset: false,
                // Precalculado por la importacion masiva y nulo en el alta
                // individual, que lo genera dentro: es lo que saca los 160 ms de
                // bcrypt de la transaccion sin cambiar ninguna garantia.
                material: $command->pinMaterial,
            ));

            if (! $pin instanceof IssuedPin) {
                // La fila se acaba de escribir en esta misma transaccion, asi
                // que no encontrarla no es un caso de negocio: es una
                // incoherencia, y un alta sin PIN es justo lo que esta tarea
                // existe para impedir.
                throw new RuntimeException('El alta no ha podido emitir el PIN del empleado '.$employee->uuid.'.');
            }

            // Dentro de la transaccion, al contrario que antes de la tarea 1.13:
            // el asiento de `audit_log` del alta y el del PIN son sincronos y
            // tienen que poder impedir el alta si fallan (ADR-027, regla dura 6).
            $this->events->publish(new EmployeeHired(
                employeeUuid: $employee->uuid,
                siteId: $employee->siteId,
                departmentId: $employee->departmentId,
                occurredAt: $this->clock->now(),
            ));

            return new RegisteredEmployee($employee, $pin);
        });
    }

    private function persistWithFreshCode(RegisterEmployeeCommand $command, int $siteId): Employee
    {
        $lastFailure = null;

        for ($attempt = 1; $attempt <= self::MAX_CODE_ATTEMPTS; $attempt++) {
            $employee = $this->buildEmployee($command, $siteId, EmployeeCode::generate());

            try {
                $this->employees->add($employee, $command->nationalId);

                return $employee;
            } catch (EmployeeCodeAlreadyTaken $collision) {
                $lastFailure = $collision;
            }
        }

        throw new RuntimeException(
            'No se ha podido generar un codigo de empleado libre en '.self::MAX_CODE_ATTEMPTS.' intentos.',
            previous: $lastFailure,
        );
    }

    private function buildEmployee(RegisterEmployeeCommand $command, int $siteId, EmployeeCode $code): Employee
    {
        return Employee::hire(
            // UUID v7 y no v4: es ordenable temporalmente, lo que mantiene la
            // localidad de los indices que lo referencian (doc 02 §6).
            uuid: Str::uuid7()->toString(),
            code: $code,
            firstName: $command->firstName,
            lastName: $command->lastName,
            email: $command->email,
            siteId: $siteId,
            departmentId: $command->departmentId,
            hiredAt: new DateTimeImmutable($command->hiredAt),
            locale: $command->locale,
        );
    }
}

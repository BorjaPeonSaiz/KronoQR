<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\Exception\InstallationSiteMissing;
use App\Modules\Workforce\Application\Command\IssueEmployeePinCommand;
use App\Modules\Workforce\Application\Command\PinProvisioning;
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
 * **El alta individual emite el PIN aqui y en la misma transaccion.** Un
 * empleado sin PIN no puede fichar por respaldo (RF-AT-11) ni entrar a su
 * registro horario (RL-05), y quien da el alta tiene a la persona delante para
 * entregarselo: si la emision falla, el alta tampoco se confirma. Es la razon
 * por la que este caso de uso abre transaccion —antes no la necesitaba— y por
 * la que devuelve {@see RegisteredEmployee} en lugar de la ficha sola.
 *
 * **La importacion masiva lo deja pendiente** (RF-GP-05,
 * {@see PinProvisioning::DeferredToCardHandover}). Un PIN que se muestra una
 * sola vez no cabe en un informe de quinientas filas, y nadie lo entregaria: se
 * emite desde la ficha al entregar la tarjeta. No es un alta distinta —mismo
 * codigo, mismo `EmployeeHired`, mismo asiento—, y el pendiente queda a la
 * vista en el listado (`pin_status=pending`). Lo decide el comando por su
 * nombre, nunca un nulo, y solo la importacion puede pedirlo.
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
        private EmployeeWriteRetry $retry,
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

        // EL bcrypt, ANTES DE ABRIR LA TRANSACCION (ADR-046 §1.1 punto 5, A-3),
        // y solo si el alta emite. Dentro correria con la cadena de `audit_log`
        // tomada y congelaria los fichajes del hotel unos 160 ms. Con el PIN
        // diferido —la importacion masiva— no hay hash que calcular: es lo que
        // deja una importacion de 500 altas sin ningun bcrypt.
        $material = $command->pin === PinProvisioning::IssueNow ? $this->pins->freshMaterial() : null;

        return $this->retry->run('employee.register', function () use ($command, $siteId, $material): RegisteredEmployee {
            // La insercion va antes de la cadena (ADR-046 §1.2): toma
            // `FOR KEY SHARE` sobre el centro y el departamento —filas padre— y
            // la fila nueva no la ve nadie hasta el commit.
            $employee = $this->persistWithFreshCode($command, $siteId);

            $pin = $material === null ? null : $this->pins->handle(new IssueEmployeePinCommand(
                employeeUuid: $employee->uuid,
                siteId: $employee->siteId,
                reset: false,
                material: $material,
            ));

            if ($material !== null && ! $pin instanceof IssuedPin) {
                // La fila se acaba de escribir en esta misma transaccion, asi
                // que no encontrarla no es un caso de negocio: es una
                // incoherencia. Un alta que debia emitir y no emite dejaria a
                // alguien sin PIN sin que nadie lo hubiera decidido, que es
                // justo lo que el pendiente explicito existe para evitar.
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
                // Se propaga tal cual: decide quien cuenta el uso del plan —una
                // vez por lote y no una por fila (ADR-028, H-04 de la 3.8)—. Que
                // el alta importada no deje `pin.issued` ya cuenta en el trail
                // que su PIN quedo pendiente.
                viaImport: $command->viaImport,
                // El valor inicial, para que el asiento del alta diga con que
                // marca nacio la ficha (RF-GP-01, AUD-2).
                teleworking: $employee->teleworking,
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
            teleworking: $command->teleworking,
        );
    }
}

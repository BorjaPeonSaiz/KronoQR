<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

use App\Modules\Identity\Application\Port\CredentialRepository;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\EmployeeRegistry;
use App\Modules\Shared\Application\Port\EmploymentStatusLookup;
use App\Modules\Shared\Application\Port\SerializedLedgerWrite;
use App\Modules\Shared\Application\Support\UseCaseInstrumentation;
use App\Modules\Shared\Domain\ValueObject\EmploymentStatus;
use Throwable;

/**
 * **Las tarjetas que se quedaron activas en bajas anteriores a la 2.2.0**
 * (R3-AR-07, R4-SC-11; tarea 12.4 de la 2.3.0).
 *
 * Desde la 2.2.0 la baja revoca sus tarjetas en la misma transaccion
 * ({@see RevokeCredentialsOfOffboardedEmployee}). Las bajas registradas antes
 * conservan las suyas «activas»: no dejan fichar (RN-14 mira el estado laboral),
 * pero salen como activas en el panel y en el inventario de la clave de firma.
 *
 * ## Por que una orden y no una migracion
 *
 * Cada revocacion deja su asiento `credential.revoked` en `audit_log` (regla dura
 * 6), y una migracion no puede escribirlo: corre con el rol de migracion, no con
 * el de la aplicacion, y la particion del periodo puede no existir todavia
 * (`2026_08_30_100100_grant_read_ability.php`). Lo lanza `update.sh` despues de
 * migrar, como aplicacion (decision del propietario del 22-10-2026).
 *
 * ## Que hace
 *
 * Para cada persona **de baja** con alguna tarjeta activa, el mismo caso de uso
 * que la baja de hoy: mismo motivo (`RevokeCredentialsOfOffboardedEmployee::REASON`),
 * un asiento por tarjeta y el portal cerrado. **La fecha de revocacion es la de
 * la ejecucion**, no la del cese: antedatarla diria que la tarjeta se retiro
 * cuando no se retiro.
 *
 * Una persona por transaccion, cada una con el candado de la cadena tomado
 * **antes** que la fila de la tarjeta ({@see SerializedLedgerWrite}): el orden de
 * todo lo que audita, y sin retener el candado de los fichajes durante todo el
 * barrido.
 *
 * ## Idempotente
 *
 * Solo selecciona tarjetas activas: la segunda pasada no encuentra nada y no
 * escribe ningun asiento.
 */
final readonly class RevokeLeftoverCredentialsOfOffboardedEmployees
{
    public function __construct(
        private CredentialRepository $credentials,
        private EmploymentStatusLookup $employmentStatus,
        private EmployeeRegistry $employees,
        private RevokeCredentialsOfOffboardedEmployee $revoke,
        private SerializedLedgerWrite $serialized,
        private Clock $clock,
    ) {}

    /**
     * Personas de baja tratadas, tarjetas revocadas y personas cuya revocacion
     * fallo, con su excepcion.
     *
     * @return array{employees: int, credentials: int, failed: int, failures: list<Throwable>}
     */
    public function handle(): array
    {
        return UseCaseInstrumentation::run(
            'identity.revoke_leftover_credentials_of_offboarded_employees',
            $this->sweep(...),
            static fn (array $result): array => [
                'employee_count' => $result['employees'],
                'revoked_count' => $result['credentials'],
                'failed_count' => $result['failed'],
            ],
        );
    }

    /**
     * @return array{employees: int, credentials: int, failed: int, failures: list<Throwable>}
     */
    private function sweep(): array
    {
        $holders = $this->credentials->employeeIdsWithActiveCredentials();
        $statuses = $holders === [] ? [] : $this->employmentStatus->statusesOf($holders);
        $employees = 0;
        $revoked = 0;
        $failures = [];

        foreach ($holders as $employeeId) {
            if (($statuses[$employeeId] ?? null) !== EmploymentStatus::TERMINATED) {
                continue;
            }

            $employeeUuid = $this->employees->uuidOf($employeeId);

            if ($employeeUuid === null) {
                continue;
            }

            // Una persona que falla no detiene a las demas: su transaccion se
            // revierte entera y la excepcion vuelve a quien llama, que la
            // notifica (`report()`, con el saneado de `error_events`). Repetir
            // la orden la vuelve a intentar.
            try {
                $count = $this->serialized->withChainLock(
                    fn (): int => $this->revoke->handle($employeeUuid, $this->clock->now()),
                );
            } catch (Throwable $exception) {
                $failures[] = $exception;

                continue;
            }

            if ($count > 0) {
                $employees++;
                $revoked += $count;
            }
        }

        return ['employees' => $employees, 'credentials' => $revoked, 'failed' => \count($failures), 'failures' => $failures];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Console;

use App\Modules\Identity\Application\Command\IssueCredentialCommand as IssueCredential;
use App\Modules\Identity\Application\UseCase\IssueCredential as IssueCredentialHandler;
use App\Modules\Identity\Domain\Exception\EmployeeAlreadyHasCredential;
use App\Modules\Identity\Domain\Exception\IdentityDomainException;
use Illuminate\Console\Command;

/**
 * `php artisan credentials:issue {employee}` — emite una credencial y la deja
 * pendiente de imprimir (Anexo C del doc 02, RF-QR-01, RF-QR-03).
 *
 * **Por que existe ademas del endpoint.** El alta masiva de una temporada se
 * hace por consola —cuarenta personas en una tarde—, la puesta en marcha de una
 * instalacion todavia no tiene panel, y una incidencia se resuelve por SSH sin
 * navegador. Es el mismo caso de uso: no hay una segunda implementacion que
 * pueda divergir.
 *
 * **Este comando no enseña ningun QR, y no puede** (ADR-034). El token se acuña
 * al imprimir la tarjeta —`credentials:print`, tarea 1.10—, no aqui. La version
 * anterior volcaba el payload en la terminal con un aviso; el aviso no evitaba
 * que quedara en el historial del interprete, en el buffer de la sesion SSH y en
 * el registro de cualquier guion que lo llamara. Emitir es ahora una anotacion
 * administrativa sin nada que filtrar.
 *
 * El actor de la auditoria es el sistema: una consola no tiene sesion. Queda
 * escrito como tal en `audit_log`, que es lo honesto — no atribuir la accion a
 * una persona que no la hizo.
 */
final class IssueCredentialCommand extends Command
{
    protected $signature = 'credentials:issue
        {employee : UUID del empleado}
        {--reissue : Revoca la credencial activa anterior y emite otra}
        {--reason= : Motivo de la revocacion de la anterior. Obligatorio con --reissue}';

    protected $description = 'Emite una credencial QR y la deja pendiente de imprimir (RF-QR-01).';

    public function handle(IssueCredentialHandler $handler): int
    {
        $employeeUuid = trim((string) $this->argument('employee'));
        $reissue = (bool) $this->option('reissue');
        $reason = $this->option('reason');

        if ($employeeUuid === '') {
            $this->error('Hace falta el UUID del empleado.');

            return self::INVALID;
        }

        try {
            $issued = $handler->handle(new IssueCredential(
                employeeUuid: $employeeUuid,
                reissue: $reissue,
                reason: \is_string($reason) ? $reason : null,
            ));
        } catch (EmployeeAlreadyHasCredential $exception) {
            $this->error($exception->getMessage());
            $this->line('Usa --reissue --reason="motivo" para sustituirla.');

            return self::FAILURE;
        } catch (IdentityDomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($issued === null) {
            $this->error('No hay ningun empleado con ese UUID.');

            return self::FAILURE;
        }

        $this->info(($issued->reissue ? 'Credencial reemitida' : 'Credencial emitida').': '.$issued->credential->uuid);
        $this->line('Estado: pendiente de imprimir. Nadie puede fichar con ella todavia.');
        $this->line('El QR se acuña al imprimir: php artisan credentials:print '.$this->argument('employee'));

        return self::SUCCESS;
    }
}

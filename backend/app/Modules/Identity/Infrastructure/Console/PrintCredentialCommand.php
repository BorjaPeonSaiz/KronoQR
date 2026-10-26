<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Console;

use App\Modules\Identity\Application\Command\PrintCredentialCommand as PrintCredential;
use App\Modules\Identity\Application\UseCase\PrintCredential as PrintCredentialHandler;
use App\Modules\Identity\Domain\Exception\IdentityDomainException;
use App\Modules\Shared\Infrastructure\Console\SpeaksInstallationLanguage;
use Illuminate\Console\Command;

/**
 * `php artisan credentials:print {employee}` — el PDF de **una** tarjeta, en
 * formato 85,6 x 54 mm (Anexo C del doc 02, RF-QR-04).
 *
 * **Por que existe ademas del endpoint.** La puesta en marcha de una instalacion
 * todavia no tiene panel, una incorporacion de ultima hora se resuelve por SSH, y
 * quien esta delante de la terminal sabe **a quien** le falta la tarjeta, no que
 * credencial le corresponde: por eso el argumento es el UUID del **empleado** y
 * no el de la credencial. Es el mismo caso de uso que el endpoint; no hay una
 * segunda implementacion que pueda divergir.
 *
 * **No hay `--force` y no puede haberlo** (ADR-034). Una credencial ya impresa
 * hace fallar el comando. Reponer una tarjeta es revocar, reemitir e imprimir la
 * nueva: tres actos, tres asientos de auditoria.
 *
 * **`--out` es obligatorio y el PDF no se escribe en ningun sitio por defecto.**
 * Un PDF de tarjeta es un instrumento al portador: quien lo tenga puede fichar
 * por su dueño. Volcarlo por la salida estandar lo dejaria en el buffer de la
 * sesion SSH y en el registro de cualquier guion; escribirlo «donde toque» sin
 * que nadie lo pida lo dejaria olvidado en el servidor. El operador dice donde y
 * **el runbook manda borrarlo despues de imprimir**.
 *
 * El actor de la auditoria es el sistema: una consola no tiene sesion. Queda
 * escrito como tal en `audit_log`, que es lo honesto.
 */
final class PrintCredentialCommand extends Command
{
    use SpeaksInstallationLanguage;

    /** Seccion de `lang/*\/console.php`, compartida por las ordenes `credentials:*`. */
    private const string TEXTS = 'console.credentials';

    protected $signature = 'credentials:print
        {employee : UUID del empleado}
        {--out= : Ruta del fichero PDF que se va a escribir. Obligatoria}';

    protected $description = 'Genera el PDF de la tarjeta de un empleado, en formato tarjeta de credito (RF-QR-04).';

    public function handle(PrintCredentialHandler $handler): int
    {
        $target = $this->option('out');

        $employeeUuid = trim($this->argument('employee'));
        $out = \is_string($target) ? trim($target) : '';

        if ($employeeUuid === '') {
            $this->error($this->text('employee_required'));

            return self::INVALID;
        }

        if ($out === '') {
            $this->error($this->text('print.out_required'));

            return self::INVALID;
        }

        try {
            $printed = $handler->handle(PrintCredential::forEmployee($employeeUuid));
        } catch (IdentityDomainException $exception) {
            $this->error($exception->getMessage());
            $this->line($this->text('print.no_reprint'));

            return self::FAILURE;
        }

        if ($printed === null) {
            $this->error($this->text('print.no_active_credential'));
            $this->line($this->text('print.issue_first', ['employee' => $employeeUuid]));

            return self::FAILURE;
        }

        if (file_put_contents($out, $printed->pdf) === false) {
            // La credencial YA esta impresa en la base de datos y su token es
            // irrecuperable: es el riesgo residual de ADR-034 materializado. Hay
            // que decirlo con todas las letras, porque la salida se resuelve
            // revocando y reemitiendo, no reintentando.
            $this->error($this->text('print.not_written', ['path' => $out]));
            $this->line($this->text('print.printed_one'));
            $this->line($this->text('print.runbook'));

            return self::FAILURE;
        }

        $this->info($this->text('print.generated', ['path' => $out]));
        $this->line($this->text('print.credential', ['uuid' => $printed->credentials[0]->credential->uuid]));
        $this->line($this->text('print.handle_with_care'));
        $this->line($this->text('print.next', ['uuid' => $printed->credentials[0]->credential->uuid]));

        return self::SUCCESS;
    }
}

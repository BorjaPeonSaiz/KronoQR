<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Console;

use App\Modules\Identity\Application\Command\PrintCredentialCommand as PrintCredential;
use App\Modules\Identity\Application\UseCase\PrintCredential as PrintCredentialHandler;
use App\Modules\Identity\Domain\Exception\IdentityDomainException;
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
            $this->error('Hace falta el UUID del empleado.');

            return self::INVALID;
        }

        if ($out === '') {
            $this->error('Hace falta --out=/ruta/credencial.pdf: este comando no elige donde dejar un PDF de tarjeta.');

            return self::INVALID;
        }

        try {
            $printed = $handler->handle(PrintCredential::forEmployee($employeeUuid));
        } catch (IdentityDomainException $exception) {
            $this->error($exception->getMessage());
            $this->line('No hay reimpresion (ADR-034). Para reponer una tarjeta: revocar, reemitir e imprimir la nueva.');

            return self::FAILURE;
        }

        if ($printed === null) {
            $this->error('Ese empleado no existe o no tiene ninguna credencial activa.');
            $this->line('Emitela primero: php artisan credentials:issue '.$employeeUuid);

            return self::FAILURE;
        }

        if (file_put_contents($out, $printed->pdf) === false) {
            // La credencial YA esta impresa en la base de datos y su token es
            // irrecuperable: es el riesgo residual de ADR-034 materializado. Hay
            // que decirlo con todas las letras, porque la salida se resuelve
            // revocando y reemitiendo, no reintentando.
            $this->error('El PDF se genero pero no se ha podido escribir en «'.$out.'».');
            $this->line('La credencial consta IMPRESA y su token es irrecuperable.');
            $this->line('Sigue docs/runbooks/tarjeta-perdida-o-rota.md: revocar con motivo «impresion fallida» y reemitir.');

            return self::FAILURE;
        }

        $this->info('Tarjeta generada: '.$out);
        $this->line('Credencial: '.$printed->credentials[0]->credential->uuid);
        $this->line('Imprime, plastifica y BORRA el fichero: es un instrumento al portador.');
        $this->line('Despues, registra la entrega: php artisan credentials:deliver '.$printed->credentials[0]->credential->uuid.' --by=<correo>');

        return self::SUCCESS;
    }
}

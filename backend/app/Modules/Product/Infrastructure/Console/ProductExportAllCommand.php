<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Console;

use App\Modules\Product\Application\Command\RequestDataExportCommand;
use App\Modules\Product\Application\UseCase\GenerateDataExportHandler;
use App\Modules\Product\Application\UseCase\PurgeExpiredDataExportsHandler;
use App\Modules\Product\Application\UseCase\RequestDataExportHandler;
use App\Modules\Product\Domain\Exception\DataExportAlreadyInProgress;
use App\Modules\Product\Domain\Model\DataExport;
use App\Modules\Product\Domain\ValueObject\DataExportOrigin;
use App\Modules\Shared\Infrastructure\Format\ByteSize;
use Illuminate\Console\Command;
use Throwable;

/**
 * `php artisan product:export-all` — la exportacion integra desde la consola
 * (**RF-PD-14**, RL-20, tarea 5.10).
 *
 * ## Existe porque el panel no siempre sirve
 *
 * Tres casos reales, y ninguno es raro:
 *
 * 1. **El fichero es enorme.** El panel lo descarga entero en la memoria del
 *    navegador antes de guardarlo; por encima de un giga conviene generarlo aqui
 *    y sacarlo con `docker compose cp`.
 * 2. **Nadie puede entrar al panel.** Una contraseña perdida, un segundo factor
 *    con el movil roto. RL-20 no puede depender de que la puerta de delante este
 *    abierta.
 * 3. **Se quiere automatizar.** Una copia trimestral desde el `cron` del cliente.
 *
 * ## Sincrona y en primer plano, al contrario que el panel
 *
 * Quien esta delante de la terminal quiere ver la ruta del fichero antes de
 * irse, y aqui no hay ningun tiempo de espera de HTTP que agotar.
 *
 * ## Registra su fila igual que el panel
 *
 * Con `requested_via = console` y sin usuario: ahi no hay sesion que atribuir. La
 * exportacion aparece en la lista del panel y se puede descargar desde el, y
 * deja sus tres asientos en `audit_log` como cualquier otra. Un fichero con
 * todos los datos personales de la plantilla no se genera sin dejar rastro por
 * el hecho de generarse por SSH (RS-05).
 *
 * ## `--purge` no genera nada
 *
 * Borra los ficheros vencidos y marca sus filas. Es lo que el planificador
 * ejecuta cada hora; a mano sirve para hacer sitio en el disco sin esperar.
 *
 * ## Codigos de salida
 *
 * | Codigo | Significado |
 * |---|---|
 * | `0` | La exportacion se genero, o la purga termino. |
 * | `1` | No se pudo generar. El mensaje dice que fallo y la fila queda en `failed`. |
 * | `2` | Ya hay una exportacion en curso. Espera a que termine. |
 *
 * ## Funciona con la licencia caducada, ausente o ilegible (regla dura 15)
 *
 * Es exactamente la situacion para la que existe (ADR-019).
 */
final class ProductExportAllCommand extends Command
{
    protected $signature = 'product:export-all
        {--purge : No genera nada: borra los ficheros de exportaciones ya caducadas y marca sus filas}';

    protected $description = 'Exporta TODOS los datos de la instalacion a un ZIP con un CSV por tabla, o purga los caducados';

    public function handle(
        RequestDataExportHandler $request,
        GenerateDataExportHandler $generate,
        PurgeExpiredDataExportsHandler $purge,
    ): int {
        if ($this->option('purge') === true) {
            return $this->purge($purge);
        }

        try {
            $requested = $request->handle(new RequestDataExportCommand(
                requestedVia: DataExportOrigin::Console,
                requestedByUserId: null,
            ));
        } catch (DataExportAlreadyInProgress $conflict) {
            $this->line('Ya hay una exportacion integra en curso ('.($conflict->current->uuid ?? 'sin identificar').').');
            $this->line('Espera a que termine y vuelve a ejecutarlo, o miralas con:');
            $this->line('  php artisan tinker --execute="dump(DB::table(\'data_exports\')->latest(\'id\')->first());"');

            return 2;
        }

        $this->line('Generando la exportacion integra. Recorre todas las tablas: puede tardar varios minutos.');

        try {
            $export = $generate->handle($requested->uuid);
        } catch (Throwable $failure) {
            // La clase y no el mensaje, por lo mismo que en la columna
            // `failure_reason`: un error de base de datos puede llevar dentro el
            // valor de una fila (regla dura 21).
            $this->line('No se pudo generar la exportacion integra: '.$failure::class.'.');
            $this->line('La causa mas frecuente es falta de espacio en disco o permisos del directorio de');
            $this->line('exportaciones. Compruebalos con «df -h» y «ls -ld» sobre PRODUCT_DATA_EXPORT_PATH,');
            $this->line('y ejecuta «php artisan product:doctor» para ver el resto del estado de la instalacion.');

            return self::FAILURE;
        }

        return $this->report($export);
    }

    private function report(DataExport $export): int
    {
        if ($export->filePath === null || $export->fileName === null) {
            $this->line('La exportacion termino sin fichero. Revisa el estado con «php artisan product:doctor».');

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('Exportacion integra generada.');
        $this->newLine();
        $this->line('  Fichero:    '.$export->filePath);
        $this->line('  Tamaño:     '.ByteSize::human($export->sizeBytes ?? 0));
        $this->line('  SHA-256:    '.($export->sha256 ?? ''));
        $this->line('  Identificador: '.$export->uuid);
        $this->line('  Caduca:     '.($export->expiresAt?->format('Y-m-d H:i').' UTC'));
        $this->newLine();

        $this->line('Filas por fichero:');

        foreach ($export->rowCounts as $file => $rows) {
            $this->line('  '.str_pad($file, 28).number_format($rows, 0, ',', '.'));
        }

        $this->newLine();
        $this->line('Para sacarlo del contenedor, desde el directorio de la instalacion y en el servidor:');
        $this->line('  docker compose cp app:'.$export->filePath.' ./'.$export->fileName);
        $this->newLine();
        $this->line('Para comprobar que llego entero:');
        $this->line('  sha256sum ./'.$export->fileName);
        $this->newLine();
        $this->line('El fichero contiene TODOS los datos personales de la plantilla. Guardalo donde guardas');
        $this->line('el resto de la informacion laboral. Aqui se borra solo al caducar.');

        return self::SUCCESS;
    }

    private function purge(PurgeExpiredDataExportsHandler $purge): int
    {
        $report = $purge->handle();

        $this->line($report->purged === 0
            ? 'No hay ninguna exportacion integra caducada que purgar.'
            : 'Purgadas '.$report->purged.' exportaciones integras caducadas. Las filas se conservan con su estado.');

        if ($report->released > 0) {
            /*
             * Se dice en voz alta y no se calla: una exportacion atascada estaba
             * impidiendo pedir la siguiente, y quien lea esto tiene que saber que
             * el producto se ha desbloqueado solo —y que si se repite cada hora,
             * lo que hay que mirar es el trabajador de cola—.
             */
            $this->line('Liberadas '.$report->released.' exportaciones que se quedaron a medias '
                .'(motivo «stale»): ya se puede pedir una nueva.');
        }

        return self::SUCCESS;
    }
}

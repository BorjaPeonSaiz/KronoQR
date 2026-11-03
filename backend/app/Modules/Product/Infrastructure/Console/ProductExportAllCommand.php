<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Console;

use App\Modules\Product\Application\Command\RequestDataExportCommand;
use App\Modules\Product\Application\Exception\DataExportSignerNotAllowed;
use App\Modules\Product\Application\Support\DataExportSigners;
use App\Modules\Product\Application\UseCase\GenerateDataExportHandler;
use App\Modules\Product\Application\UseCase\PurgeExpiredDataExportsHandler;
use App\Modules\Product\Application\UseCase\RequestDataExportHandler;
use App\Modules\Product\Application\UseCase\SweepExpiredDiagnosticsBundles;
use App\Modules\Product\Domain\Exception\DataExportAlreadyInProgress;
use App\Modules\Product\Domain\Model\DataExport;
use App\Modules\Product\Domain\ValueObject\DataExportOrigin;
use App\Modules\Shared\Application\Port\ResponsibleAccounts;
use App\Modules\Shared\Domain\Support\UtcInstant;
use App\Modules\Shared\Domain\ValueObject\ResponsibleAccount;
use App\Modules\Shared\Infrastructure\Console\ConsoleResponsible;
use App\Modules\Shared\Infrastructure\Console\SpeaksInstallationLanguage;
use App\Modules\Shared\Infrastructure\Format\ByteSize;
use Illuminate\Console\Command;
use Throwable;

/**
 * `php artisan product:export-all` — la exportacion integra desde la consola
 * (**RF-PD-14**, RL-20).
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
 * ## Registra su fila igual que el panel, firmada por quien la pide
 *
 * Con `requested_via = console` y, desde la 2.3.0, a nombre de la cuenta `admin`
 * de `--responsible` (doc 01 §7.2, «Firmas por consola»): la orden enseña la
 * cuenta (`Firma: nombre <correo> · rol · uuid`) y la persona confirma que es
 * ella —con `--force` o sin terminal, con `--responsible-confirm=<correo>`—, asi
 * que la fila y sus asientos la nombran, como cuando se pide desde el panel.
 * Firma quien puede pedirla desde el panel: solo `admin` (`DataExportSigners`).
 * La exportacion aparece en la lista del panel y se puede descargar desde el, y
 * deja sus asientos en `audit_log` como cualquier otra. Un fichero con todos
 * los datos personales de la plantilla no se genera sin dejar rastro por el
 * hecho de generarse por SSH (RS-05). El caso de uso vuelve a comprobar la
 * cuenta con la cadena tomada: una baja entre la pregunta y la firma no pide
 * nada.
 *
 * Un `cron` del cliente que la automatice la firma igual, sin terminal:
 * `--responsible=<correo> --responsible-confirm=<correo>`.
 *
 * ## `--purge` no genera nada
 *
 * Borra los ficheros vencidos y marca sus filas, y concilia fila y fichero en
 * los dos sentidos (ADR-045): la fila cuyo ZIP ya no esta pasa a `purged`, y el
 * ZIP o el `.work-<uuid>/` que ninguna fila viva nombra se borra al superar su
 * edad minima. Todo dentro de `PRODUCT_DATA_EXPORT_PATH` y por patron exacto de
 * nombre. Es lo que el planificador ejecuta cada hora; a mano sirve para hacer
 * sitio en el disco sin esperar. **No lleva firma**: no exporta nada, y la
 * pasada horaria no tiene a nadie detras.
 *
 * En la misma pasada barre los paquetes de diagnostico de mas de
 * `PRODUCT_DIAGNOSTICS_RETENTION_DAYS` dias (ADR-045 §g, condicion C4): es la pasada
 * horaria de los ficheros del modulo, y un paquete pedido con datos personales
 * no puede esperar a que alguien genere el siguiente para desaparecer.
 *
 * ## Codigos de salida
 *
 * | Codigo | Significado |
 * |---|---|
 * | `0` | La exportacion se genero, o la purga termino. |
 * | `1` | No se pudo generar —el mensaje dice que fallo y la fila queda en `failed`—, o la persona responde que no a la firma. |
 * | `2` | Ya hay una exportacion en curso; o falta `--responsible`, la cuenta no es `admin` activa (al preguntar o al firmar), o falta o no cuadra `--responsible-confirm`. |
 *
 * Con la firma rechazada (`1` por el «no», o `2`) no se ha pedido nada: ni fila
 * ni asiento.
 *
 * ## Funciona con la licencia caducada, ausente o ilegible (regla dura 15)
 *
 * Es exactamente la situacion para la que existe (ADR-019).
 */
final class ProductExportAllCommand extends Command
{
    use SpeaksInstallationLanguage;

    /** Seccion de `lang/*\/console.php`. */
    private const string TEXTS = 'console.product_export_all';

    protected $signature = 'product:export-all
        {--purge : No genera nada: borra los ficheros caducados, marca sus filas y concilia fila y fichero}
        {--responsible= : Uuid o correo de la cuenta admin que pide la exportacion y la firma. Obligatorio salvo con --purge}
        {--force : No preguntar por la firma; exige --responsible-confirm}
        {--responsible-confirm= : Correo de la cuenta de --responsible. Obligatorio con --force o sin terminal}';

    protected $description = 'Exporta TODOS los datos de la instalacion a un ZIP con un CSV por tabla, o purga los caducados';

    public function handle(
        RequestDataExportHandler $request,
        GenerateDataExportHandler $generate,
        PurgeExpiredDataExportsHandler $purge,
        SweepExpiredDiagnosticsBundles $diagnostics,
        ResponsibleAccounts $accounts,
    ): int {
        if ($this->option('purge') === true) {
            return $this->purge($purge, $diagnostics);
        }

        $responsible = $this->signedBy($accounts);

        if (\is_int($responsible)) {
            return $responsible;
        }

        try {
            $requested = $request->handle(new RequestDataExportCommand(
                requestedVia: DataExportOrigin::Console,
                // La cuenta que ha confirmado su firma: la fila y sus asientos
                // son suyos, como cuando se pide desde el panel.
                requestedByUserId: $responsible->id,
            ));
        } catch (DataExportSignerNotAllowed) {
            // La cuenta se dio de baja o perdio el rol entre la pregunta y la
            // firma: no se ha pedido ni escrito nada.
            return ConsoleResponsible::noLongerAllowed($this);
        } catch (DataExportAlreadyInProgress $conflict) {
            $this->paragraph('in_progress', ['uuid' => $conflict->current->uuid ?? $this->text('unidentified')]);

            return 2;
        }

        $this->line($this->text('generating'));

        try {
            $export = $generate->handle($requested->uuid);
        } catch (Throwable $failure) {
            // La clase y no el mensaje, por lo mismo que en la columna
            // `failure_reason`: un error de base de datos puede llevar dentro el
            // valor de una fila (regla dura 21).
            $this->paragraph('failed', ['error' => $failure::class]);

            return self::FAILURE;
        }

        return $this->report($export);
    }

    /**
     * La cuenta `admin` que firma la exportacion, o el codigo de salida con el
     * motivo ya escrito: `2` si falta `--responsible`, la cuenta no puede
     * firmar o falta o no cuadra `--responsible-confirm`; `1` si la persona
     * responde que no. En ningun caso se ha pedido ni escrito nada.
     */
    private function signedBy(ResponsibleAccounts $accounts): ResponsibleAccount|int
    {
        // Los roles que la piden desde el panel (`DataExportPolicy`): la
        // consola no es la via por la que alguien hace lo que el panel le niega.
        return ConsoleResponsible::sign($this, $this->input, $accounts, DataExportSigners::ROLES, $this->text('confirm'));
    }

    private function report(DataExport $export): int
    {
        if ($export->filePath === null || $export->fileName === null) {
            $this->line($this->text('without_file'));

            return self::FAILURE;
        }

        $this->newLine();
        $this->line($this->text('report.title'));
        $this->newLine();
        $this->line($this->text('report.file', ['path' => $export->filePath]));
        $this->line($this->text('report.size', ['size' => ByteSize::human($export->sizeBytes ?? 0)]));
        $this->line($this->text('report.digest', ['digest' => $export->sha256 ?? '']));
        $this->line($this->text('report.uuid', ['uuid' => $export->uuid]));
        $this->line($this->text('report.expires', ['at' => $export->expiresAt === null ? '' : UtcInstant::wallTime($export->expiresAt)]));
        $this->newLine();

        $this->line($this->text('report.rows'));

        foreach ($export->rowCounts as $file => $rows) {
            $this->line('  '.str_pad($file, 28).number_format($rows, 0, ',', '.'));
        }

        $this->newLine();
        $this->paragraph('report.copy_out', ['path' => $export->filePath, 'name' => $export->fileName]);
        $this->newLine();
        $this->paragraph('report.check', ['name' => $export->fileName]);
        $this->newLine();
        $this->paragraph('report.personal_data');

        return self::SUCCESS;
    }

    private function purge(PurgeExpiredDataExportsHandler $purge, SweepExpiredDiagnosticsBundles $diagnostics): int
    {
        $report = $purge->handle();
        $bundles = $diagnostics->handle();

        $this->line($report->purged === 0
            ? $this->installationText('generated-files.data_exports.nothing_expired')
            : $this->installationText('generated-files.data_exports.purged', ['count' => $report->purged]));

        if ($report->released > 0) {
            /*
             * Se dice en voz alta y no se calla: una exportacion atascada estaba
             * impidiendo pedir la siguiente, y quien lea esto tiene que saber que
             * el producto se ha desbloqueado solo —y que si se repite cada hora,
             * lo que hay que mirar es el trabajador de cola—.
             */
            $this->line($this->installationText('generated-files.data_exports.released', ['count' => $report->released]));
        }

        if ($report->orphans > 0) {
            // Sin nombres ni rutas, igual que el log: solo la cifra (regla dura 21).
            $this->line($this->installationText('generated-files.data_exports.orphans', ['count' => $report->orphans]));
        }

        if ($report->missing > 0) {
            /*
             * En voz alta: un ZIP con la plantilla entera ha desaparecido antes
             * de caducar. Tras una restauracion es lo esperado; fuera de eso,
             * alguien lo borro o se lo llevo, y el asiento de auditoria es lo
             * que permite acotar cuando.
             */
            $this->line($this->installationText('generated-files.data_exports.missing', ['count' => $report->missing]));
        }

        if ($bundles > 0) {
            // Solo la cifra: ni nombres de fichero ni rutas (regla dura 21).
            $this->line($this->installationText('generated-files.data_exports.diagnostics', ['count' => $bundles]));
        }

        return self::SUCCESS;
    }
}

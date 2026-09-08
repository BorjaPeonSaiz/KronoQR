<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Console;

use App\Modules\Product\Application\Port\DiagnosticsBundleWriter;
use App\Modules\Product\Application\UseCase\GenerateDiagnosticsBundleHandler;
use App\Modules\Product\Domain\ValueObject\DiagnosticsActor;
use App\Modules\Product\Domain\ValueObject\DiagnosticsBundle;
use App\Modules\Product\Domain\ValueObject\DiagnosticsOptions;
use App\Modules\Shared\Application\Port\Clock;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Throwable;

/**
 * `php artisan product:diagnostics` — genera el paquete de diagnostico (Anexo C,
 * **RF-PD-09**, RL-19, ADR-020).
 *
 * ## Anonimizado por defecto, y `--anonymized` es solo un alias explicito
 *
 * Ejecutarlo sin banderas da el paquete anonimizado. `--anonymized` existe
 * porque el Anexo C lo nombra y porque quien lo escribe en un runbook quiere
 * dejar constancia de que lo eligio, pero **no cambia nada**: el valor por
 * defecto ya es ese. Que el defecto sea el seguro es la mitad de la promesa de
 * ADR-020.
 *
 * ## `--with-personal-data` es una accion distinta (RL-19)
 *
 * Pide confirmacion antes de nada —salvo con `--no-interaction`, que es como lo
 * ejecuta un script—, avisa en texto llano de que se incluye y deja un asiento
 * propio en `audit_log`. Nunca es un efecto secundario de otra opcion.
 *
 * ## `--verify=RUTA` no genera nada
 *
 * Relee un paquete escrito antes y recalcula la huella del documento sin su
 * `manifest`. Existe porque el paquete **viaja sin cifrar a proposito** —el
 * cliente tiene que poder abrirlo antes de enviarlo— y un fichero en claro se
 * trunca: un cliente de correo que corta a los 5 MB, un copiar y pegar
 * incompleto. Sin esta comprobacion, soporte se pasaria una hora diagnosticando
 * un paquete a medias.
 *
 * ## Codigos de salida
 *
 * | Codigo | Significado |
 * |---|---|
 * | `0` | El paquete se genero, o el que se verifico esta integro. |
 * | `1` | No se pudo escribir el fichero. El mensaje dice donde y por que. |
 * | `2` | Con `--verify`: la huella no coincide, o el fichero no es un paquete. |
 *
 * ## Funciona con la licencia caducada o ausente (regla dura 15)
 */
final class ProductDiagnosticsCommand extends Command
{
    protected $signature = 'product:diagnostics
        {--anonymized : Alias explicito del comportamiento por defecto: sin datos personales}
        {--with-personal-data : Incluye plantilla, fichajes e incidencias. Accion distinta y auditada (RL-19)}
        {--period-days=7 : Dias de fichajes que abarca --with-personal-data}
        {--output= : Fichero o directorio donde escribirlo. Por defecto, storage/app/diagnostics}
        {--verify= : No genera nada: comprueba la huella de un paquete ya escrito}';

    protected $description = 'Genera el paquete de diagnostico anonimizado que se envia a soporte, o verifica uno ya generado';

    public function handle(
        GenerateDiagnosticsBundleHandler $diagnostics,
        DiagnosticsBundleWriter $writer,
        Clock $clock,
        Config $config,
    ): int {
        $verify = $this->option('verify');

        if (is_string($verify) && $verify !== '') {
            return $this->verify($writer, $verify);
        }

        $purged = $this->purge($writer, $clock, $config);

        $options = $this->requestedOptions($config);

        if ($options === null) {
            return self::SUCCESS;
        }

        if ($options->includePersonalData && ! $this->confirmed($options)) {
            $this->line('No se ha generado nada.');

            return self::SUCCESS;
        }

        $bundle = $diagnostics->handle($options, DiagnosticsActor::Console);

        return $this->write($writer, $bundle, $options, $purged);
    }

    private function requestedOptions(Config $config): ?DiagnosticsOptions
    {
        if ($this->option('with-personal-data') !== true) {
            return DiagnosticsOptions::anonymized();
        }

        $maximum = max(1, $config->integer('product.diagnostics_personal_data_max_period_days', 31));
        $requested = $this->option('period-days');
        $days = is_numeric($requested) ? (int) $requested : DiagnosticsOptions::DEFAULT_PERIOD_DAYS;

        if ($days < 1 || $days > $maximum) {
            $this->line('El periodo tiene que estar entre 1 y '.$maximum.' dias; has pedido '.$days.'.');
            $this->line('Vuelve a ejecutarlo con, por ejemplo: php artisan product:diagnostics '
                .'--with-personal-data --period-days=7');

            return null;
        }

        return DiagnosticsOptions::withPersonalData($days);
    }

    /**
     * El aviso previo, en texto llano y antes de generar nada (RL-19).
     *
     * ## Sin terminal, la bandera **es** la confirmacion
     *
     * Con `--no-interaction` no hay nadie a quien preguntar, y `confirm()`
     * devolveria el valor por defecto —`false`—, es decir, un comando que no
     * hace nada y no dice por que. El aviso se imprime igual, porque acaba en el
     * registro de quien lanzo el script.
     *
     * Y RL-19 se sigue cumpliendo entero: la accion **es** distinta —hay que
     * escribir `--with-personal-data`, que nunca es el valor por defecto ni el
     * efecto secundario de otra opcion—, va avisada y deja su propio asiento en
     * `audit_log`. La pregunta interactiva es una red de seguridad para la
     * persona que esta delante, no la fuente de la legitimidad.
     */
    private function confirmed(DiagnosticsOptions $options): bool
    {
        $this->line('');
        $this->line('ATENCION: vas a generar un paquete CON DATOS PERSONALES.');
        $this->line('----------------------------------------------------');
        $this->line('Ademas de la informacion tecnica de siempre, el fichero incluira:');
        $this->line('  - La plantilla: nombre, codigo de empleado, estado y departamento.');
        $this->line('  - Los fichajes y los tramos de los ultimos '.$options->periodDays.' dias.');
        $this->line('  - Las incidencias abiertas.');
        $this->line('');
        $this->line('Que implica:');
        $this->line('  - El fichero deja de estar anonimizado y no se debe enviar sin necesidad.');
        $this->line('  - La accion queda registrada en el registro de auditoria, con quien la hizo.');
        $this->line('  - Si se lo envias al proveedor, este actua como encargado del tratamiento');
        $this->line('    para ese supuesto (RGPD art. 28). Necesitas el contrato de encargo firmado.');
        $this->line('');
        $this->line('Si solo quieres diagnosticar un problema tecnico, no hace falta: ejecuta');
        $this->line('  php artisan product:diagnostics');
        $this->line('');

        return ! $this->input->isInteractive()
            || $this->confirm('¿Seguro que quieres incluir los datos personales?', false);
    }

    private function write(
        DiagnosticsBundleWriter $writer,
        DiagnosticsBundle $bundle,
        DiagnosticsOptions $options,
        int $purged,
    ): int {
        $output = $this->option('output');

        try {
            $path = $writer->write($bundle, is_string($output) && $output !== '' ? $output : null);
        } catch (Throwable $failure) {
            $this->line('No se ha podido escribir el paquete.');
            $this->line($failure->getMessage());

            return self::FAILURE;
        }

        $this->line('');

        if ($purged > 0) {
            $this->line('Se han borrado '.$purged.' paquete(s) anterior(es) por antiguedad. Un paquete de');
            $this->line('diagnostico es material caducado en cuanto se envia, y el que lleva datos');
            $this->line('personales no debe quedarse en el disco (RL-19).');
            $this->line('');
        }

        $this->line('Paquete de diagnostico generado.');
        $this->line('');
        $this->line('  Fichero:  '.$path);
        $this->line('  Tamaño:   '.number_format((float) \strlen($bundle->toJson()) / 1024, 1).' KB');
        $this->line('  Huella:   '.$bundle->manifest->sha256);
        $this->line('  Contiene: '.implode(', ', $bundle->manifest->sections));
        $this->line('  Anonimo:  '.($bundle->manifest->anonymized ? 'si' : 'NO — lleva datos personales'));
        $this->line('');
        $this->line('Antes de enviarlo, abrelo y comprueba que no lleva nada que no quieras enviar:');
        $this->line('  less '.$path);
        $this->line('');
        $this->line('Quien lo reciba puede comprobar que no se ha alterado por el camino con:');
        $this->line('  php artisan product:diagnostics --verify='.$path);

        if (! $options->includePersonalData) {
            $this->line('');
            $this->line('Este paquete va anonimizado: no lleva nombres, ni correos, ni fichajes,');
            $this->line('ni contraseñas, ni claves. Los empleados aparecen solo como identificador.');
        }

        $this->line('');

        return self::SUCCESS;
    }

    /**
     * Borra los paquetes anteriores que ya han caducado, antes de escribir uno
     * nuevo (**RL-19**).
     *
     * ## Por que aqui y no en una tarea programada
     *
     * Porque este comando es el unico momento en el que alguien esta mirando. Una
     * tarea nocturna que borrase ficheros del disco del cliente por su cuenta
     * seria una sorpresa; hacerlo al generar convierte el borrado en parte de un
     * acto que la persona acaba de pedir, y ademas lo dice en la salida.
     *
     * ## Por que hay que borrarlos
     *
     * Un paquete es material caducado el dia que se envia. El anonimizado ocupa
     * sitio sin aportar nada; el que se pidio con datos personales es **una copia
     * de la plantilla y de los fichajes de un periodo**, en el disco del cliente
     * y sin fecha de caducidad. RL-19 autoriza a generarlo para una incidencia
     * concreta, no a conservarlo para siempre.
     *
     * Un fallo del borrado no impide generar: quedarse sin diagnostico porque no
     * se pudo limpiar un fichero viejo seria el peor intercambio posible.
     */
    private function purge(DiagnosticsBundleWriter $writer, Clock $clock, Config $config): int
    {
        $days = max(1, $config->integer('product.diagnostics_retention_days', 7));

        try {
            return $writer->purgeOlderThan($clock->now()->modify('-'.$days.' days'));
        } catch (Throwable) {
            return 0;
        }
    }

    private function verify(DiagnosticsBundleWriter $writer, string $path): int
    {
        $document = $writer->read($path);

        if ($document === null) {
            $this->line('No se ha podido leer un paquete de diagnostico en '.$path.'.');
            $this->line('Comprueba que la ruta existe, que es legible y que el fichero es el JSON completo:');
            $this->line('un fichero cortado por el correo no se puede verificar.');

            return 2;
        }

        if (! DiagnosticsBundle::digestMatches($document)) {
            $this->line('LA HUELLA NO COINCIDE: '.$path);
            $this->line('');
            $this->line('El fichero no es el que se genero, o le falta parte. La causa mas frecuente es');
            $this->line('que se corto al enviarlo por correo. Pide al cliente que lo vuelva a generar y');
            $this->line('lo envie comprimido, o por el portal de tickets.');

            return 2;
        }

        /** @var array<string, mixed> $manifest */
        $manifest = is_array($document['manifest'] ?? null) ? $document['manifest'] : [];

        $this->line('Paquete integro: '.$path);
        $this->line('  Version:   '.self::text($manifest, 'product_version'));
        $this->line('  Generado:  '.self::text($manifest, 'generated_at').' por '.self::text($manifest, 'generated_by'));
        $this->line('  Anonimo:   '.(($manifest['anonymized'] ?? null) === true ? 'si' : 'NO — lleva datos personales'));
        $this->line('  Huella:    '.self::text($manifest, 'sha256'));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private static function text(array $manifest, string $key): string
    {
        $value = $manifest[$key] ?? null;

        return is_scalar($value) ? (string) $value : '—';
    }
}

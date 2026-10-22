<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Console;

use App\Modules\Product\Application\Exception\DiagnosticsResponsibleNotAllowed;
use App\Modules\Product\Application\Port\DiagnosticsBundleWriter;
use App\Modules\Product\Application\Port\ProbeFailureClassifier;
use App\Modules\Product\Application\Support\DiagnosticsSigners;
use App\Modules\Product\Application\UseCase\DeliverDiagnosticsBundle;
use App\Modules\Product\Application\UseCase\GenerateDiagnosticsBundleHandler;
use App\Modules\Product\Application\UseCase\RecordPendingDiagnosticsAudits;
use App\Modules\Product\Application\UseCase\SweepExpiredDiagnosticsBundles;
use App\Modules\Product\Domain\Exception\DiagnosticsAuditNotDeferred;
use App\Modules\Product\Domain\ValueObject\DiagnosticsActor;
use App\Modules\Product\Domain\ValueObject\DiagnosticsBundle;
use App\Modules\Product\Domain\ValueObject\DiagnosticsDigestCheck;
use App\Modules\Product\Domain\ValueObject\DiagnosticsOptions;
use App\Modules\Shared\Application\Port\ResponsibleAccounts;
use App\Modules\Shared\Domain\ValueObject\ResponsibleAccount;
use App\Modules\Shared\Infrastructure\Console\ConsoleResponsible;
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
 * Exige `--responsible`, la cuenta `admin` que lo autoriza (su `uuid` o su
 * correo), enseña quien firma y pide confirmacion antes de nada —sin terminal,
 * `--responsible-confirm=<correo>` de esa cuenta—, avisa en texto llano de que
 * se incluye y deja un asiento propio en `audit_log` **firmado por esa cuenta**,
 * no por `system` (2.3.0 bloque 3, PR17; doc 01 §7.2). Nunca es un efecto
 * secundario de otra opcion.
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
 * | `1` | No se genero: no se pudo escribir el fichero, no se pudo dejar constancia en `audit_log` (ni ahora ni como pendiente), o se respondio que no a la confirmacion de los datos personales. El mensaje dice cual y que hacer. |
 * | `2` | Con `--verify`: la huella no coincide, o el fichero no es un paquete. Con `--with-personal-data`: periodo fuera de rango, falta `--responsible`, la cuenta no es `admin` activa (al preguntar o al firmar), o sin terminal falta o no cuadra `--responsible-confirm`. |
 *
 * ## Funciona con la licencia caducada o ausente (regla dura 15)
 *
 * ## Y con la base de datos caida, solo el anonimizado (ADR-020, enmienda del 14-10-2026)
 *
 * El paquete anonimizado se genera y se escribe con su asiento pendiente, y lo
 * dice. El que lleva datos personales no se genera si no se puede auditar
 * (RL-19). Al arrancar, escribe los asientos pendientes de generaciones
 * anteriores si la base ya responde.
 */
final class ProductDiagnosticsCommand extends Command
{
    protected $signature = 'product:diagnostics
        {--anonymized : Alias explicito del comportamiento por defecto: sin datos personales}
        {--with-personal-data : Incluye plantilla, fichajes e incidencias. Accion distinta y auditada (RL-19)}
        {--period-days=7 : Dias de fichajes que abarca --with-personal-data}
        {--responsible= : Con --with-personal-data, uuid o correo de la cuenta admin que lo autoriza y firma el asiento. Obligatorio}
        {--responsible-confirm= : Correo de la cuenta de --responsible. Obligatorio sin terminal}
        {--output= : Fichero o directorio donde escribirlo. Por defecto, storage/app/diagnostics}
        {--verify= : No genera nada: comprueba la huella de un paquete ya escrito}';

    protected $description = 'Genera el paquete de diagnostico anonimizado que se envia a soporte, o verifica uno ya generado';

    public function handle(
        GenerateDiagnosticsBundleHandler $diagnostics,
        DiagnosticsBundleWriter $writer,
        SweepExpiredDiagnosticsBundles $sweep,
        Config $config,
        RecordPendingDiagnosticsAudits $recordPending,
        DeliverDiagnosticsBundle $delivery,
        ProbeFailureClassifier $failures,
        ResponsibleAccounts $accounts,
    ): int {
        $verify = $this->option('verify');

        if (is_string($verify) && $verify !== '') {
            return $this->verify($writer, $verify);
        }

        $purged = $this->purge($sweep);

        $this->recordPending($recordPending);

        $options = $this->requestedOptions($config);

        if ($options === null) {
            // Periodo fuera de rango: es un error de uso, como el resto (2).
            return self::INVALID;
        }

        $responsible = null;

        if ($options->includePersonalData) {
            try {
                $responsible = $this->signedBy($accounts, $options);
            } catch (Throwable $failure) {
                // Sin base no se puede saber quien firma, y el paquete con datos
                // personales no se genera si no se puede auditar (RL-19): el
                // mismo mensaje que si fallara el asiento, sin la excepcion.
                return $this->notAudited($failure, $options, $failures);
            }

            if (is_int($responsible)) {
                return $responsible;
            }
        }

        try {
            $bundle = $diagnostics->handle($options, DiagnosticsActor::Console, $responsible?->id);
        } catch (DiagnosticsResponsibleNotAllowed) {
            // La cuenta se dio de baja o perdio el rol entre la pregunta y la
            // generacion —tambien con la cadena tomada, antes de los asientos—:
            // no se ha escrito ningun asiento ni se entrega el paquete.
            return ConsoleResponsible::noLongerAllowed($this);
        } catch (Throwable $failure) {
            return $this->notAudited($failure, $options, $failures);
        }

        $path = $this->deliver($delivery, $bundle);

        return $path === null ? self::FAILURE : $this->report($bundle, $options, $purged, $path);
    }

    /**
     * Deja el fichero en el disco por {@see DeliverDiagnosticsBundle}, que es
     * quien ordena temporal, pendiente y renombrado (ADR-020). Aqui
     * solo se dice que paso.
     */
    private function deliver(DeliverDiagnosticsBundle $delivery, DiagnosticsBundle $bundle): ?string
    {
        $output = $this->option('output');

        try {
            return $delivery->handle($bundle, is_string($output) && $output !== '' ? $output : null);
        } catch (DiagnosticsAuditNotDeferred $failure) {
            $this->line('No se ha generado el paquete: la base de datos no responde y no se ha podido');
            $this->line('guardar la constancia de su generacion para escribirla en la auditoria despues.');
            $this->line($failure->getMessage());
        } catch (Throwable $failure) {
            $this->line('No se ha podido escribir el paquete.');
            $this->line($failure->getMessage());
        }

        return null;
    }

    /**
     * El asiento no se pudo escribir y el paquete no se entrega (RL-19, regla
     * dura 6). Sin el mensaje de la excepcion, que puede llevar la cadena de
     * conexion: la clase basta para que soporte sepa que se rompio.
     */
    private function notAudited(Throwable $failure, DiagnosticsOptions $options, ProbeFailureClassifier $failures): int
    {
        $databaseDown = $failures->unavailableService($failure) === ProbeFailureClassifier::DATABASE;

        $this->line('');

        if ($databaseDown && $options->includePersonalData) {
            $this->line('No se ha generado el paquete CON DATOS PERSONALES: la base de datos no responde y');
            $this->line('ese paquete no se genera porque no se puede auditar (RL-19). No se ha escrito nada.');
            $this->line('');
            $this->line('Si necesitas el paquete ya, genera el anonimizado, que si se puede generar sin base:');
            $this->line('  php artisan product:diagnostics');
            $this->line('Cuando la base vuelva, repite este comando si sigues necesitando los datos personales.');
        } else {
            // Con la base caida y el paquete anonimizado no se llega aqui: el
            // caso de uso aplaza el asiento en lugar de lanzar (ADR-020).
            $this->line('No se ha generado el paquete: no se ha podido dejar constancia de su generacion en');
            $this->line('la auditoria (error '.$failure::class.'). No se ha escrito nada.');
            $this->line('Ejecuta php artisan product:doctor y revisa las comprobaciones database.*; si todas');
            $this->line('estan en verde, avisa a soporte con esa salida.');
        }

        $this->line('');

        return self::FAILURE;
    }

    /**
     * Escribe los asientos de paquetes anteriores generados con la base caida
     * (ADR-020, enmienda del 14-10-2026). Sin pendientes no toca la base. Un
     * fallo aqui no impide generar: el paquete de ahora es lo urgente, y la
     * tarea programada lo volvera a intentar.
     */
    private function recordPending(RecordPendingDiagnosticsAudits $recordPending): void
    {
        try {
            $result = $recordPending->handle();
        } catch (Throwable) {
            return;
        }

        if ($result['recorded'] > 0) {
            $this->line('Se han escrito en la auditoria '.$result['recorded'].' asiento(s) pendiente(s) de paquetes');
            $this->line('generados mientras la base de datos no respondia.');
            $this->line('');
        }
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
     * La cuenta `admin` que firma el paquete con datos personales, o el codigo
     * de salida con el motivo ya escrito: `2` si falta, no puede firmar o no
     * cuadra `--responsible-confirm`; `1` si la persona responde que no. En
     * ningun caso se ha generado ni escrito nada.
     */
    private function signedBy(ResponsibleAccounts $accounts, DiagnosticsOptions $options): ResponsibleAccount|int
    {
        $option = $this->option('responsible');

        if (! is_string($option) || trim($option) === '') {
            return ConsoleResponsible::missing($this);
        }

        $responsible = ConsoleResponsible::resolve($this, $accounts, $option, DiagnosticsSigners::ROLES);

        if (is_int($responsible)) {
            return $responsible;
        }

        $this->warnAboutPersonalData($options);

        return ConsoleResponsible::confirm(
            $this,
            $responsible,
            '¿Seguro que quieres incluir los datos personales?',
            ! $this->input->isInteractive(),
        ) ?? $responsible;
    }

    /**
     * El aviso previo, en texto llano y antes de generar nada (RL-19).
     *
     * ## Sin terminal, `--responsible-confirm` **es** la confirmacion
     *
     * Con `--no-interaction` no hay nadie a quien preguntar. El aviso se
     * imprime igual, porque acaba en el registro de quien lanzo el script, y
     * la confirmacion es el correo de la cuenta que firma (2.3.0 bloque 3).
     *
     * Y RL-19 se sigue cumpliendo entero: la accion **es** distinta —hay que
     * escribir `--with-personal-data`, que nunca es el valor por defecto ni el
     * efecto secundario de otra opcion—, va avisada y deja su propio asiento en
     * `audit_log`, firmado por la cuenta que la autoriza.
     */
    private function warnAboutPersonalData(DiagnosticsOptions $options): void
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
        $this->line('  - La accion queda registrada en el registro de auditoria, firmada por la cuenta');
        $this->line('    de --responsible.');
        $this->line('  - Si se lo envias al proveedor, este actua como encargado del tratamiento');
        $this->line('    para ese supuesto (RGPD art. 28). Necesitas el contrato de encargo firmado.');
        $this->line('');
        $this->line('Si solo quieres diagnosticar un problema tecnico, no hace falta: ejecuta');
        $this->line('  php artisan product:diagnostics');
        $this->line('');
    }

    private function report(
        DiagnosticsBundle $bundle,
        DiagnosticsOptions $options,
        int $purged,
        string $path,
    ): int {
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

        if ($bundle->generationEntryPending()) {
            // ADR-020, enmienda del 14-10-2026: se entrega antes que su asiento,
            // y quien lo genera tiene que saberlo y saber que no ha de hacer nada.
            $this->line('La base de datos no responde: el asiento de este paquete en la auditoria queda');
            $this->line('PENDIENTE y se escribira solo en cuanto la base vuelva (no tienes que hacer nada).');
            $this->line('El propio paquete lo dice en audit.generation_entry, dentro de su huella.');
            $this->line('Las secciones que dependen de la base salen como "unavailable".');
            $this->line('');
        }
        $this->line('Antes de enviarlo, abrelo y comprueba que no lleva nada que no quieras enviar:');
        $this->line('  less '.$path);
        $this->line('');
        $this->line('Quien lo reciba puede comprobar que no se ha alterado por el camino con:');
        $this->line('  php artisan product:diagnostics --verify='.$path);

        if (! $options->includePersonalData) {
            $this->line('');
            // ADR-048: lo que dice aqui es lo que comprueba la prueba sembrada
            // del paquete, ni mas ni menos. Va en los dos idiomas porque quien
            // lo ejecuta puede ser el IT del hotel o el soporte del fabricante.
            $this->line('Anonimizado: sin nombres, documentos ni identificadores de empleado; el texto');
            $this->line('de los errores, solo con palabras técnicas. Ábrelo antes de enviarlo.');
            $this->line('Anonymised: no names, documents or employee identifiers; error text reduced');
            $this->line('to technical words. Open it before sending.');
        }

        $this->line('');

        return self::SUCCESS;
    }

    /**
     * Borra los paquetes anteriores que ya han caducado, antes de escribir uno
     * nuevo (**RL-19**).
     *
     * ## Aqui Y en la pasada horaria
     *
     * Con el volumen persistente (ADR-045 §g, C4) el ultimo paquete no
     * desaparece al recrear el contenedor, y por eso la pasada horaria de
     * `product:export-all --purge` barre tambien. Aqui se conserva porque es el
     * momento en que alguien esta mirando, y lo dice en la salida. Los dos
     * pasan por el mismo caso de uso: patron exacto, un nivel, sin enlaces.
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
    private function purge(SweepExpiredDiagnosticsBundles $sweep): int
    {
        try {
            return $sweep->handle();
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

        $check = DiagnosticsBundle::checkDigest($document);

        if (! $check->matches()) {
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

        if ($check === DiagnosticsDigestCheck::IntactWithoutManifest) {
            // Paquete de `schema_version: 1`, anterior a la 2.3.0 (PR16).
            $this->line('             huella sin manifiesto: cubre las secciones, no la cabecera. Que diga');
            $this->line('             «anonimo» no esta comprobado; mira las secciones antes de darlo por anonimo.');
        }

        $this->line('  Asiento:   '.self::generationEntry($document));

        return self::SUCCESS;
    }

    /**
     * Lo que dice el paquete de su propio asiento (ADR-020, enmienda del
     * 14-10-2026). Un paquete anterior a la 2.2.1 no lleva la marca, y su
     * asiento tambien se escribio al generarlo.
     *
     * @param  array<string, mixed>  $document
     */
    private static function generationEntry(array $document): string
    {
        $audit = $document['audit'] ?? null;

        return is_array($audit) && is_array($audit[DiagnosticsBundle::GENERATION_ENTRY] ?? null)
            ? 'PENDIENTE: la base de datos no respondia al generarlo; se escribe solo al volver'
            : 'escrito en la auditoria al generarlo';
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

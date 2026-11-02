<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Console;

use App\Modules\Product\Application\Exception\DiagnosticsResponsibleNotAllowed;
use App\Modules\Product\Application\Exception\DiagnosticsSealingUnavailable;
use App\Modules\Product\Application\Port\DiagnosticsBundleSealer;
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
use App\Modules\Product\Domain\ValueObject\SealedDiagnosticsBundle;
use App\Modules\Product\Infrastructure\Diagnostics\DiagnosticsAuditTrail;
use App\Modules\Shared\Application\Port\ResponsibleAccounts;
use App\Modules\Shared\Domain\Support\UtcInstant;
use App\Modules\Shared\Domain\ValueObject\ResponsibleAccount;
use App\Modules\Shared\Infrastructure\Console\ConsoleResponsible;
use App\Modules\Shared\Infrastructure\Console\SpeaksInstallationLanguage;
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
 * no por `system` (doc 01 §7.2). Nunca es un efecto
 * secundario de otra opcion.
 *
 * ## `--verify=RUTA` no genera nada
 *
 * Relee un paquete escrito antes y recalcula la huella del documento sin su
 * `manifest`. Existe porque el anonimizado **viaja sin cifrar a proposito** —el
 * cliente tiene que poder abrirlo antes de enviarlo— y un fichero en claro se
 * trunca: un cliente de correo que corta a los 5 MB, un copiar y pegar
 * incompleto. Sin esta comprobacion, soporte se pasaria una hora diagnosticando
 * un paquete a medias.
 *
 * Sobre un `.sealed.json` (el paquete con datos personales, cifrado; ADR-020,
 * enmienda del 01-11-2026) dice para que clave va, enseña la huella declarada y
 * la del fichero, y las compara con el asiento `diagnostics.bundle_generated`
 * de esta instalacion. El contenido no lo comprueba: sin la clave privada del
 * fabricante no se puede, y lo dice.
 *
 * ## Con datos personales, cifrado o nada
 *
 * Sin la clave publica de soporte del fabricante el paquete con datos
 * personales no se genera: sale con `1` antes de preguntar quien firma, sin
 * recoger nada ni escribir ningun asiento, y remite al anonimizado.
 *
 * ## Codigos de salida
 *
 * | Codigo | Significado |
 * |---|---|
 * | `0` | El paquete se genero, o el que se verifico esta integro (en el cifrado: su cabecera tiene la forma de un sobre y, si hay asiento, la huella del fichero es la suya). |
 * | `1` | No se genero: no se pudo escribir el fichero, no se pudo dejar constancia en `audit_log` (ni ahora ni como pendiente), o se respondio que no a la confirmacion de los datos personales, o se pidio con datos personales y no hay clave publica de soporte para cifrarlo. El mensaje dice cual y que hacer. |
 * | `2` | Con `--verify`: la huella no coincide, el fichero no es un paquete, o es un sobre mal formado o distinto del que dice su asiento. Con `--with-personal-data`: periodo fuera de rango, falta `--responsible`, la cuenta no es `admin` activa (al preguntar o al firmar), o sin terminal falta o no cuadra `--responsible-confirm`. |
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
    use SpeaksInstallationLanguage;

    /** Seccion de `lang/*\/console.php`. */
    private const string TEXTS = 'console.product_diagnostics';

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
        DiagnosticsBundleSealer $sealer,
        DiagnosticsAuditTrail $trail,
    ): int {
        $verify = $this->option('verify');

        if (is_string($verify) && $verify !== '') {
            return $this->verify($writer, $verify, $trail);
        }

        $purged = $this->purge($sweep);

        $this->recordPending($recordPending);

        $options = $this->requestedOptions($config);

        if ($options === null) {
            // Periodo fuera de rango: es un error de uso, como el resto (2).
            return self::INVALID;
        }

        $responsible = $options->includePersonalData
            ? $this->personalDataSigner($sealer, $accounts, $options, $failures)
            : null;

        if (is_int($responsible)) {
            return $responsible;
        }

        try {
            $bundle = $diagnostics->handle($options, DiagnosticsActor::Console, $responsible?->id);
        } catch (DiagnosticsResponsibleNotAllowed) {
            // La cuenta se dio de baja o perdio el rol entre la pregunta y la
            // generacion —tambien con la cadena tomada, antes de los asientos—:
            // no se ha escrito ningun asiento ni se entrega el paquete.
            return ConsoleResponsible::noLongerAllowed($this);
        } catch (DiagnosticsSealingUnavailable) {
            return $this->sealingUnavailable();
        } catch (Throwable $failure) {
            return $this->notAudited($failure, $options, $failures);
        }

        $path = $this->deliver($delivery, $bundle);

        return $path === null ? self::FAILURE : $this->report($bundle, $options, $purged, $path);
    }

    /**
     * La cuenta que firma el paquete con datos personales, o el codigo de
     * salida con el motivo ya escrito.
     *
     * Primero la clave de soporte: un paquete que no se va a poder cifrar no se
     * genera, y nadie tiene que confirmar nada para nada (ADR-020, enmienda del
     * 01-11-2026).
     */
    private function personalDataSigner(
        DiagnosticsBundleSealer $sealer,
        ResponsibleAccounts $accounts,
        DiagnosticsOptions $options,
        ProbeFailureClassifier $failures,
    ): ResponsibleAccount|int {
        if (! $sealer->available()) {
            return $this->sealingUnavailable();
        }

        try {
            return $this->signedBy($accounts, $options);
        } catch (Throwable $failure) {
            // Sin base no se puede saber quien firma, y el paquete con datos
            // personales no se genera si no se puede auditar (RL-19): el
            // mismo mensaje que si fallara el asiento, sin la excepcion.
            return $this->notAudited($failure, $options, $failures);
        }
    }

    /**
     * Sin clave publica de soporte, el paquete con datos personales no se
     * genera (ADR-020, enmienda del 01-11-2026): `1`, sin fichero ni asiento,
     * y el mensaje remite al anonimizado, que no la necesita.
     */
    private function sealingUnavailable(): int
    {
        $this->line('');
        $this->paragraph('sealing_unavailable');
        $this->line('');

        return self::FAILURE;
    }

    /**
     * Deja el fichero en el disco por {@see DeliverDiagnosticsBundle}, que es
     * quien ordena temporal, pendiente y renombrado (ADR-020). Aqui
     * solo se dice que paso.
     */
    private function deliver(DeliverDiagnosticsBundle $delivery, DiagnosticsBundle|SealedDiagnosticsBundle $bundle): ?string
    {
        $output = $this->option('output');

        try {
            return $delivery->handle($bundle, is_string($output) && $output !== '' ? $output : null);
        } catch (DiagnosticsAuditNotDeferred $failure) {
            $this->paragraph('deliver.not_deferred');
            $this->line($failure->getMessage());
        } catch (Throwable $failure) {
            $this->line($this->text('deliver.not_written'));
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
            $this->paragraph('not_audited.personal_data_without_database');
            $this->line('');
            $this->paragraph('not_audited.personal_data_alternative');
        } else {
            // Con la base caida y el paquete anonimizado no se llega aqui: el
            // caso de uso aplaza el asiento en lugar de lanzar (ADR-020).
            $this->paragraph('not_audited.other', ['error' => $failure::class]);
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
            $this->paragraph('pending_recorded', ['count' => $result['recorded']]);
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
            $this->paragraph('period_out_of_range', ['maximum' => $maximum, 'days' => $days]);

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
            $this->text('confirm_personal_data'),
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
     * la confirmacion es el correo de la cuenta que firma.
     *
     * Y RL-19 se sigue cumpliendo entero: la accion **es** distinta —hay que
     * escribir `--with-personal-data`, que nunca es el valor por defecto ni el
     * efecto secundario de otra opcion—, va avisada y deja su propio asiento en
     * `audit_log`, firmado por la cuenta que la autoriza.
     */
    private function warnAboutPersonalData(DiagnosticsOptions $options): void
    {
        $title = $this->text('personal_data_warning.title');

        $this->line('');
        $this->line($title);
        $this->line(str_repeat('-', mb_strlen($title)));
        $this->paragraph('personal_data_warning.contents', ['days' => $options->periodDays]);
        $this->line('');
        $this->paragraph('personal_data_warning.implications');
        $this->line('');
        $this->paragraph('personal_data_warning.alternative');
        $this->line('');
    }

    private function report(
        DiagnosticsBundle|SealedDiagnosticsBundle $bundle,
        DiagnosticsOptions $options,
        int $purged,
        string $path,
    ): int {
        $this->line('');

        if ($purged > 0) {
            $this->paragraph('report.purged', ['count' => $purged]);
            $this->line('');
        }

        if ($bundle instanceof SealedDiagnosticsBundle) {
            return $this->reportSealed($bundle, $path);
        }

        $this->line($this->text('report.title'));
        $this->line('');
        $this->line($this->text('report.file', ['path' => $path]));
        $this->line($this->text('report.size', ['size' => number_format((float) \strlen($bundle->toJson()) / 1024, 1)]));
        $this->line($this->text('report.digest', ['digest' => $bundle->manifest->sha256]));
        $this->line($this->text('report.sections', ['sections' => implode(', ', $bundle->manifest->sections)]));
        $this->line($this->text('report.anonymous', ['answer' => $this->anonymousAnswer($bundle->manifest->anonymized)]));
        $this->line('');

        if ($bundle->generationEntryPending()) {
            // ADR-020, enmienda del 14-10-2026: se entrega antes que su asiento,
            // y quien lo genera tiene que saberlo y saber que no ha de hacer nada.
            $this->paragraph('report.entry_pending');
            $this->line('');
        }
        $this->paragraph('report.check_before_sending', ['path' => $path]);
        $this->line('');
        $this->paragraph('report.verify_hint', ['path' => $path]);

        if (! $options->includePersonalData) {
            $this->line('');
            // ADR-048: lo que dice aqui es lo que comprueba la prueba sembrada
            // del paquete, ni mas ni menos. Va en los dos idiomas, en los dos
            // catalogos, porque quien lo lee puede ser el IT del hotel o el
            // soporte del fabricante.
            $this->paragraph('report.anonymized_scope');
        }

        $this->line('');

        return self::SUCCESS;
    }

    /**
     * El paquete con datos personales, cifrado (ADR-020, enmienda del
     * 01-11-2026): las dos huellas —la del texto en claro, que dice la
     * cabecera, y la del fichero— y para que clave va. Que revisar antes de
     * enviarlo es la cabecera, no el contenido.
     */
    private function reportSealed(SealedDiagnosticsBundle $bundle, string $path): int
    {
        $json = $bundle->toJson();

        $this->line($this->text('report.sealed.title'));
        $this->line('');
        $this->line($this->text('report.file', ['path' => $path]));
        $this->line($this->text('report.size', ['size' => number_format((float) \strlen($json) / 1024, 1)]));
        $this->line($this->text('report.sealed.key', ['key' => $bundle->recipientKeyId]));
        $this->line($this->text('report.digest', ['digest' => $bundle->manifest->sha256]));
        $this->line($this->text('report.sealed.file_digest', ['digest' => hash('sha256', $json)]));
        $this->line($this->text('report.sections', ['sections' => implode(', ', $bundle->manifest->sections)]));
        $this->line($this->text('report.anonymous', ['answer' => $this->anonymousAnswer(false)]));
        $this->line('');
        $this->paragraph('report.sealed.check_before_sending', ['path' => $path]);
        $this->line('');
        $this->paragraph('report.verify_hint', ['path' => $path]);
        $this->line('');

        return self::SUCCESS;
    }

    /**
     * Borra los paquetes anteriores que ya han caducado, antes de escribir uno
     * nuevo (**RL-19**).
     *
     * ## Aqui Y en la pasada horaria
     *
     * Con el volumen persistente (ADR-045 §g, condicion C4) el ultimo paquete no
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

    private function verify(DiagnosticsBundleWriter $writer, string $path, DiagnosticsAuditTrail $trail): int
    {
        $document = $writer->read($path);

        if ($document === null) {
            $this->paragraph('verify.unreadable', ['path' => $path]);

            return 2;
        }

        if (SealedDiagnosticsBundle::looksSealed($document)) {
            return $this->verifySealed($document, $path, $trail);
        }

        $check = DiagnosticsBundle::checkDigest($document);

        if (! $check->matches()) {
            $this->line($this->text('verify.mismatch.title', ['path' => $path]));
            $this->line('');
            $this->paragraph('verify.mismatch.advice');

            return 2;
        }

        /** @var array<string, mixed> $manifest */
        $manifest = is_array($document['manifest'] ?? null) ? $document['manifest'] : [];

        $this->line($this->text('verify.intact', ['path' => $path]));
        $this->line($this->text('verify.version', ['version' => self::manifestValue($manifest, 'product_version')]));
        $this->line($this->text('verify.generated', [
            'at' => self::manifestValue($manifest, 'generated_at'),
            'by' => self::manifestValue($manifest, 'generated_by'),
        ]));
        $this->line($this->text('verify.anonymous', ['answer' => $this->anonymousAnswer(($manifest['anonymized'] ?? null) === true)]));
        $this->line($this->text('verify.digest', ['digest' => self::manifestValue($manifest, 'sha256')]));

        if ($check === DiagnosticsDigestCheck::IntactWithoutManifest) {
            // Paquete de `schema_version: 1`, anterior a la 2.3.0.
            $this->paragraph('verify.without_manifest');
        }

        $this->line($this->text('verify.entry', ['entry' => $this->generationEntry($document)]));

        return self::SUCCESS;
    }

    /**
     * `--verify` sobre un paquete cifrado (ADR-020, enmienda del 01-11-2026).
     *
     * Su contenido no se puede comprobar aqui —la instalacion no tiene la clave
     * privada—, y lo dice. Lo que si se comprueba es la cabecera contra el
     * asiento `diagnostics.bundle_generated` con la misma huella, y la huella
     * del fichero entero contra la que el asiento dice que se entrego. `2` si
     * la cabecera no tiene la forma de un sobre o si la huella del fichero no
     * es la del asiento.
     *
     * @param  array<string, mixed>  $document
     */
    private function verifySealed(array $document, string $path, DiagnosticsAuditTrail $trail): int
    {
        $header = self::sealedHeader($document);

        if ($header === null) {
            $this->paragraph('verify.sealed.malformed');

            return 2;
        }

        ['manifest' => $manifest, 'key_id' => $keyId, 'sha256' => $sha256] = $header;
        $fileDigest = hash_file('sha256', $path) ?: '';

        $this->line($this->text('verify.sealed.title', ['key' => $keyId, 'path' => $path]));
        $this->line($this->text('verify.version', ['version' => self::manifestValue($manifest, 'product_version')]));
        $this->line($this->text('verify.generated', [
            'at' => self::manifestValue($manifest, 'generated_at'),
            'by' => self::manifestValue($manifest, 'generated_by'),
        ]));
        $this->line($this->text('verify.anonymous', ['answer' => $this->anonymousAnswer(false)]));
        $this->line($this->text('verify.digest', ['digest' => $sha256]));
        $this->line($this->text('verify.sealed.file_digest', ['digest' => $fileDigest]));

        $status = $this->sealedEntry($trail, $sha256, $fileDigest);

        $this->line('');
        $this->paragraph('verify.sealed.content_unchecked');

        return $status;
    }

    /**
     * Lo que `--verify` necesita de la cabecera de un sobre, o nulo si no tiene
     * su forma.
     *
     * @param  array<string, mixed>  $document
     * @return array{manifest: array<string, mixed>, key_id: string, sha256: string}|null
     */
    private static function sealedHeader(array $document): ?array
    {
        $keyId = SealedDiagnosticsBundle::wellFormedKeyId($document);
        $manifest = $document['manifest'] ?? null;

        if ($keyId === null || ! is_array($manifest) || ! is_string($manifest['sha256'] ?? null)) {
            return null;
        }

        /** @var array<string, mixed> $manifest */
        /** @var string $sha256 */
        $sha256 = $manifest['sha256'];

        return ['manifest' => $manifest, 'key_id' => $keyId, 'sha256' => $sha256];
    }

    /**
     * Lo que dice la auditoria de esta instalacion de ese sobre. Sin base, lo
     * dice y no falla: el fichero puede estar bien.
     */
    private function sealedEntry(DiagnosticsAuditTrail $trail, string $sha256, string $fileDigest): int
    {
        try {
            $entry = $trail->generation($sha256);
        } catch (Throwable) {
            $this->line($this->text('verify.sealed.entry_unknown'));

            return self::SUCCESS;
        }

        if ($entry === null) {
            $this->line($this->text('verify.sealed.entry_missing'));

            return self::SUCCESS;
        }

        $this->line($this->text('verify.sealed.entry_found', ['at' => UtcInstant::wallTime($entry['occurred_at'])]));

        if ($entry['sealed_sha256'] !== null && ! hash_equals($entry['sealed_sha256'], $fileDigest)) {
            $this->line('');
            $this->paragraph('verify.sealed.file_differs');

            return 2;
        }

        $this->line($this->text('verify.sealed.file_matches'));

        return self::SUCCESS;
    }

    private function anonymousAnswer(bool $anonymized): string
    {
        return $this->text($anonymized ? 'anonymous.yes' : 'anonymous.no');
    }

    /**
     * Lo que dice el paquete de su propio asiento (ADR-020, enmienda del
     * 14-10-2026). Un paquete anterior a la 2.2.1 no lleva la marca, y su
     * asiento tambien se escribio al generarlo.
     *
     * @param  array<string, mixed>  $document
     */
    private function generationEntry(array $document): string
    {
        $audit = $document['audit'] ?? null;

        return $this->text(is_array($audit) && is_array($audit[DiagnosticsBundle::GENERATION_ENTRY] ?? null)
            ? 'verify.entry_pending'
            : 'verify.entry_written');
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private static function manifestValue(array $manifest, string $key): string
    {
        $value = $manifest[$key] ?? null;

        return is_scalar($value) ? (string) $value : '—';
    }
}

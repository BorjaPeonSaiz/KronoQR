<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Console;

use App\Modules\Compliance\Application\Command\GenerateLegalExportCommand;
use App\Modules\Compliance\Application\Exception\LegalExportSignerNotAllowed;
use App\Modules\Compliance\Application\Support\LegalExporters;
use App\Modules\Compliance\Application\UseCase\GenerateLegalExport;
use App\Modules\Compliance\Domain\Exception\InvalidLegalExportRequest;
use App\Modules\Compliance\Domain\ValueObject\LegalExportPeriod;
use App\Modules\Compliance\Domain\ValueObject\LegalExportScope;
use App\Modules\Shared\Application\Port\ResponsibleAccounts;
use App\Modules\Shared\Domain\ValueObject\ResponsibleAccount;
use App\Modules\Shared\Infrastructure\Console\ConsoleResponsible;
use App\Modules\Shared\Infrastructure\Console\SpeaksInstallationLanguage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * `php artisan compliance:legal-export --from= --to= --employee=` — la
 * exportacion para la Inspeccion **sin depender del panel** (RF-IN-05, RL-03,
 * RL-06, doc 02 Anexo C, plan 1.17 paso 7).
 *
 * ## Por que existe habiendo endpoint
 *
 * Porque un requerimiento llega con plazo y no siempre con el panel disponible.
 * Un despliegue a medias, una sesion caducada, un navegador que no descarga
 * ficheros grandes o simplemente que quien atiende el requerimiento es el
 * administrador del servidor y no un usuario de RRHH: en cualquiera de esos
 * casos, el registro horario tiene que poder salir igual. El runbook
 * `docs/runbooks/requerimiento-inspeccion.md` se apoya en esta orden.
 *
 * ## Escribe donde le digan, y por defecto donde se pueda encontrar
 *
 * `--output` acepta una ruta; sin ella, el fichero cae en
 * `storage/app/legal-exports/` con el nombre que decide el manifiesto —que
 * **nunca lleva el nombre de una persona** (regla dura 21)—. La ruta se imprime
 * al terminar, porque un comando que escribe un fichero y no dice donde obliga a
 * buscarlo.
 *
 * ## Y recuerda que no se borra solo (ADR-045 §f)
 *
 * `storage/app/legal-exports/` vive en el volumen persistente `app-storage`: la
 * copia ya no desaparece al recrear el contenedor, y nadie la borra por su
 * cuenta porque es custodia humana. El comando termina diciendo «borralo en
 * cuanto lo hayas entregado», y a los 30 dias avisan `product:doctor` y la
 * serie `generated_files_overdue{class="legal_export_console"}`.
 *
 * ## Queda auditado igual que la descarga, y firmado por quien la pide
 *
 * El asiento lo escribe el caso de uso dentro de su transaccion (regla dura 6).
 * Desde la 2.3.0 lo firma la cuenta de `--responsible` (doc 01 §7.2, «Firmas
 * por consola»): la orden la enseña (`Firma: nombre <correo> · rol · uuid`) y
 * la persona confirma que es ella —con `--force` o sin terminal, con
 * `--responsible-confirm=<correo>`—, asi que el actor es esa cuenta, como
 * cuando se descarga desde el panel. Hasta la 2.2.1 el actor era `system`.
 * Firma quien puede descargarla desde el panel: `admin`, `rrhh` o `auditor`
 * (`LegalExporters`). Una via de escape sin auditoria seria justo la que se
 * usaria para no dejarla.
 *
 * El caso de uso vuelve a comprobar la cuenta antes de leer y con la cadena
 * tomada antes del asiento. Si la baja llega mientras se escribe el fichero,
 * no hay asiento y esta orden borra el fichero: un registro horario nominal
 * sin constancia no se entrega.
 *
 * ## Codigos de salida
 *
 * `0` si el fichero se escribio; `1` si el periodo no es valido o la persona
 * responde que no a la firma; `2` si falta `--responsible`, la cuenta no puede
 * firmar (al preguntar o al firmar) o falta o no cuadra `--responsible-confirm`.
 * Con `1` o `2` no hay asiento ni fichero. Un periodo sin jornadas registradas
 * es `0` y un fichero con su cabecera de criterios: «no hay nada» tambien es
 * una afirmacion que hay que poder entregar.
 */
final class LegalExportCommand extends Command
{
    use SpeaksInstallationLanguage;

    /** Seccion de `lang/*\/console.php`. */
    private const string TEXTS = 'console.legal_export';

    protected $signature = 'compliance:legal-export
        {--from= : Primer dia del periodo, YYYY-MM-DD, por fecha de jornada}
        {--to= : Ultimo dia del periodo, inclusive}
        {--employee= : UUID del trabajador. Sin esta opcion se exporta la plantilla completa}
        {--output= : Ruta del fichero a escribir. Por defecto storage/app/legal-exports/}
        {--responsible= : Uuid o correo de la cuenta de gestion (admin, rrhh o auditor) que pide la exportacion y la firma. Obligatorio}
        {--force : No preguntar por la firma; exige --responsible-confirm}
        {--responsible-confirm= : Correo de la cuenta de --responsible. Obligatorio con --force o sin terminal}';

    protected $description = 'Genera la exportacion normalizada para la Inspeccion de Trabajo (RF-IN-05, RL-06)';

    public function handle(GenerateLegalExport $generate, ResponsibleAccounts $accounts): int
    {
        try {
            $period = LegalExportPeriod::between($this->stringOption('from'), $this->stringOption('to'));
        } catch (InvalidLegalExportRequest $invalid) {
            $this->error($invalid->getMessage());
            $this->line($this->text('example'));

            return self::FAILURE;
        }

        $employee = $this->stringOption('employee');
        $scope = $employee === '' ? LegalExportScope::everyone() : LegalExportScope::employee($employee);

        $responsible = $this->signedBy($accounts, $period, $scope);

        if (\is_int($responsible)) {
            return $responsible;
        }

        $destination = $this->destinationPath($period->slug());

        try {
            $export = $generate->handle(new GenerateLegalExportCommand(
                period: $period,
                scope: $scope,
                destinationPath: $destination,
                // La cuenta que ha confirmado su firma: el asiento es suyo,
                // como cuando se descarga desde el panel.
                signedByUserId: $responsible->id,
            ));
        } catch (LegalExportSignerNotAllowed $notAllowed) {
            // La cuenta se dio de baja o perdio el rol entre la pregunta y la
            // firma: no hay asiento, y el fichero que llego a escribirse no se
            // entrega.
            if ($notAllowed->fileWritten && is_file($destination)) {
                unlink($destination);
            }

            return ConsoleResponsible::noLongerAllowed($this);
        }

        // El log NO lleva nombres (regla dura 21): periodo, alcance y cifras. El
        // fichero si lleva datos personales, por su finalidad legal; su
        // constancia esta en `audit_log`, no aqui.
        Log::notice('compliance.legal_export_generated', [
            'period_from' => $export->manifest->period->from,
            'period_to' => $export->manifest->period->to,
            'scope' => $export->manifest->scope->metricLabel(),
            'employee_uuid' => $export->manifest->scope->employeeUuid,
            'shift_entry_rows' => $export->tally->shiftEntries,
            'correction_rows' => $export->tally->corrections,
            'employees_exported' => $export->tally->employees,
            'channel' => 'console',
            // Quien firma, por su uuid y nunca por su nombre ni su correo.
            'responsible_uuid' => $responsible->uuid,
        ]);

        $this->info($this->text('generated'));
        $this->table($this->texts('columns'), [
            [$this->text('rows.period'), $period->from.' → '.$period->to],
            [$this->text('rows.scope'), $scope->isEveryone() ? $this->text('whole_staff') : (string) $scope->employeeUuid],
            [$this->text('rows.shift_entries'), (string) $export->tally->shiftEntries],
            [$this->text('rows.corrections'), (string) $export->tally->corrections],
            [$this->text('rows.employees'), (string) $export->tally->employees],
            [$this->text('rows.file'), $export->path],
            [$this->text('rows.signed_by'), $responsible->email],
        ]);

        // ADR-045 §f: la copia de consola vive en el volumen persistente y no se
        // borra sola. En el idioma de la instalacion, como el resto de textos
        // que lee una persona.
        $this->newLine();
        $this->warn($this->installationText('legal-export.console.delete_after_delivery'));

        return self::SUCCESS;
    }

    /**
     * La cuenta que firma la exportacion, o el codigo de salida con el motivo
     * ya escrito: `2` si falta `--responsible`, la cuenta no puede firmar o
     * falta o no cuadra `--responsible-confirm`; `1` si la persona responde que
     * no. En ningun caso se ha leido ni escrito nada.
     */
    private function signedBy(ResponsibleAccounts $accounts, LegalExportPeriod $period, LegalExportScope $scope): ResponsibleAccount|int
    {
        $option = $this->stringOption('responsible');

        if ($option === '') {
            return ConsoleResponsible::missing($this);
        }

        // Los roles que la descargan desde el panel (`LegalExportPolicy`): la
        // consola no es la via por la que alguien hace lo que el panel le niega.
        $responsible = ConsoleResponsible::resolve($this, $accounts, $option, LegalExporters::ROLES);

        if (\is_int($responsible)) {
            return $responsible;
        }

        return ConsoleResponsible::confirm(
            $this,
            $responsible,
            $this->text('confirm', [
                'from' => $period->from,
                'to' => $period->to,
                'scope' => $scope->isEveryone() ? $this->text('whole_staff') : (string) $scope->employeeUuid,
            ]),
            $this->option('force') === true || ! $this->input->isInteractive(),
        ) ?? $responsible;
    }

    /**
     * El nombre lo decide el manifiesto y no esta orden: es el mismo que se
     * descarga desde el panel, de modo que dos vias distintas de atender el
     * mismo requerimiento producen el mismo adjunto.
     */
    private function destinationPath(string $slug): string
    {
        $output = $this->stringOption('output');

        if ($output !== '') {
            return $output;
        }

        return rtrim(config()->string('compliance.legal_export_console_path'), '/')
            .'/registro-horario-'.$slug.'.csv';
    }

    private function stringOption(string $name): string
    {
        $value = $this->option($name);

        return is_string($value) ? trim($value) : '';
    }
}

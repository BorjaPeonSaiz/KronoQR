<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure\Console;

use App\Modules\Shared\Application\Port\ResponsibleAccounts;
use App\Modules\Shared\Domain\ValueObject\ResponsibleAccount;
use App\Modules\Shared\Infrastructure\Console\ConsoleResponsible;
use App\Modules\Shared\Infrastructure\Console\SpeaksInstallationLanguage;
use App\Modules\Workforce\Application\Command\CorrectSiteTimezoneCommand as CorrectSiteTimezone;
use App\Modules\Workforce\Application\Support\SiteMaintainers;
use App\Modules\Workforce\Application\UseCase\CorrectSiteTimezoneHandler;
use App\Modules\Workforce\Application\UseCase\SiteTimezoneCorrectionOutcome;
use App\Modules\Workforce\Application\UseCase\SiteTimezoneCorrectionReport;
use App\Modules\Workforce\Domain\Exception\InvalidSiteTimezoneCorrection;
use App\Modules\Workforce\Domain\Exception\UnknownTimezone;
use App\Modules\Workforce\Domain\ValueObject\SiteTimezoneCorrection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * `php artisan workforce:correct-site-timezone <zona>` — corrige la zona
 * horaria de un centro que ya tiene registro horario (RN-23, ADR-056 §3).
 *
 * Es la salida para una instalacion puesta en marcha con la zona equivocada:
 * desde el primer fichaje, el panel y la API ya no la cambian. **No mueve
 * ninguna jornada ni recalcula nada**; todo el historico pasa a presentarse con
 * la zona corregida, y la confirmacion lo dice antes de hacerlo.
 *
 * **Codigos de salida.**
 *
 * - `0`: corregida, o no habia nada que hacer (misma zona).
 * - `1`: no se puede corregir por aqui —sin centro, sin registro horario, que
 *   se cambia desde el panel— o la persona respondio que no a la confirmacion.
 * - `2` (`INVALID`): zona que no existe, faltan `--reason` o `--responsible`,
 *   motivo fuera de 10 a 1000 caracteres, `--responsible` que no es el `uuid`
 *   ni el correo de una cuenta de gestion activa con rol admin o rrhh (un id
 *   numerico se rechaza desde la 2.3.0), `--no-interaction` sin `--force`, o
 *   `--force` sin `--responsible-confirm` con el correo de esa cuenta.
 *
 * **Antes de confirmar enseña quien firma** (`Firma: nombre <correo> · rol ·
 * uuid`) con {@see ConsoleResponsible}, igual que las otras ordenes que firman
 * un asiento (doc 01 §7.2, «Firmas por consola»).
 *
 * En todos menos en el `0` de una correccion, **no se ha escrito nada**.
 *
 * **El motivo nunca va al log tecnico** (regla dura 21): es texto libre y va
 * solo al asiento. El log lleva las dos zonas, el recuento y la cuenta.
 */
final class CorrectSiteTimezoneCommand extends Command
{
    use SpeaksInstallationLanguage;

    /** Clave de `lang/*\/site.php` con los textos de esta orden. */
    private const string TEXTS = 'site.correct_timezone';

    protected $signature = 'workforce:correct-site-timezone
        {timezone : Zona horaria IANA correcta, por ejemplo Atlantic/Canary}
        {--reason= : Que se configuro mal y como se supo, de 10 a 1000 caracteres. Obligatorio}
        {--responsible= : Uuid o correo de la cuenta de gestion activa (admin o rrhh) que responde de la correccion. Obligatorio}
        {--force : No pedir confirmacion. Necesario con --no-interaction; exige --responsible-confirm}
        {--responsible-confirm= : Correo de la cuenta de --responsible. Obligatorio con --force}';

    protected $description = 'Corrige la zona horaria del centro cuando ya hay fichajes, sin mover ninguna jornada (RN-23, ADR-056)';

    public function handle(CorrectSiteTimezoneHandler $handler, ResponsibleAccounts $accounts): int
    {
        $signed = $this->request($accounts);

        if (\is_int($signed)) {
            return $signed;
        }

        [$request, $responsible] = $signed;

        $preview = $this->attempt(fn (): SiteTimezoneCorrectionReport => $this->previewOf($handler, $request));

        if (\is_int($preview)) {
            return $preview;
        }

        if ($preview->outcome !== SiteTimezoneCorrectionOutcome::Corrected) {
            return $this->explain($preview);
        }

        $this->printSummary($preview);

        $declined = $this->declined($responsible);

        if ($declined !== null) {
            return $declined;
        }

        $report = $this->attempt(fn (): SiteTimezoneCorrectionReport => $handler->handle($request));

        if (\is_int($report)) {
            return $report;
        }

        // Entre la vista previa y la transaccion pudo cambiar algo: lo que vale
        // es lo que decidio la transaccion.
        if ($report->outcome !== SiteTimezoneCorrectionOutcome::Corrected) {
            return $this->explain($report);
        }

        Log::warning('site_time_zone_corrected', [
            'previous_timezone' => $report->currentTimezone,
            'timezone' => $report->newTimezone,
            'shift_entry_count' => $report->shiftEntryCount,
            'responsible_uuid' => $responsible->uuid,
        ]);

        $this->info($this->text('done', [
            'previous' => (string) $report->currentTimezone,
            'timezone' => $report->newTimezone,
        ]));

        return self::SUCCESS;
    }

    /**
     * La orden tal como se pidio y la cuenta que la firma, o el codigo de
     * salida si faltan opciones o `--responsible` no puede firmarla.
     *
     * @return array{0: CorrectSiteTimezone, 1: ResponsibleAccount}|int
     */
    private function request(ResponsibleAccounts $accounts): array|int
    {
        $reason = $this->stringOption('reason');
        $responsibleOption = $this->stringOption('responsible');

        if ($reason === null || $responsibleOption === null) {
            $this->error($this->text('missing_options'));

            return self::INVALID;
        }

        $responsible = ConsoleResponsible::resolve($this, $accounts, $responsibleOption, SiteMaintainers::ROLES);

        if (\is_int($responsible)) {
            return $responsible;
        }

        return [new CorrectSiteTimezone((string) $this->argument('timezone'), $reason, $responsible->id), $responsible];
    }

    /**
     * La vista previa, con el motivo y el responsable comprobados antes: no se
     * pide «¿seguro?» por algo que despues se va a negar. El responsable se
     * vuelve a comprobar dentro de la transaccion, por si se da de baja entre
     * medias.
     *
     * @throws UnknownTimezone
     * @throws InvalidSiteTimezoneCorrection
     */
    private function previewOf(CorrectSiteTimezoneHandler $handler, CorrectSiteTimezone $request): SiteTimezoneCorrectionReport
    {
        SiteTimezoneCorrection::reason($request->reason);

        return $handler->preview($request->timezone, $request->responsibleUserId);
    }

    /**
     * Ejecuta el paso y traduce sus negativas a `INVALID` con el texto que toca.
     *
     * @param  callable(): SiteTimezoneCorrectionReport  $step
     */
    private function attempt(callable $step): SiteTimezoneCorrectionReport|int
    {
        try {
            return $step();
        } catch (UnknownTimezone) {
            $this->error($this->text('invalid_timezone', ['value' => (string) $this->argument('timezone')]));
        } catch (InvalidSiteTimezoneCorrection $invalid) {
            $this->error($this->installationText($invalid->translationKey, $invalid->parameters));
        }

        return self::INVALID;
    }

    /**
     * `null` si se puede seguir. Enseña quien firma; con `--force` exige
     * `--responsible-confirm` con su correo, y si no, pregunta. Sin terminal y
     * sin `--force` no hay a quien preguntar, y eso es `INVALID`. Si la persona
     * responde que no, `FAILURE`.
     */
    private function declined(ResponsibleAccount $responsible): ?int
    {
        $force = (bool) $this->option('force');

        if (! $force && ! $this->input->isInteractive()) {
            $this->error($this->text('needs_force'));

            return self::INVALID;
        }

        return ConsoleResponsible::confirm($this, $responsible, $this->text('confirm'), $force);
    }

    private function explain(SiteTimezoneCorrectionReport $report): int
    {
        return match ($report->outcome) {
            SiteTimezoneCorrectionOutcome::SameTimezone => $this->done(
                $this->text('same_timezone', ['timezone' => $report->newTimezone]),
            ),
            SiteTimezoneCorrectionOutcome::NoSite => $this->refused($this->text('no_site')),
            SiteTimezoneCorrectionOutcome::NoWorkRecord,
            SiteTimezoneCorrectionOutcome::Corrected => $this->refused($this->text('no_work_record')),
        };
    }

    private function printSummary(SiteTimezoneCorrectionReport $report): void
    {
        $this->line($this->text('summary_current', ['timezone' => (string) $report->currentTimezone]));
        $this->line($this->text('summary_new', ['timezone' => $report->newTimezone]));
        $this->line($this->text('summary_entries', [
            'count' => (string) $report->shiftEntryCount,
            'first' => (string) $report->extent?->firstWorkDate,
            'last' => (string) $report->extent?->lastWorkDate,
        ]));
        $this->warn($this->text('warning'));
    }

    private function done(string $message): int
    {
        $this->info($message);

        return self::SUCCESS;
    }

    private function refused(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return \is_string($value) && trim($value) !== '' ? $value : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Console;

use App\Modules\Product\Application\Exception\LicenseUnavailable;
use App\Modules\Product\Application\UseCase\DescribeLicenseHandler;
use App\Modules\Product\Domain\ValueObject\License;
use App\Modules\Product\Domain\ValueObject\LicenseOverview;
use App\Modules\Product\Domain\ValueObject\LicenseState;
use App\Modules\Product\Domain\ValueObject\StoredLicense;
use App\Modules\Shared\Domain\ValueObject\Feature;
use App\Modules\Shared\Domain\ValueObject\FeatureAvailability;
use App\Modules\Shared\Infrastructure\Console\SpeaksInstallationLanguage;
use Illuminate\Console\Command;

/**
 * `php artisan license:show` — el estado de la licencia en lenguaje llano
 * (Anexo C del doc 01, **RF-PD-04**, ADR-028).
 *
 * ## Para quien esta escrito
 *
 * Para la persona de informatica del hotel, a las nueve de la mañana, con un
 * aviso en pantalla y sin saber que es una licencia ed25519. Por eso la salida
 * dice, en este orden: **como esta**, **que se ha degradado**, **que sigue
 * funcionando** y **que hacer**. La ultima seccion es la unica que importa
 * cuando algo va mal, y por eso no esta al final por casualidad.
 *
 * También lo ejecuta el fabricante en una revision comercial, que es de donde
 * sale la seccion de contratado frente a real (ADR-028).
 *
 * ## Nunca imprime un secreto, ni la clave entera
 *
 * Sale la **huella corta** de la clave —doce caracteres— porque es lo que sirve
 * para confirmar por telefono que la clave activada es la que se envio. La clave
 * completa no sale: lleva el nombre del cliente repetido, ocupa varias lineas y
 * quien ejecuta esto suele estar pegando la salida en un ticket.
 *
 * ## Codigos de salida
 *
 * | Codigo | Significado |
 * |---|---|
 * | `0` | La licencia esta vigente y no hay ningun exceso de plan. |
 * | `3` | **Hay algo que mirar** ({@see self::ATTENTION}): no hay licencia, no verifica, ha caducado, caduca pronto, su vigencia no ha empezado, o se ha superado una cifra del plan. |
 * | `1` | La orden en si ha fallado (una excepcion no capturada, que es lo que da Symfony Console). |
 *
 * **Hasta la 2.3.0 «hay algo que mirar» era `1`**, el mismo codigo que una
 * excepcion no capturada, y quien llamaba (install.sh, update.sh) no podia
 * distinguir «sin licencia», que es lo normal recien instalado, de «la consulta ha
 * fallado». Quien lo consume es el `install.sh` y el
 * `update.sh` del MISMO paquete (lo ejecutan contra su propia imagen), asi que el
 * cambio no rompe nada entre versiones.
 *
 * **`3` no significa que el sistema este parado**, y el comando lo dice en su
 * propia salida. Se ficha, se consulta el registro, se exporta para la
 * Inspeccion, se corrige y se hace copia exactamente igual (regla dura 15,
 * ADR-019). El codigo existe para que `doctor` (5.9) y una tarea programada
 * puedan detectarlo sin leer el texto.
 *
 * **Sin licencia NUNCA es un error fatal.** Este comando no lanza en ningun
 * camino: el caso de uso es tolerante por diseño.
 */
final class LicenseShowCommand extends Command
{
    use SpeaksInstallationLanguage;

    /** «Hay algo que mirar», distinto del `1` de una excepcion no capturada. */
    public const int ATTENTION = 3;

    /** Seccion de `lang/*\/console.php`. */
    private const string TEXTS = 'console.license_show';

    protected $signature = 'license:show';

    protected $description = 'Estado de la licencia: cliente, plan, limites contratados frente a reales, vigencia y que esta degradado';

    public function handle(DescribeLicenseHandler $licenses): int
    {
        try {
            $overview = $licenses->handle();
        } catch (LicenseUnavailable) {
            // No saber no es «no hay». Sin consejo de reactivarla.
            $this->line('');
            $this->title();
            $this->line('');
            $this->line($this->text('unknown.state'));
            $this->line('');
            $this->paragraph('unknown.advice');
            $this->line('');

            return self::ATTENTION;
        }

        $status = $overview->status;

        $this->line('');
        $this->title();
        $this->line('');

        $this->summary($overview);
        $this->line('');
        $this->plan($overview);
        $this->line('');
        $this->degradation($overview);
        $this->line('');
        $this->neverDegraded();
        $this->line('');
        $this->whatToDo($overview);
        $this->line('');

        return $status->state === LicenseState::Valid && $overview->exceeded() === []
            ? self::SUCCESS
            : self::ATTENTION;
    }

    private function title(): void
    {
        $this->heading($this->text('title'), '=');
    }

    /** Un encabezado subrayado a su propia medida, en cualquier idioma. */
    private function heading(string $text, string $rule = '-'): void
    {
        $this->line($text);
        $this->line(str_repeat($rule, mb_strlen($text)));
    }

    private function summary(LicenseOverview $overview): void
    {
        $status = $overview->status;
        $license = $status->license;

        $this->line($this->text('summary.state', ['state' => $this->text('states.'.$status->state->value)]));

        if (! $license instanceof License) {
            $this->line($this->text('summary.customer', ['customer' => '—']));
            $this->line($this->text('summary.plan', ['plan' => '—']));
        } else {
            $this->line($this->text('summary.customer', ['customer' => $license->customerName]));
            $this->line($this->text('summary.plan', ['plan' => $license->plan]));
            $this->line($this->text('summary.validity', [
                'from' => self::day($license->validFrom),
                'until' => self::day($license->validUntil),
            ]));

            $remaining = $status->daysUntilExpiry();
            $elapsed = $status->daysSinceExpiry();

            if ($elapsed !== null) {
                $this->line($this->text('summary.expired_ago', ['days' => $elapsed]));
            } elseif ($remaining !== null) {
                $this->line($this->text('summary.remaining', [
                    'days' => $remaining,
                    'warning' => $status->expiryWarningDays,
                ]));
            }
        }

        if ($overview->stored instanceof StoredLicense) {
            $this->line($this->text('summary.activated', [
                'day' => self::day($overview->stored->activatedAt),
                'fingerprint' => $overview->stored->fingerprint(),
            ]));
            $this->line($this->text('summary.last_verified', [
                'day' => $overview->stored->lastVerifiedAt === null ? '—' : self::day($overview->stored->lastVerifiedAt),
            ]));
        }
    }

    /**
     * Contratado frente a real (**ADR-028**): las dos cifras que el fabricante
     * pide en una revision.
     *
     * Un exceso **no ha bloqueado nada**: la persona esta dada de alta y el
     * quiosco emparejado. Se dice aqui con todas las letras porque quien lee
     * esto suele estar buscando una explicacion a un problema, y este no lo es.
     */
    private function plan(LicenseOverview $overview): void
    {
        $this->heading($this->text('plan.title'));

        foreach ($overview->usage as $usage) {
            $this->line(\sprintf(
                '%-22s %s',
                $this->text('limits.'.($usage->limit->value === 'max_employees' ? 'max_employees' : 'max_devices')),
                $this->text('plan.row', [
                    'contracted' => \sprintf('%-6s', $usage->contracted === null ? '—' : (string) $usage->contracted),
                    'actual' => \sprintf('%-6s', (string) $usage->actual),
                    'exceeded' => $usage->isExceeded() ? $this->text('plan.exceeded', ['excess' => $usage->excess()]) : '',
                ]),
            ));
        }

        if ($overview->exceeded() !== []) {
            $this->line('');
            $this->paragraph('plan.not_blocking');
        }
    }

    private function degradation(LicenseOverview $overview): void
    {
        $this->heading($this->text('degradation.title'));

        $degraded = array_values(array_filter(
            $overview->status->degradedFeatures(),
            static fn (Feature $feature): bool => $feature->isImplemented(),
        ));

        if ($degraded === []) {
            $this->line($this->text('degradation.none'));

            return;
        }

        foreach ($degraded as $feature) {
            $availability = $overview->status->availabilityOf($feature);

            $this->line('- '.$this->text('features.'.$feature->value).': '.$this->restrictionLabel($availability));
        }

        $pending = array_values(array_filter(
            $overview->status->degradedFeatures(),
            static fn (Feature $feature): bool => ! $feature->isImplemented(),
        ));

        if ($pending !== []) {
            $this->line('');
            $this->paragraph('degradation.pending', ['count' => \count($pending)]);
        }
    }

    /**
     * La otra mitad, y la que hace que este comando sea util a las nueve de la
     * mañana: **que sigue funcionando pase lo que pase** (ADR-019, ADR-023).
     *
     * Es texto fijo y no una lista calculada, y es correcto que lo sea: no hay
     * ningun estado del producto en el que estas cosas dejen de funcionar por la
     * licencia, asi que no hay nada que calcular. Si algun dia lo hubiera, seria
     * un defecto y esta lista seria la prueba de que se prometio lo contrario.
     */
    private function neverDegraded(): void
    {
        $this->heading($this->text('never_degraded.title'));
        $this->paragraph('never_degraded.body');
    }

    private function whatToDo(LicenseOverview $overview): void
    {
        $this->heading($this->text('what_to_do.title'));

        $state = $overview->status->state;

        if ($state === LicenseState::Unverifiable) {
            $rejection = $overview->status->rejection?->value;
            $known = ['malformed', 'bad_signature', 'invalid_payload', 'no_public_key'];

            $this->paragraph('unverifiable.'.(\in_array($rejection, $known, true) ? $rejection : 'other'));
        } else {
            $this->paragraph('what_to_do.'.$state->value);
        }

        if ($overview->exceeded() !== []) {
            $this->line('');
            $this->paragraph('what_to_do.exceeded');
        }
    }

    private function restrictionLabel(FeatureAvailability $availability): string
    {
        $since = $availability->since === null ? '' : $this->text('restrictions.since', ['day' => self::day($availability->since)]);
        $known = ['license_expired', 'license_absent', 'license_unverifiable', 'license_not_yet_valid', 'not_in_plan'];
        $restriction = $availability->restriction?->value;

        return $this->text('restrictions.'.(\in_array($restriction, $known, true) ? $restriction : 'other'), ['since' => $since]);
    }

    private static function day(\DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new \DateTimeZone('UTC'))->format('d/m/Y');
    }
}

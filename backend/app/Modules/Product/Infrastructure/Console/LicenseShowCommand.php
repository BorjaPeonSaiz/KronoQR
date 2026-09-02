<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Console;

use App\Modules\Product\Application\UseCase\DescribeLicenseHandler;
use App\Modules\Product\Domain\ValueObject\License;
use App\Modules\Product\Domain\ValueObject\LicenseOverview;
use App\Modules\Product\Domain\ValueObject\LicenseState;
use App\Modules\Product\Domain\ValueObject\PlanUsage;
use App\Modules\Product\Domain\ValueObject\StoredLicense;
use App\Modules\Shared\Domain\ValueObject\Feature;
use App\Modules\Shared\Domain\ValueObject\FeatureAvailability;
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
 * | `1` | **Hay algo que mirar**: no hay licencia, no verifica, ha caducado, caduca pronto, su vigencia no ha empezado, o se ha superado una cifra del plan. |
 *
 * **`1` no significa que el sistema este parado**, y el comando lo dice en su
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
    protected $signature = 'license:show';

    protected $description = 'Estado de la licencia: cliente, plan, limites contratados frente a reales, vigencia y que esta degradado';

    public function handle(DescribeLicenseHandler $licenses): int
    {
        $overview = $licenses->handle();
        $status = $overview->status;

        $this->line('');
        $this->line('Licencia de KronoQR');
        $this->line('===================');
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
            : self::FAILURE;
    }

    private function summary(LicenseOverview $overview): void
    {
        $status = $overview->status;
        $license = $status->license;

        $this->line('Estado: '.self::stateLabel($overview));

        if (! $license instanceof License) {
            $this->line('Cliente: —');
            $this->line('Plan:    —');
        } else {
            $this->line('Cliente: '.$license->customerName);
            $this->line('Plan:    '.$license->plan);
            $this->line('Vigencia: del '.self::day($license->validFrom).' al '.self::day($license->validUntil).' (UTC)');

            $remaining = $status->daysUntilExpiry();
            $elapsed = $status->daysSinceExpiry();

            if ($elapsed !== null) {
                $this->line('Caduco hace '.$elapsed.' dia(s).');
            } elseif ($remaining !== null) {
                $this->line('Le quedan '.$remaining.' dia(s). Aviso a partir de los '
                    .$status->expiryWarningDays.' dias.');
            }
        }

        if ($overview->stored instanceof StoredLicense) {
            $this->line('Clave activada el '.self::day($overview->stored->activatedAt)
                .', huella '.$overview->stored->fingerprint());
            $this->line('Ultima verificacion correcta: '
                .($overview->stored->lastVerifiedAt === null ? '—' : self::day($overview->stored->lastVerifiedAt)));
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
        $this->line('Plan contratado frente a uso real');
        $this->line('---------------------------------');

        foreach ($overview->usage as $usage) {
            $this->line(\sprintf(
                '%-22s contratado: %-6s  real: %-6s %s',
                self::limitLabel($usage),
                $usage->contracted === null ? '—' : (string) $usage->contracted,
                (string) $usage->actual,
                $usage->isExceeded() ? '  ← SUPERADO en '.$usage->excess() : '',
            ));
        }

        if ($overview->exceeded() !== []) {
            $this->line('');
            $this->line('El exceso NO ha impedido ningun alta ni ningun emparejamiento, y no lo hara.');
            $this->line('Queda constancia en el registro de auditoria con la fecha exacta; hablalo con');
            $this->line('el proveedor para ampliar el plan.');
        }
    }

    private function degradation(LicenseOverview $overview): void
    {
        $this->line('Funcionalidades accesorias');
        $this->line('--------------------------');

        $degraded = array_values(array_filter(
            $overview->status->degradedFeatures(),
            static fn (Feature $feature): bool => $feature->isImplemented(),
        ));

        if ($degraded === []) {
            $this->line('Todo lo contratado esta disponible.');

            return;
        }

        foreach ($degraded as $feature) {
            $availability = $overview->status->availabilityOf($feature);

            $this->line('- '.self::featureLabel($feature).': '.self::restrictionLabel($availability));
        }

        $pending = array_values(array_filter(
            $overview->status->degradedFeatures(),
            static fn (Feature $feature): bool => ! $feature->isImplemented(),
        ));

        if ($pending !== []) {
            $this->line('');
            $this->line('(Otras '.\count($pending).' funcionalidad(es) del catalogo todavia no existen en esta');
            $this->line(' version, asi que no se pierde nada con ellas.)');
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
        $this->line('Lo que NUNCA depende de la licencia');
        $this->line('-----------------------------------');
        $this->line('Fichaje por QR y por PIN, sincronizacion de la cola del quiosco, consulta de');
        $this->line('jornadas y tramos, portal del empleado, exportacion para la Inspeccion,');
        $this->line('correcciones con su motivo, registro de auditoria, copias de seguridad y su');
        $this->line('restauracion, y las sondas de salud.');
        $this->line('Funcionan con la licencia caducada, ausente o ilegible. Es una promesa del');
        $this->line('producto, no una casualidad de esta version.');
    }

    private function whatToDo(LicenseOverview $overview): void
    {
        $this->line('Que hacer');
        $this->line('---------');

        $lines = match ($overview->status->state) {
            LicenseState::Valid => ['Nada. Vuelve a mirar cuando se acerque la fecha de caducidad.'],
            LicenseState::ExpiringSoon => [
                'Pide la renovacion al proveedor. Cuando te llegue la clave nueva:',
                '    php artisan license:activate "KQL1...."',
                'o pegala en el panel, en Configuracion > Licencia.',
            ],
            LicenseState::Expired => [
                'Pide la renovacion al proveedor y activa la clave nueva:',
                '    php artisan license:activate "KQL1...."',
                'Mientras tanto se sigue fichando y se puede exportar el registro con normalidad.',
            ],
            LicenseState::Absent => [
                'Activa la clave que te entrego el proveedor:',
                '    php artisan license:activate "KQL1...."',
                'Si no la encuentras, pidesela: es una cadena que empieza por KQL1.',
            ],
            LicenseState::NotYetValid => [
                'La clave es correcta pero su vigencia empieza mas adelante. No hay que hacer nada:',
                'las funcionalidades accesorias se activan solas ese dia.',
            ],
            LicenseState::Unverifiable => self::unverifiableAdvice($overview),
        };

        foreach ($lines as $line) {
            $this->line($line);
        }

        if ($overview->exceeded() !== []) {
            $this->line('');
            $this->line('Ademas, estas por encima de alguna cifra del plan. No corre prisa y no bloquea');
            $this->line('nada: contacta con el proveedor para ampliarlo cuando te venga bien.');
        }
    }

    /**
     * @return list<string>
     */
    private static function unverifiableAdvice(LicenseOverview $overview): array
    {
        return match ($overview->status->rejection?->value) {
            'malformed' => [
                'La clave guardada esta incompleta o cortada. Suele pasar al copiarla de un correo.',
                'Vuelve a copiarla entera —empieza por KQL1. y no lleva espacios— y activala otra vez.',
            ],
            'bad_signature' => [
                'La clave guardada no la emitio el fabricante de esta version, o se ha modificado.',
                'Pide una clave nueva al proveedor y activala.',
            ],
            'invalid_payload' => [
                'La clave esta firmada pero le falta informacion. Es un fallo de emision, no tuyo:',
                'avisa al proveedor con la huella que aparece arriba y pide una clave nueva.',
            ],
            'no_public_key' => [
                'Esta instalacion no lleva la clave publica del fabricante, asi que no puede verificar',
                'ninguna licencia. No es un problema de tu clave: es del despliegue.',
                'Avisa al proveedor indicando la version que devuelve GET /api/v1/health.',
            ],
            default => ['Avisa al proveedor: la licencia guardada no se puede verificar.'],
        };
    }

    private static function stateLabel(LicenseOverview $overview): string
    {
        return match ($overview->status->state) {
            LicenseState::Valid => 'VIGENTE',
            LicenseState::ExpiringSoon => 'VIGENTE, caduca pronto',
            LicenseState::Expired => 'CADUCADA (el sistema sigue funcionando)',
            LicenseState::Absent => 'SIN LICENCIA (el sistema sigue funcionando)',
            LicenseState::NotYetValid => 'TODAVIA NO VIGENTE',
            LicenseState::Unverifiable => 'NO SE PUEDE VERIFICAR (el sistema sigue funcionando)',
        };
    }

    private static function limitLabel(PlanUsage $usage): string
    {
        return match ($usage->limit->value) {
            'max_employees' => 'Personas en plantilla',
            default => 'Quioscos activos',
        };
    }

    private static function featureLabel(Feature $feature): string
    {
        return match ($feature) {
            Feature::AdvancedReports => 'Informes por periodo y comparativa con lo contratado',
            Feature::ImpactDashboard => 'Cuadro de impacto y adopcion',
            Feature::PayrollExport => 'Exportacion para nomina',
            Feature::WeeklyEmailSummary => 'Resumen semanal por correo',
            Feature::RealtimePresence => 'Presencia en tiempo real (pasa a actualizarse por sondeo)',
            Feature::WhiteLabel => 'Marca propia (vuelve a la marca del producto)',
            Feature::Telemetry => 'Telemetria opcional',
        };
    }

    private static function restrictionLabel(FeatureAvailability $availability): string
    {
        $since = $availability->since === null ? '' : ' (desde el '.self::day($availability->since).')';

        return match ($availability->restriction?->value) {
            'license_expired' => 'no disponible por licencia caducada'.$since,
            'license_absent' => 'no disponible: no hay licencia activada',
            'license_unverifiable' => 'no disponible: la licencia no se puede verificar',
            'license_not_yet_valid' => 'no disponible hasta que empiece la vigencia'.$since,
            'not_in_plan' => 'no incluida en el plan contratado',
            default => 'no disponible',
        };
    }

    private static function day(\DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new \DateTimeZone('UTC'))->format('d/m/Y');
    }
}

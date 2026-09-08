<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics\Collector;

use App\Modules\Product\Application\Port\DiagnosticsCollector;
use App\Modules\Product\Application\UseCase\DescribeLicenseHandler;
use App\Modules\Product\Domain\ValueObject\DiagnosticsOptions;
use App\Modules\Product\Domain\ValueObject\License;
use App\Modules\Product\Domain\ValueObject\PlanUsage;
use App\Modules\Product\Domain\ValueObject\StoredLicense;
use App\Modules\Shared\Domain\ValueObject\Feature;
use App\Modules\Shared\Domain\ValueObject\UtcInstant;

/**
 * Seccion `license`: en que estado esta la licencia, **sin la razon social del
 * cliente** (RF-PD-04, RF-PD-05).
 *
 * ## Por que esta seccion existe
 *
 * Porque «no me funciona X» tiene como segunda causa mas frecuente «X es una
 * funcionalidad accesoria y la licencia caduco». Con el estado y la lista de
 * degradadas, esa hipotesis se descarta o se confirma sin preguntar nada.
 *
 * ## `customer_name` NO sale, y aqui se cierra un riesgo abierto
 *
 * El doc 07 §6 lo dejo anotado en la tarea 5.3: la razon social viaja en el
 * asiento `license.activated` y no debe salir de la instalacion. El paquete es
 * el unico canal por el que algo sale, asi que es aqui donde se corta. Va la
 * **huella corta** de la clave —doce caracteres— que es lo que sirve para
 * confirmar por telefono que la clave activada es la que se envio, y nada mas.
 *
 * ## Nunca lanza, y sin licencia tampoco
 *
 * «Sin licencia» es un estado, no un error (regla dura 15). Un paquete generado
 * en una instalacion recien montada tiene que salir igual de completo.
 */
final readonly class LicenseCollector implements DiagnosticsCollector
{
    public function __construct(private DescribeLicenseHandler $licenses) {}

    public function section(): string
    {
        return 'license';
    }

    public function collect(DiagnosticsOptions $options): array
    {
        $overview = $this->licenses->handle();
        $status = $overview->status;
        $license = $status->license;

        return [
            'state' => $status->state->value,
            'rejection' => $status->rejection?->value,
            'days_until_expiry' => $status->daysUntilExpiry(),
            'days_since_expiry' => $status->daysSinceExpiry(),
            'expiry_warning_days' => $status->expiryWarningDays,
            'plan' => $license instanceof License ? $license->plan : null,
            'valid_from' => UtcInstant::format($license?->validFrom),
            'valid_until' => UtcInstant::format($license?->validUntil),
            'features' => $license instanceof License ? $license->featureNames() : [],
            'degraded_features' => array_values(array_map(
                static fn (Feature $feature): string => $feature->value,
                array_filter(
                    $status->degradedFeatures(),
                    static fn (Feature $feature): bool => $feature->isImplemented(),
                ),
            )),
            // La huella, jamas la clave: la clave repite el nombre del cliente
            // varias veces y ocupa varias lineas.
            'key_fingerprint' => $overview->stored instanceof StoredLicense
                ? $overview->stored->fingerprint()
                : null,
            'activated_at' => UtcInstant::format($overview->stored?->activatedAt),
            'last_verified_at' => UtcInstant::format($overview->stored?->lastVerifiedAt),
            'plan_usage' => array_map(
                static fn (PlanUsage $usage): array => [
                    'limit' => $usage->limit->value,
                    'contracted' => $usage->contracted,
                    'actual' => $usage->actual,
                    'exceeded' => $usage->isExceeded(),
                ],
                $overview->usage,
            ),
        ];
    }
}

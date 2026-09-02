<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Adapter;

use App\Modules\Product\Application\UseCase\GetLicenseStatusHandler;
use App\Modules\Product\Domain\ValueObject\LicenseStatus;
use App\Modules\Shared\Application\Port\FeatureGate;
use App\Modules\Shared\Domain\ValueObject\Feature;
use App\Modules\Shared\Domain\ValueObject\FeatureAvailability;
use App\Modules\Shared\Domain\ValueObject\FeatureRestriction;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * **El punto unico de decision** de ADR-023, ya enchufado a la licencia.
 *
 * Todo el que quiera saber si una funcionalidad accesoria esta disponible pasa
 * por aqui, y `tests/Architecture/LicenseBoundaryTest.php` comprueba que no hay
 * otra via: ningun fichero fuera de `Product` nombra la tabla `license`, ni el
 * estado de la licencia, ni construye su propia condicion sobre `features`.
 *
 * ## Memoria por peticion
 *
 * El estado se resuelve una vez por peticion y se reutiliza: el informe por
 * periodo y la presencia en vivo comparten la respuesta. Se enlaza con
 * `scoped()` y no con `singleton()`, por la misma razon que los proveedores de
 * la tarea 5.1: un `singleton` sobrevive a la peticion en un trabajador de cola
 * o en Octane, y ahi la memoria dejaria de ser «por peticion» para convertirse
 * en una cache sin invalidacion — una licencia recien activada no surtiria
 * efecto hasta reiniciar el proceso.
 *
 * ## Nunca lanza
 *
 * Si algo se rompe resolviendo el estado —y no deberia, porque el caso de uso ya
 * es tolerante—, se responde **denegando lo accesorio** y se deja un aviso.
 * Denegar y no conceder: conceder por error significaria dar gratis lo que se
 * vende, y denegar por error solo degrada algo accesorio que el cliente
 * recupera en cuanto se arregla. **Ninguna de las dos toca el registro legal**,
 * porque el argumento es un {@see Feature} y ahi no hay nada legal.
 */
final class LicensedFeatureGate implements FeatureGate
{
    private ?LicenseStatus $status = null;

    public function __construct(
        private readonly GetLicenseStatusHandler $licenses,
        private readonly LoggerInterface $logger,
    ) {}

    public function isEnabled(Feature $feature): bool
    {
        return $this->statusOf($feature)->enabled;
    }

    public function statusOf(Feature $feature): FeatureAvailability
    {
        $status = $this->status();

        if (! $status instanceof LicenseStatus) {
            return FeatureAvailability::denied($feature, FeatureRestriction::LicenseUnverifiable);
        }

        return $status->availabilityOf($feature);
    }

    private function status(): ?LicenseStatus
    {
        if ($this->status instanceof LicenseStatus) {
            return $this->status;
        }

        try {
            $this->status = $this->licenses->handle();
        } catch (Throwable $exception) {
            $this->logger->warning('product.license_state_unresolved', [
                'reason' => $exception::class,
            ]);

            return null;
        }

        return $this->status;
    }
}

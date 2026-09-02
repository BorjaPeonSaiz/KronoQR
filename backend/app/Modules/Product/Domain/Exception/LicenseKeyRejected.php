<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Exception;

use App\Modules\Product\Domain\ValueObject\LicenseRejection;

/**
 * La clave que se intenta activar no verifica (RF-PD-04).
 *
 * Solo la lanza el camino de **activacion**. La lectura de la licencia guardada
 * nunca lanza: ahi el mismo hecho es el estado `unverifiable` y el sistema
 * funciona igual (regla dura 15).
 *
 * El texto de usuario sale de `lang/{es,en}/license.php` y **dice que hacer**,
 * que es lo que exige ADR-019 de una degradacion honesta: si la clave esta a
 * medias, volver a copiarla; si la firma no cuadra, pedir una nueva; si esta
 * compilacion no lleva clave publica, revisar el despliegue.
 */
final class LicenseKeyRejected extends ProductDomainException
{
    private function __construct(
        string $message,
        public readonly LicenseRejection $rejection,
        public readonly string $translationKey,
    ) {
        parent::__construct($message);
    }

    public static function because(LicenseRejection $rejection): self
    {
        return new self(
            \sprintf('The license key was rejected: %s.', $rejection->value),
            $rejection,
            'license.errors.rejected.'.$rejection->value,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

/**
 * El resultado de verificar una clave: o la licencia, o el motivo por el que no
 * (RF-PD-04).
 *
 * ## Por que un resultado y no una excepcion
 *
 * Porque el camino que mas veces recorre esta comprobacion es una **lectura**:
 * el `FeatureGate` resuelve el estado de la licencia para decidir si pinta una
 * comparativa. Que una clave guardada este corrupta no es excepcional en ese
 * camino —es uno de los estados normales del producto— y modelarlo como
 * excepcion invita a que alguien no la capture. Una excepcion no capturada en
 * esa lectura seria un `500` en el panel por una fila de licencia, que es
 * exactamente el tipo de fallo que ADR-019 no quiere ver cerca del sistema.
 *
 * La activacion si lanza, pero lanza **despues**, al mirar este resultado: ahi
 * el fallo si es excepcional, porque alguien acaba de pegar una clave con la
 * intencion de que funcione.
 */
final readonly class LicenseVerification
{
    private function __construct(
        public ?License $license,
        public ?LicenseRejection $rejection,
    ) {}

    public static function verified(License $license): self
    {
        return new self($license, null);
    }

    public static function rejected(LicenseRejection $rejection): self
    {
        return new self(null, $rejection);
    }

    /**
     * @phpstan-assert-if-true !null $this->license
     *
     * @phpstan-assert-if-false !null $this->rejection
     */
    public function isVerified(): bool
    {
        return $this->license instanceof License;
    }
}

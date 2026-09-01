<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

use App\Modules\Product\Domain\Exception\InvalidLicenseKey;

/**
 * Las cifras del plan contratado: cuantas personas y cuantos quioscos
 * (RF-PD-04, doc 01 §5).
 *
 * ## Dos y no tres: `max_sites` no existe
 *
 * [ADR-040](../../../../../../docs/adr/ADR-040-un-centro-por-instalacion-y-por-licencia.md)
 * decidio un centro por instalacion y por licencia, y su punto 5 lo dice
 * literalmente: *«la licencia no tiene `max_sites`»*. Una cadena de tres hoteles
 * compra tres licencias. Un limite que siempre vale 1 no limita nada y ademas
 * invita a que alguien lo compruebe algun dia.
 *
 * Una clave emitida por una version que todavia lo incluyera **verifica igual**
 * y el campo se ignora: la tolerancia a campos desconocidos esta razonada en
 * {@see License::fromClaims()}.
 *
 * ## No bloquean nada
 *
 * Superarlos produce aviso, asiento en `audit_log` y cifra en `license:show`
 * (**ADR-028**), nunca un rechazo. Esta clase no tiene ningun metodo del tipo
 * `allowsAnother()` a proposito: si existiera, alguien lo llamaria desde el alta
 * de un empleado, y eso deja a una persona trabajando sin registro horario.
 * Lo que si tiene es {@see self::isExceededBy()}, que **describe** un exceso ya
 * consumado para poder contarlo.
 */
final readonly class LicenseLimits
{
    private function __construct(
        public int $maxEmployees,
        public int $maxDevices,
    ) {}

    /**
     * @throws InvalidLicenseKey si alguna cifra no es un entero positivo
     */
    public static function of(int $maxEmployees, int $maxDevices): self
    {
        foreach (['max_employees' => $maxEmployees, 'max_devices' => $maxDevices] as $field => $value) {
            if ($value < 1) {
                throw InvalidLicenseKey::limitNotPositive($field, $value);
            }
        }

        return new self($maxEmployees, $maxDevices);
    }

    public function contractedFor(PlanLimit $limit): int
    {
        return match ($limit) {
            PlanLimit::Employees => $this->maxEmployees,
            PlanLimit::Devices => $this->maxDevices,
        };
    }

    /**
     * Cuantas unidades por encima del plan hay, o `0` si no se supera.
     *
     * **Describe, no autoriza.** Se llama despues del alta, desde el observador
     * que escucha los eventos, nunca antes de ella.
     */
    public function excessOf(PlanLimit $limit, int $actual): int
    {
        return max(0, $actual - $this->contractedFor($limit));
    }

    public function isExceededBy(PlanLimit $limit, int $actual): bool
    {
        return $this->excessOf($limit, $actual) > 0;
    }
}

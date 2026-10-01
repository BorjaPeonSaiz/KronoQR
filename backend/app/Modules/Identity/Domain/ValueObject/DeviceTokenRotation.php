<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\ValueObject;

use App\Modules\Identity\Domain\Policy\DeviceTokenRotationPolicy;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * La decision de la politica de rotacion (RF-ID-04, ADR-044): que se emite, que
 * token entra en solape y hasta cuando, y que tokens se retiran.
 *
 * Es una orden que el caso de uso ejecuta tal cual dentro de una transaccion;
 * la regla vive en {@see DeviceTokenRotationPolicy}.
 */
final readonly class DeviceTokenRotation
{
    /**
     * @param  list<int>  $retire  Tokens del dispositivo que dejan de valer ya.
     */
    private function __construct(
        public DeviceTokenRotationOutcome $outcome,
        public array $retire,
        public ?int $supersededTokenId,
        public ?DateTimeImmutable $supersededUntil,
    ) {
        // Que una rotacion diga que token entra en solape y hasta cuando lo
        // garantizan los tipos de `rotate()`, el unico camino hasta aqui.
        if ($supersededTokenId !== null && in_array($supersededTokenId, $retire, true)) {
            throw new InvalidArgumentException('Un token no puede quedar en solape y retirarse a la vez.');
        }
    }

    /**
     * @param  list<int>  $retire
     */
    public static function none(array $retire = []): self
    {
        return new self(DeviceTokenRotationOutcome::NONE, $retire, null, null);
    }

    /**
     * @param  list<int>  $retire
     */
    public static function rotate(int $supersededTokenId, DateTimeImmutable $supersededUntil, array $retire = []): self
    {
        return new self(DeviceTokenRotationOutcome::ROTATE, $retire, $supersededTokenId, $supersededUntil);
    }

    /**
     * @param  list<int>  $retire  El relevo que no llego, como minimo.
     */
    public static function redeliver(int $presentedTokenId, DateTimeImmutable $presentedExpiresAt, array $retire): self
    {
        // El firmante sigue en solape con la fecha que YA tenia: se informa para
        // el asiento, pero no se toca (ADR-044: el solape no se alarga).
        return new self(DeviceTokenRotationOutcome::REDELIVER, $retire, $presentedTokenId, $presentedExpiresAt);
    }

    public function issuesToken(): bool
    {
        return $this->outcome !== DeviceTokenRotationOutcome::NONE;
    }

    public function shortensSuperseded(): bool
    {
        return $this->outcome === DeviceTokenRotationOutcome::ROTATE;
    }
}

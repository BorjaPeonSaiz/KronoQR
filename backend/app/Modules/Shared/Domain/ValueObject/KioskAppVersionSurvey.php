<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

/**
 * Que version de la PWA declaran los quioscos que estan latiendo, frente a la
 * del servidor (RF-KI-07, RF-PD-13; bloque 1 de la 2.2.1).
 *
 * Es lo que `Kiosk` le entrega a la sonda `kiosk.app_version` de
 * `product:doctor` por el puerto `KioskAppVersions`: `Product` no puede
 * importar el dominio de `Kiosk`, asi que la regla
 * (`Kiosk\Domain\Policy\AppVersionPolicy`) se aplica alli y aqui viaja solo el
 * resultado.
 *
 * **Sin un solo nombre de quiosco**: viaja en el paquete de diagnostico
 * (ADR-020, regla dura 21). El `uuid` del dispositivo y la version que declara
 * no son datos personales y bastan para encontrarlo en el panel.
 */
final readonly class KioskAppVersionSurvey
{
    /**
     * @param  string|null  $minimumAppVersion  El nucleo `X.Y.Z` del servidor, o `null` si no hay con
     *                                          que comparar (version desconocida o build `-dev`).
     * @param  int  $examined  Quioscos activos con latido reciente que se han comparado.
     * @param  array<string, string|null>  $behind  `uuid` => `app_version` declarada, de los que van por detras.
     * @param  array<string, string|null>  $ahead  `uuid` => `app_version` declarada, de los que van por delante.
     */
    public function __construct(
        public ?string $minimumAppVersion,
        public int $examined = 0,
        public array $behind = [],
        public array $ahead = [],
    ) {}

    /** Un servidor sin version comparable no juzga a nadie. */
    public static function unchecked(): self
    {
        return new self(null);
    }

    public function isEnforced(): bool
    {
        return $this->minimumAppVersion !== null;
    }
}

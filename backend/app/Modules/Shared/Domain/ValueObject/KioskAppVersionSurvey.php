<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Que version de la PWA declaran los quioscos que estan latiendo, frente a la
 * del servidor (RF-KI-07, RF-PD-13).
 *
 * Es lo que `Kiosk` le entrega a la sonda `kiosk.app_version` de
 * `product:doctor` por el puerto `KioskAppVersions`: `Product` no puede
 * importar el dominio de `Kiosk`, asi que la regla
 * (`Kiosk\Domain\Policy\AppVersionPolicy`) se aplica alli y aqui viaja solo el
 * resultado, con el estado de la cola de cada tablet
 * ({@see KioskAppVersionEntry}), que decide si se la puede tocar.
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
     * @param  list<KioskAppVersionEntry>  $behind  Los que van por detras.
     * @param  list<KioskAppVersionEntry>  $ahead  Los que van por delante.
     */
    public function __construct(
        public ?string $minimumAppVersion,
        public int $examined = 0,
        public array $behind = [],
        public array $ahead = [],
    ) {
        // Un sondeo que compara menos quioscos de los que lista, o que lista sin
        // version con la que comparar, daria en `product:doctor` un recuento
        // imposible (V21-1-AR-4).
        if ($examined < 0) {
            throw new InvalidArgumentException('El sondeo de versiones no compara '.$examined.' quioscos.');
        }

        if (\count($behind) + \count($ahead) > $examined) {
            throw new InvalidArgumentException('El sondeo de versiones no lista mas quioscos de los que ha comparado.');
        }

        if ($minimumAppVersion === null && $examined > 0) {
            throw new InvalidArgumentException('Sin version del servidor no se compara ningun quiosco.');
        }
    }

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

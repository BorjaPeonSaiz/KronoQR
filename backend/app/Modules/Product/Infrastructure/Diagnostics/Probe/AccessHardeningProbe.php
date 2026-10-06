<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics\Probe;

use App\Modules\Product\Application\Port\AccessHardeningFacts;
use App\Modules\Product\Application\Port\DoctorProbe;
use App\Modules\Product\Application\UseCase\GetSettingsHandler;
use App\Modules\Product\Domain\ValueObject\DoctorFinding;
use App\Modules\Product\Domain\ValueObject\DoctorStatus;
use App\Modules\Product\Domain\ValueObject\SettingKey;
use App\Modules\Shared\Domain\ValueObject\PinLength;

/**
 * Sondas `access.*` de `product:doctor`: como de cerrada esta la puerta del
 * portal y del panel (ADR-050, RF-ID-09, RS-12; RF-PD-13).
 *
 * ## La pregunta que responde
 *
 * «Con la red que he decidido, ¿la autenticacion esta a la altura?» El portal
 * entra con codigo de empleado y PIN. En la red del hotel, 6 cifras con el
 * bloqueo por empleado bastan (ADR-015); abierto a internet, quien rota
 * direcciones acierta un PIN de 6 cada pocos meses y uno de 8 en decenas de
 * años (ADR-050, residuo 1). Ninguna de las dos cosas rompe nada, y por eso
 * **todo lo de esta sonda es aviso como mucho**: abrir el portal y elegir la
 * longitud son decisiones del cliente, y un fallo (`2`) abortaria `update.sh`
 * por algo que no esta roto.
 *
 * ## Por que es una sonda aparte de {@see EdgeNetworksProbe}
 *
 * Esta lee la base de datos (el ajuste y los recuentos) y aquella no. Si fueran
 * una, una base de datos caida se llevaria por delante los avisos de red, que
 * son justo los que no dependen de ella. La clasificacion del portal si es
 * comun: {@see EdgeNetworksProbe::portalCategory()}.
 *
 * ## Solo cifras (regla dura 21, ADR-020)
 *
 * Los recuentos son numeros y nada mas: ni nombres, ni codigos de empleado, ni
 * UUID. El informe viaja al fabricante, y decir quien conserva el PIN corto es
 * decir a quien atacar.
 */
final readonly class AccessHardeningProbe implements DoctorProbe
{
    public function __construct(
        private GetSettingsHandler $settings,
        private AccessHardeningFacts $facts,
        private string $portalInternal,
    ) {}

    public function family(): string
    {
        return 'access';
    }

    /**
     * @return list<DoctorFinding>
     */
    public function run(): array
    {
        $length = $this->pinLength();

        return [
            $this->pinLengthForExposure($length),
            $this->shortPins($length),
        ];
    }

    /**
     * La longitud con la que se emiten los PIN. Un valor ilegible ya lo
     * denuncia `settings.invalid_keys`; aqui rige el de serie, que es lo que
     * hace el generador.
     */
    private function pinLength(): PinLength
    {
        $raw = $this->settings->handle()->text(SettingKey::IDENTITY_PIN_LENGTH);

        return PinLength::tryFrom((int) $raw) ?? PinLength::SIX;
    }

    /**
     * Portal alcanzable desde internet con PIN de 6 cifras: aviso que recomienda
     * 8 (ADR-050 §3). Con el portal en una red privada, 6 es lo correcto y no
     * se dice nada mas.
     */
    private function pinLengthForExposure(PinLength $length): DoctorFinding
    {
        $id = 'access.pin_length';
        $exposed = \in_array(
            EdgeNetworksProbe::portalCategory($this->portalInternal),
            EdgeNetworksProbe::EXPOSED_PORTAL_CATEGORIES,
            true,
        );
        $details = ['pin_length' => $length->value, 'portal_exposed' => $exposed];

        if (! $exposed) {
            return DoctorFinding::ok($id, $details, ['length' => $length->value]);
        }

        if ($length === PinLength::EIGHT) {
            return new DoctorFinding($id, DoctorStatus::Ok, ['length' => $length->value], $details, 'exposed');
        }

        return DoctorFinding::warning($id, params: ['length' => $length->value], details: $details);
    }

    /**
     * Con el ajuste en 8, cuantas personas en alta conservan un PIN de 6.
     *
     * Siguen valiendo hasta que se restablecen (ADR-050 §1, «Transicion»): no
     * esta roto, pero mientras queden el residuo de PP-09 sigue ahi, y RRHH
     * necesita saber cuantos restablecimientos le faltan.
     */
    private function shortPins(PinLength $length): DoctorFinding
    {
        $id = 'access.short_pins';

        if ($length === PinLength::SIX) {
            return new DoctorFinding($id, DoctorStatus::Ok, [], ['pin_length' => $length->value], 'six');
        }

        $count = $this->facts->activeEmployeesWithPinOf(PinLength::SIX);

        if ($count === 0) {
            return DoctorFinding::ok($id, ['short_pins' => 0]);
        }

        return DoctorFinding::warning($id, params: ['count' => $count], details: ['short_pins' => $count]);
    }
}

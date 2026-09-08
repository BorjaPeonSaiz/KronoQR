<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics\Probe;

use App\Modules\Product\Application\Port\DoctorProbe;
use App\Modules\Product\Application\UseCase\GetSettingsHandler;
use App\Modules\Product\Domain\ValueObject\DoctorFinding;
use App\Modules\Product\Domain\ValueObject\InvalidSetting;
use App\Modules\Product\Domain\ValueObject\ResolvedSettings;
use App\Modules\Product\Domain\ValueObject\SettingsDrift;

/**
 * Sondas `settings.*` de `product:doctor` (RF-PD-13, RF-PD-01).
 *
 * ## `settings.invalid_keys`: el sistema esta funcionando con OTRO valor
 *
 * La resolucion de configuracion es tolerante a proposito (tarea 5.1): una fila
 * corrupta se descarta, rige el valor de serie y se sigue fichando. Eso es lo
 * correcto —parar el fichaje por un ajuste ilegible seria mucho peor— pero deja
 * un hueco peligroso: el cliente cree que tiene puesto 8 y el sistema calcula
 * con 12. Si la clave descartada afecta a las horas trabajadas, la sonda es
 * `failure`; si es de presentacion, `warning`.
 *
 * ## `settings.env_differs_from_db`: lo que el cliente edito ya no manda
 *
 * Alguien puso `ATTENDANCE_MAX_SHIFT_HOURS` en el `.env` y despues alguien lo
 * cambio desde el panel. Manda la base de datos (ADR-017) y el `.env` se queda
 * ahi engañando a quien lo lea. `warning`: no esta roto, pero es la explicacion
 * de la mitad de los «pues yo lo tengo puesto a otra cosa».
 */
final readonly class SettingsProbe implements DoctorProbe
{
    /**
     * @param  array<string, mixed>  $environment
     */
    public function __construct(
        private GetSettingsHandler $settings,
        private array $environment,
    ) {}

    public function family(): string
    {
        return 'settings';
    }

    public function run(): array
    {
        $resolved = $this->settings->handle();

        $invalid = $resolved->invalidKeys;
        $affectsHours = array_filter(
            $invalid,
            static fn (InvalidSetting $setting): bool => $setting->affectsWorkedHours,
        );

        $keys = array_map(static fn (InvalidSetting $setting): string => $setting->key->value, $invalid);

        $findings = [];

        if ($invalid === [] && $resolved->unknownKeys === []) {
            $findings[] = DoctorFinding::ok('settings.invalid_keys', ['invalid' => 0]);
        } elseif ($affectsHours !== []) {
            $findings[] = DoctorFinding::failure(
                'settings.invalid_keys',
                params: ['keys' => implode(', ', $keys)],
                details: ['invalid_keys' => $keys, 'unknown_keys' => $resolved->unknownKeys],
            );
        } else {
            $findings[] = DoctorFinding::warning(
                'settings.invalid_keys',
                params: ['keys' => implode(', ', [...$keys, ...$resolved->unknownKeys])],
                details: ['invalid_keys' => $keys, 'unknown_keys' => $resolved->unknownKeys],
            );
        }

        $findings[] = $this->differences($resolved);

        return $findings;
    }

    /**
     * La misma comparacion que lleva la seccion `configuration` del paquete, y
     * por eso vive en {@see SettingsDrift} y no aqui: dos copias de la regla
     * acabarian señalando claves distintas en el informe que el cliente ve y en
     * el que llega a soporte.
     */
    private function differences(ResolvedSettings $resolved): DoctorFinding
    {
        $drift = SettingsDrift::between($this->environment, $resolved);

        if ($drift->isEmpty()) {
            return DoctorFinding::ok('settings.env_differs_from_db', ['differing' => 0]);
        }

        return DoctorFinding::warning(
            'settings.env_differs_from_db',
            params: ['keys' => $drift->asText()],
            // Las CLAVES, nunca los dos valores: este informe viaja al
            // fabricante dentro del paquete y nada obliga a que el valor salga.
            details: ['keys' => $drift->keys],
        );
    }
}

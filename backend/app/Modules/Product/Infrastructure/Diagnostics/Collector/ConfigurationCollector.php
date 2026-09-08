<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics\Collector;

use App\Modules\Product\Application\Port\DiagnosticsCollector;
use App\Modules\Product\Application\UseCase\GetSettingsHandler;
use App\Modules\Product\Domain\ValueObject\DiagnosticsOptions;
use App\Modules\Product\Domain\ValueObject\InvalidSetting;
use App\Modules\Product\Domain\ValueObject\SettingsDrift;
use App\Modules\Product\Infrastructure\Diagnostics\DiagnosticsConfigurationAllowlist;

/**
 * Seccion `configuration`: el `.env` **filtrado por lista de permitidos**, las
 * filas de configuracion que no se pudieron aplicar y las claves en las que el
 * `.env` y la base de datos dicen cosas distintas (RF-PD-09, RS-08).
 *
 * ## Las tres cosas responden a la misma pregunta
 *
 * «¿Por que este sistema se comporta distinto de como el cliente cree que lo ha
 * configurado?». Y las tres causas habituales son estas: un umbral que no es el
 * que se piensa, una fila guardada que el producto descarta por invalida
 * (`invalid_keys`), y una variable de entorno que quedo puesta a mano y ya no
 * manda porque el panel la sobreescribio en base de datos.
 *
 * ## De las diferencias sale la CLAVE, nunca los dos valores
 *
 * `{"key": "ATTENDANCE_MAX_SHIFT_HOURS", "differs": true}`. Con los valores, la
 * seccion se convertiria en una puerta trasera para sacar del `.env` cualquier
 * variable que ademas fuera una clave de configuracion, esquivando la lista de
 * permitidos de arriba. Con la clave basta para decirle al cliente donde mirar.
 */
final readonly class ConfigurationCollector implements DiagnosticsCollector
{
    /**
     * @param  array<string, mixed>  $environment  El entorno del proceso, inyectado y no leido
     *                                             con `env()`: asi la prueba puede pasarle el `.env.example` entero y
     *                                             afirmar que ninguna de sus claves secretas sale por aqui, que es
     *                                             exactamente la prueba que la ficha 5.9 exige.
     */
    public function __construct(
        private GetSettingsHandler $settings,
        private array $environment,
    ) {}

    public function section(): string
    {
        return 'configuration';
    }

    public function collect(DiagnosticsOptions $options): array
    {
        $resolved = $this->settings->handle();

        return [
            'env' => DiagnosticsConfigurationAllowlist::apply($this->environment),
            'invalid_keys' => array_map(
                static fn (InvalidSetting $invalid): array => [
                    'key' => $invalid->key->value,
                    'reason' => $invalid->translationKey,
                    'affects_worked_hours' => $invalid->affectsWorkedHours,
                ],
                $resolved->invalidKeys,
            ),
            'unknown_keys' => $resolved->unknownKeys,
            'env_differs_from_database' => SettingsDrift::between($this->environment, $resolved)->toArray(),
        ];
    }
}

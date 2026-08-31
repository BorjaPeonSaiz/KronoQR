<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

use App\Modules\Product\Application\Port\SettingsAnomalyReporter;
use App\Modules\Product\Application\Port\SettingsRepository;
use App\Modules\Product\Domain\ValueObject\ResolvedSettings;

/**
 * La configuracion de la instalacion, resuelta (**RF-PD-01**).
 *
 * ## Es el UNICO punto de lectura, y por eso existe
 *
 * Lo usan los tres consumidores: `GET /api/v1/settings`, el adaptador de
 * umbrales operativos que alimenta el fichaje y el de la marca. Si cada uno
 * resolviera por su cuenta, «lo que la instalacion tiene configurado» acabaria
 * significando tres cosas ligeramente distintas segun quien preguntara, y —peor—
 * el aviso de una fila corrupta se anunciaria en uno solo de los tres caminos.
 * Lo usaran tambien el asistente de puesta en marcha (5.5) y `doctor` (5.9).
 *
 * **Devuelve el catalogo entero**, no solo lo guardado: un panel que enseñara
 * unicamente las filas existentes ocultaria justo lo que el cliente todavia no ha
 * configurado. La procedencia de cada valor la lleva el propio `SettingValue`.
 *
 * ## Nunca falla, y deja constancia de lo que descarta
 *
 * La resolucion es tolerante (ver {@see ResolvedSettings}): una fila corrupta se
 * descarta, rige el valor de serie y se ficha con normalidad. Eso no puede ser
 * silencioso, asi que lo que se ha descartado se anuncia por el puerto
 * {@see SettingsAnomalyReporter} —que registra un `warning` agrupado por ventana,
 * sin datos personales— y viaja ademas en `meta.invalid_keys` de la respuesta.
 */
final readonly class GetSettingsHandler
{
    public function __construct(
        private SettingsRepository $settings,
        private SettingsAnomalyReporter $anomalies,
    ) {}

    public function handle(): ResolvedSettings
    {
        $settings = ResolvedSettings::resolve($this->settings->storedValues());

        $this->anomalies->report($settings);

        return $settings;
    }
}

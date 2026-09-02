<?php

declare(strict_types=1);

namespace App\Support\Health;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Throwable;

/**
 * El estado de la licencia para `GET /api/v1/health`, **sin tocar la base de
 * datos** (doc 02 §10.5, ADR-018, paso 9 de la tarea 5.3).
 *
 * ## Por que no lee la tabla `license`
 *
 * Porque `/health` es una **sonda de vida** y su regla, desde la tarea 1.7, es
 * que no toca dependencias: una sonda de vida que consulta PostgreSQL hace que
 * Docker reinicie el contenedor de PHP cuando lo que esta caido es PostgreSQL,
 * se pierden las conexiones sanas y el diagnostico apunta al sitio equivocado.
 * Añadir el estado de licencia no puede costar esa propiedad.
 *
 * Asi que se lee **solo de la cache y sin recalcular nada**. La escribe
 * `LicensedFeatureGate` cada vez que resuelve el estado de verdad, lo que ocurre
 * en cualquier peticion del panel y en cada `license:show`.
 *
 * ## `unknown` es una respuesta honesta, no un fallo
 *
 * Significa literalmente «esta sonda no ha podido saberlo sin tocar nada»: Redis
 * no responde, o nadie ha resuelto el estado desde el arranque. Quien necesita
 * el dato autoritativo tiene `GET /api/v1/license` y `php artisan license:show`,
 * que si consultan, verifican y anotan `last_verified_at`. `doctor` (5.9) usara
 * el segundo.
 *
 * **Nunca se confunde con `absent`.** «No hay licencia activada» es un estado
 * conocido y con nombre propio; «no lo se» es otra cosa, y mezclarlos haria que
 * un Redis caido pareciera un cliente sin licencia.
 *
 * ## Por que vive aqui y no en `Product`
 *
 * Porque su unico consumidor es `HealthController`, que esta fuera de los
 * modulos y **no puede depender de ninguno** (Deptrac: `AppFramework` no alcanza
 * `App\Modules\*`, y esa frontera esta intacta desde la tarea 0.2). La direccion
 * que si esta admitida es la contraria —un modulo alcanza el armazon, como hace
 * todo `Product/Http`—, asi que la constante de la clave vive aqui y es
 * `LicensedFeatureGate` quien la importa para escribir.
 *
 * Es tambien el sitio correcto por contenido: junto a `DependencyProbe`, que es
 * lo que responde `GET /api/v1/ready`.
 */
final readonly class LicenseStateProbe
{
    /**
     * Clave versionada, como las de la tarea 5.1: si algun dia cambia lo que se
     * guarda, la clave nueva no se encuentra con el valor viejo de un despliegue
     * anterior.
     */
    public const string CACHE_KEY = 'kronoqr:product:license_state:v1';

    public function __construct(private CacheRepository $cache) {}

    public function lastKnownState(): ?string
    {
        try {
            $state = $this->cache->get(self::CACHE_KEY);
        } catch (Throwable) {
            // Redis caido. La sonda de vida sigue respondiendo 200, que es toda
            // su razon de ser.
            return null;
        }

        return \is_string($state) && $state !== '' ? $state : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Adapter;

use App\Modules\Product\Application\Port\LicenseStatePublisher;
use App\Support\Health\LicenseStateProbe;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Throwable;

/**
 * Deja el estado de la licencia en la clave de cache que lee
 * {@see LicenseStateProbe}, para `GET /api/v1/health` (doc 02 §10.5).
 *
 * **La constante de la clave vive en `App\Support\Health` y no aqui** porque su
 * consumidor —`HealthController`— esta fuera de los modulos y no puede depender
 * de ninguno. La direccion admitida es la contraria: un modulo alcanza el
 * armazon, que es lo que ya hace todo `Product/Http`.
 *
 * **Se traga cualquier fallo.** Publicar es un efecto secundario de una lectura:
 * si Redis no responde, la sonda dira `unknown` —que es la verdad— y la peticion
 * que iba a servirse se sirve igual.
 */
final readonly class CachedLicenseStatePublisher implements LicenseStatePublisher
{
    public function __construct(
        private CacheRepository $cache,
        private int $ttlSeconds,
    ) {}

    public function publish(string $state): void
    {
        try {
            $this->cache->put(LicenseStateProbe::CACHE_KEY, $state, $this->ttlSeconds);
        } catch (Throwable) {
            // Redis caido. La sonda respondera `unknown`.
        }
    }
}

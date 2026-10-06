<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Adapter;

use App\Modules\Identity\Application\Port\PortalOriginAttempts;
use App\Modules\Identity\Domain\ValueObject\OriginAttemptHistory;
use App\Modules\Identity\Domain\ValueObject\RequestOrigin;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * La cuenta de fallos por origen del portal, sobre la cache **`resilient`**
 * (RS-12, ADR-050 §2).
 *
 * La misma cache que el contador del PIN (`CachePinAttempts`) y por el mismo
 * motivo: `resilient` es Redis con el disco local detras, asi que sin Redis la
 * cuenta sigue en el disco de la unica maquina que atiende peticiones
 * (`config/cache.php`). Sin Redis el portal ya esta cerrado por el limitador
 * (`cache.limiter` falla cerrado), pero el contador no depende de eso.
 *
 * **La clave de cache lleva la direccion en claro** (`RequestOrigin::key()`). Es
 * el unico sitio donde vive asi: la cache no viaja en el paquete de diagnostico
 * ni se escribe en ningun log, y caduca sola en cuanto pasa la ventana o el
 * bloqueo.
 *
 * Leer, calcular y escribir no es atomico: dos fallos simultaneos del mismo
 * origen pueden contar como uno. Es el mismo compromiso que el contador del PIN,
 * y el error cae del lado de dejar un intento mas a quien ya lleva diecinueve.
 */
final readonly class CachePortalOriginAttempts implements PortalOriginAttempts
{
    private const string PREFIX = 'identity:portal-origin:';

    private const string OPENINGS_PREFIX = 'identity:portal-origin-openings:';

    public function __construct(private Cache $cache) {}

    public function historyFor(RequestOrigin $origin): OriginAttemptHistory
    {
        $stored = $this->cache->get($this->keyFor($origin));

        if (! \is_array($stored)) {
            return OriginAttemptHistory::empty();
        }

        $failures = [];

        foreach (\is_array($stored['failures'] ?? null) ? $stored['failures'] : [] as $failure) {
            if (\is_int($failure)) {
                $failures[] = $failure;
            }
        }

        $lockedUntil = $stored['locked_until'] ?? null;

        return new OriginAttemptHistory($failures, \is_int($lockedUntil) ? $lockedUntil : null);
    }

    public function save(RequestOrigin $origin, OriginAttemptHistory $history, int $ttlSeconds): void
    {
        $this->cache->put(
            $this->keyFor($origin),
            ['failures' => $history->failures, 'locked_until' => $history->lockedUntil],
            max(1, $ttlSeconds),
        );
    }

    public function forget(RequestOrigin $origin): bool
    {
        $key = $this->keyFor($origin);
        $had = $this->cache->has($key);

        $this->cache->forget($key);

        return $had;
    }

    public function countLockOpening(int $hourStart): int
    {
        $key = self::OPENINGS_PREFIX.$hourStart;

        // Dos horas de vida: la que cuenta y un margen para el reloj.
        $this->cache->add($key, 0, 7200);

        $count = $this->cache->increment($key);

        return \is_int($count) ? $count : 1;
    }

    private function keyFor(RequestOrigin $origin): string
    {
        return self::PREFIX.$origin->key();
    }
}

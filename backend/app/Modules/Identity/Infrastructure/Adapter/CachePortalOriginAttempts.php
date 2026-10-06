<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Adapter;

use App\Modules\Identity\Application\Port\PortalOriginAttempts;
use App\Modules\Identity\Domain\ValueObject\OriginAttemptHistory;
use App\Modules\Identity\Domain\ValueObject\RequestOrigin;
use App\Modules\Shared\Infrastructure\Cache\CacheMutex;
use Closure;
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
 * ## Un candado por origen, y no un `increment`
 *
 * Contar un fallo es leer, calcular y escribir, y sin candado veinticinco fallos
 * simultaneos del mismo origen se quedaban en uno a tres: quien paralelizaba
 * multiplicaba por diez sus intentos y el bloqueo no llegaba nunca
 * (`PortalOriginConcurrencyTest`). Por eso {@see self::update()} hace las tres
 * cosas con el candado del origen cogido.
 *
 * Un contador por cubeta con `increment` no sirve aqui: es atomico en Redis
 * (`INCRBY`), pero en el almacen `file` —el que cuenta con Redis caido—
 * `FileStore::increment()` es a su vez leer y escribir, y pierde los mismos
 * incrementos. El candado si funciona en los dos: el de Redis es un `SET NX` y
 * el de disco es un `add` con `flock` exclusivo (`FileStore::lock()`), y el
 * almacen `failover` entrega el de quien responde, igual que con los datos.
 * Con el mismo candado se cuenta el techo de asientos por hora
 * ({@see self::countLockOpening()}), por el mismo motivo.
 *
 * El candado es {@see CacheMutex}, el mismo que el del contador del PIN: la
 * espera es por intentos y no por reloj, y si tras {@see self::LOCK_ATTEMPTS}
 * intentos sigue ocupado —o el almacen no puede darlo—, la cuenta se hace sin
 * el: un fallo de mas o de menos en una avalancha que dura segundos, en vez de
 * un `500` en el portal.
 */
final readonly class CachePortalOriginAttempts implements PortalOriginAttempts
{
    private const string PREFIX = 'identity:portal-origin:';

    private const string OPENINGS_PREFIX = 'identity:portal-origin-openings:';

    private const string LOCK_PREFIX = 'identity:portal-origin-lock:';

    /** Intentos de coger el candado antes de contar sin el: unos tres segundos. */
    private const int LOCK_ATTEMPTS = 300;

    private const int LOCK_RETRY_MICROSECONDS = 10_000;

    private CacheMutex $mutex;

    public function __construct(private Cache $cache)
    {
        $this->mutex = new CacheMutex($cache, self::LOCK_ATTEMPTS, self::LOCK_RETRY_MICROSECONDS);
    }

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

    public function update(RequestOrigin $origin, Closure $transition, int $ttlSeconds): array
    {
        return $this->guarded($this->keyFor($origin), function () use ($origin, $transition, $ttlSeconds): array {
            $before = $this->historyFor($origin);
            $after = $transition($before);

            $this->write($origin, $after, $ttlSeconds);

            return [$before, $after];
        });
    }

    public function save(RequestOrigin $origin, OriginAttemptHistory $history, int $ttlSeconds): void
    {
        $this->guarded($this->keyFor($origin), fn (): bool => $this->write($origin, $history, $ttlSeconds));
    }

    public function forget(RequestOrigin $origin): bool
    {
        $key = $this->keyFor($origin);

        return $this->guarded($key, function () use ($key): bool {
            $had = $this->cache->has($key);

            $this->cache->forget($key);

            return $had;
        });
    }

    public function countLockOpening(int $hourStart): int
    {
        $key = self::OPENINGS_PREFIX.$hourStart;

        return $this->guarded($key, function () use ($key): int {
            $count = $this->cache->get($key);
            $next = (\is_int($count) ? $count : 0) + 1;

            // Dos horas de vida: la que cuenta y un margen para el reloj.
            $this->cache->put($key, $next, 7200);

            return $next;
        });
    }

    private function write(RequestOrigin $origin, OriginAttemptHistory $history, int $ttlSeconds): bool
    {
        return $this->cache->put(
            $this->keyFor($origin),
            ['failures' => $history->failures, 'locked_until' => $history->lockedUntil],
            max(1, $ttlSeconds),
        );
    }

    /**
     * Ejecuta `$work` con el candado de `$key` cogido, o sin el si no se puede
     * coger ({@see CacheMutex}).
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $work
     * @return TResult
     */
    private function guarded(string $key, Closure $work): mixed
    {
        return $this->mutex->guarded(self::LOCK_PREFIX.$key, $work);
    }

    private function keyFor(RequestOrigin $origin): string
    {
        return self::PREFIX.$origin->key();
    }
}

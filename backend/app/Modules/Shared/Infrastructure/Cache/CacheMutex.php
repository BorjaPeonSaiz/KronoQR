<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Cache;

use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Un candado de la cache que **ordena** una cuenta sin poder **impedirla**
 * (RS-12, ADR-050, regla dura 19).
 *
 * Lo comparten los dos contadores que leen, calculan y escriben una entrada de
 * la cache compartida: el de fallos del PIN por empleado y puerta
 * (`CachePinAttempts`) y el de fallos por origen del portal
 * (`Identity\Infrastructure\Adapter\CachePortalOriginAttempts`). Antes cada uno
 * llevaba su copia de este mismo codigo; dos copias de un control de seguridad
 * son dos sitios donde corregirlo y uno donde se olvida.
 *
 * ## Por que un candado y no un `increment`
 *
 * Las entradas son listas de marcas de tiempo, no numeros, y en el almacen
 * `file` —el que cuenta con Redis caido— `FileStore::increment()` es a su vez
 * leer y escribir. El candado funciona en los dos: el de Redis es un `SET NX`,
 * el de disco un `add` con `flock` exclusivo (`FileStore::lock()`), y el almacen
 * `failover` entrega el de quien responde, igual que con los datos.
 *
 * ## Nunca un `500`, nunca un fichaje perdido
 *
 * **La espera es por intentos y no por reloj**: `Lock::block()` mide con `now()`,
 * que las pruebas detienen y nunca agotaria la espera. Si tras `$attempts`
 * intentos el candado sigue ocupado —un proceso muerto con el cogido, o una
 * avalancha—, o el almacen no puede darlo ni soltarlo, **el trabajo se hace sin
 * el**: un fallo de mas o de menos en una rafaga, en lugar de una excepcion en
 * el portal o en el fichaje de respaldo del quiosco. El candado caduca solo a
 * los {@see self::LOCK_SECONDS} segundos si quien lo tiene muere sin soltarlo.
 *
 * Las excepciones **del trabajo** si suben: solo se tragan las del almacen al
 * coger y soltar el candado, que son las que no dicen nada de la cuenta.
 */
final readonly class CacheMutex
{
    /** Lo que vive el candado si el proceso que lo tiene muere sin soltarlo. */
    public const int LOCK_SECONDS = 10;

    /**
     * @param  int  $attempts  Intentos de coger el candado antes de trabajar sin el.
     * @param  int  $retryMicroseconds  Espera entre dos intentos.
     */
    public function __construct(
        private Cache $cache,
        private int $attempts,
        private int $retryMicroseconds,
    ) {}

    /**
     * Ejecuta `$work` con el candado `$name` cogido, o sin el si no se consigue.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $work
     * @return TResult
     */
    public function guarded(string $name, Closure $work): mixed
    {
        $lock = $this->acquire($name);

        try {
            return $work();
        } finally {
            $this->release($lock);
        }
    }

    private function acquire(string $name): ?Lock
    {
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            return null;
        }

        try {
            $lock = $store->lock($name, self::LOCK_SECONDS);

            for ($attempt = 1; $attempt <= $this->attempts; $attempt++) {
                if ($lock->get() === true) {
                    return $lock;
                }

                Sleep::usleep($this->retryMicroseconds);
            }
        } catch (Throwable) {
            // Redis se cae entre la entrega del candado y su uso: los datos ya
            // caen al disco por su cuenta, y el trabajo se hace sin candado.
        }

        return null;
    }

    private function release(?Lock $lock): void
    {
        if (! $lock instanceof Lock) {
            return;
        }

        try {
            $lock->release();
        } catch (Throwable) {
            // Si no se puede soltar, caduca solo en LOCK_SECONDS.
        }
    }
}

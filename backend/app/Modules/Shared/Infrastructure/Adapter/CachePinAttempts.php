<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Adapter;

use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\PinAttempts;
use App\Modules\Shared\Domain\Policy\PinLockoutPolicy;
use App\Modules\Shared\Domain\ValueObject\PinOrigin;
use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * El bloqueo escalonado del PIN sobre la cache compartida (RS-12, doc 02 §7.5).
 *
 * **En cache compartida y no en la fila del empleado.** Un contador de intentos
 * fallidos es efimero por naturaleza y de escritura frecuente: llevarlo a
 * `employees` convertiria cada PIN mal tecleado en un `UPDATE` sobre la fila de
 * una persona —con su `updated_at` cambiando, su fila reescrita y su indice
 * tocado— y dejaria en la tabla de la plantilla un dato que caduca solo. Es la
 * misma decision, y por los mismos motivos, que tomo el bloqueo del panel en
 * `Identity\Infrastructure\Adapter\CacheLoginAttempts` —nombrado en prosa y no
 * con `@see`, porque una referencia resoluble seria una dependencia entre
 * modulos que la frontera del §1.6 no concede—.
 *
 * En produccion la cache es Redis, compartida por todos los trabajadores PHP: un
 * contador por proceso no contaria nada. En la suite es el driver `array`, que
 * basta porque cada prueba corre en un proceso.
 *
 * ## Por que ya no se apoya en el limitador de Laravel
 *
 * `Illuminate\Cache\RateLimiter` sabe expresar **un** umbral con **una**
 * duracion, y el §7.5 pide tres escalones crecientes con una ventana de olvido
 * deslizante. Con el limitador harian falta tres claves solapadas y aun asi la
 * ventana se reiniciaria desde el primer fallo, no desde el ultimo: quien fallara
 * una vez cada veintitres horas no acumularia nunca, y el escalon alto seria
 * inalcanzable para justo el patron que existe para frenar.
 *
 * ## Lo que se guarda: marcas de tiempo, no un numero
 *
 * Una entrada por empleado y puerta, con la lista de instantes de sus ultimos
 * fallos. De esa lista salen las tres respuestas del puerto sin necesidad de una
 * segunda clave de bloqueo, y salen **coherentes entre si**: el numero de fallos
 * son las marcas dentro de la ventana, el escalon lo decide
 * {@see PinLockoutPolicy} y el desbloqueo es el ultimo fallo mas ese escalon. Con
 * un contador plano habria que guardar ademas el instante del bloqueo, y dos
 * datos que hay que mantener de acuerdo son dos datos que algun dia no lo estan.
 *
 * La lista esta **acotada** por la politica: por encima del ultimo escalon el
 * bloqueo ya no crece, asi que guardar mas marcas engordaria la entrada sin
 * cambiar ninguna respuesta.
 *
 * ## La clave lleva la puerta, y eso es media RS-12
 *
 * `pin-failures:{origen}:{uuid}`. Quiosco y portal cuentan por separado (§7.5),
 * de modo que sondear una puerta no cierra la otra. `clear()` recorre las dos:
 * al restablecer el PIN, el anterior deja de existir y ningun contador levantado
 * contra el describe ya nada (RF-ID-09).
 *
 * ## Un riesgo que se acepta a proposito
 *
 * Quien conozca el codigo de un empleado puede bloquearle el PIN en tres
 * intentos, y un bloqueo tambien puede alcanzar a la propia persona: fichajes
 * por PIN encolados sin red que se rechazan al sincronizar, a veces horas
 * despues. «Su tarjeta sigue funcionando» no bastaba —la via del PIN existe
 * justo para quien no la lleva (RF-AT-11)— y lo que sostiene el riesgo es
 * **RN-19** (ADR-043): cada intento rechazado de alguien que puede fichar deja
 * en `scan_events` a quien correspondia el codigo y, si ningun fichaje suyo lo
 * subsana en 10 minutos, la revision diaria abre una incidencia
 * `rejected_pin_scan` para su responsable. El bloqueo no hace desaparecer la
 * jornada: la manda a revision humana, y la respuesta al quiosco no cambia
 * (RS-03). La alternativa —no bloquear— deja un espacio de 10^6 abierto a
 * fuerza bruta, que es lo que RS-12 existe para impedir.
 *
 * ## Un candado por empleado y puerta
 *
 * Anotar un fallo es leer la lista, anadir la marca y escribirla, y sin candado
 * los fallos simultaneos contra el mismo codigo se pisaban: con veinticinco
 * intentos a la vez, de nueve a veinte se probaban contra el PIN real y el
 * contador guardaba de tres a nueve. Quien paralelizaba no llegaba al escalon
 * que le tocaba y el espacio de busqueda por empleado y dia dejaba de ser el
 * calculado (`PinLockoutConcurrencyTest`, bloque 12 de la 2.2.0). Por eso
 * {@see self::recordFailure()} y {@see self::clear()} trabajan con el candado
 * de la entrada cogido.
 *
 * El candado es el de la cache —`SET NX` en Redis, `add` con `flock` en el disco,
 * y el almacen `failover` entrega el de quien responde—; un `increment` no valdria
 * porque la entrada es una lista de marcas y `FileStore::increment()` tampoco es
 * atomico. Su nombre lleva el `employee_uuid` y la puerta, igual que la clave: ni
 * el codigo de la tarjeta ni el nombre de nadie.
 *
 * **Nunca bloquea al empleado ni rompe RS-03.** Sin contienda cuesta un viaje mas
 * a la cache, el mismo en el camino del empleado real y en el del señuelo, asi que
 * los dos rechazos siguen costando lo mismo. Solo espera quien llega mientras otro
 * proceso cuenta un fallo **del mismo empleado y la misma puerta** —el del señuelo
 * del quiosco lo comparten los codigos inexistentes, que ya frena el limite por
 * dispositivo—, y la espera es por intentos y no por reloj (`Lock::block()` mide
 * con `now()`, que las pruebas detienen). Si tras {@see self::LOCK_ATTEMPTS}
 * intentos sigue ocupado, o el almacen no puede darlo, se cuenta sin el: un fallo
 * de mas o de menos en una avalancha, nunca un `500` ni un fichaje perdido (regla
 * dura 19).
 *
 * **Todos los umbrales son configuracion** (regla dura 13): `IDENTITY_PIN_*` en
 * `config/identity.php`. Se leen en cada llamada y no en el constructor para que
 * una prueba pueda cambiarlos con `config()->set()` sin reconstruir el servicio.
 */
final readonly class CachePinAttempts implements PinAttempts
{
    /**
     * Prefijo propio para no chocar ni con el `throttle` de la ruta ni con el
     * bloqueo del panel: son tres controles distintos sobre el mismo almacen, y
     * compartir clave dejaria dos de ellos sin efecto.
     */
    private const string PREFIX = 'workforce:pin-failures:';

    private const string LOCK_PREFIX = 'workforce:pin-failures-lock:';

    /** Lo que vive el candado si el proceso que lo tiene muere sin soltarlo. */
    private const int LOCK_SECONDS = 10;

    /**
     * Intentos de coger el candado antes de contar sin el: medio segundo como
     * mucho, y solo con contienda sobre el mismo empleado y la misma puerta.
     */
    private const int LOCK_ATTEMPTS = 100;

    private const int LOCK_RETRY_MICROSECONDS = 5_000;

    public function __construct(
        private Cache $cache,
        private Clock $clock,
    ) {}

    public function isLocked(string $employeeUuid, PinOrigin $origin): bool
    {
        return $this->secondsUntilUnlock($employeeUuid, $origin) > 0;
    }

    public function secondsUntilUnlock(string $employeeUuid, PinOrigin $origin): int
    {
        $policy = $this->policy();

        return $this->secondsUntilUnlockOf(
            $this->failuresWithinWindow($employeeUuid, $origin, $policy),
            $policy,
        );
    }

    public function recordFailure(string $employeeUuid, PinOrigin $origin): int
    {
        $key = $this->keyFor($employeeUuid, $origin);

        return $this->guarded($this->lockFor($employeeUuid, $origin), function () use ($key, $employeeUuid, $origin): int {
            $policy = $this->policy();

            // Leido y escrito con el candado cogido: entre las dos cosas no se
            // mete ningun otro fallo del mismo empleado por la misma puerta.
            $failures = $this->failuresWithinWindow($employeeUuid, $origin, $policy);
            $before = $this->secondsUntilUnlockOf($failures, $policy);

            $failures[] = $this->now();

            // Solo las mas recientes: `array_slice` con desplazamiento negativo se
            // queda con la cola de la lista —y reindexa, asi que sigue siendo una
            // lista—, que es la que decide tanto el escalon como el instante de
            // desbloqueo.
            $failures = \array_slice($failures, -$policy->trackedFailures());

            // El TTL se renueva en cada fallo, y esa renovacion **es** la ventana
            // deslizante: la entrada muere sola cuando pasa el tiempo de olvido sin
            // que nadie vuelva a fallar. Sin esto haria falta un barrido periodico
            // para limpiar contadores de gente que se equivoco una vez en marzo.
            $this->cache->put($key, $failures, $policy->resetSeconds());

            // El flanco: este fallo abre el bloqueo solo si antes no lo habia.
            // Un fallo simultaneo que llega con el bloqueo ya abierto por otro
            // cuenta, pero no lo abre por segunda vez.
            return $before > 0 ? 0 : $this->secondsUntilUnlockOf($failures, $policy);
        });
    }

    public function clear(string $employeeUuid): void
    {
        foreach (PinOrigin::cases() as $origin) {
            $key = $this->keyFor($employeeUuid, $origin);

            // Con el candado, para que un fallo que estuviera contandose no
            // reescriba despues la lista que se acaba de borrar (RF-ID-09).
            $this->guarded($this->lockFor($employeeUuid, $origin), fn (): bool => $this->cache->forget($key));
        }
    }

    /**
     * Segundos hasta el desbloqueo con estos fallos: el ultimo fallo mas el
     * escalon que le toca a cuantos son.
     *
     * @param  list<int>  $failures
     */
    private function secondsUntilUnlockOf(array $failures, PinLockoutPolicy $policy): int
    {
        if ($failures === []) {
            return 0;
        }

        $lockSeconds = $policy->lockSecondsFor(\count($failures));

        if ($lockSeconds === 0) {
            return 0;
        }

        return max(0, max($failures) + $lockSeconds - $this->now());
    }

    /**
     * Ejecuta `$work` con el candado `$lockName` cogido, o sin el si no se puede
     * coger (ver el docblock de la clase).
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $work
     * @return TResult
     */
    private function guarded(string $lockName, Closure $work): mixed
    {
        $lock = $this->acquire($lockName);

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

            for ($attempt = 1; $attempt <= self::LOCK_ATTEMPTS; $attempt++) {
                if ($lock->get() === true) {
                    return $lock;
                }

                Sleep::usleep(self::LOCK_RETRY_MICROSECONDS);
            }
        } catch (Throwable) {
            // Redis se cae entre la entrega del candado y su uso: los datos ya
            // caen al disco por su cuenta, y el fallo se cuenta sin candado.
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

    /**
     * Los fallos que siguen contando: los que caen dentro de la ventana de
     * olvido.
     *
     * Se filtra tambien al leer y no solo al escribir porque el TTL de la cache
     * es un desalojo, no una garantia: Redis puede servir una entrada un instante
     * despues de su caducidad nominal, y el driver `array` de las pruebas no
     * caduca nada por si solo cuando el reloj lo mueve una prueba.
     *
     * @return list<int> Marcas de tiempo Unix, en orden de llegada.
     */
    private function failuresWithinWindow(string $employeeUuid, PinOrigin $origin, PinLockoutPolicy $policy): array
    {
        $stored = $this->cache->get($this->keyFor($employeeUuid, $origin));

        if (! \is_array($stored)) {
            return [];
        }

        $floor = $this->now() - $policy->resetSeconds();
        $failures = [];

        foreach ($stored as $failure) {
            if (\is_int($failure) && $failure > $floor) {
                $failures[] = $failure;
            }
        }

        return $failures;
    }

    private function keyFor(string $employeeUuid, PinOrigin $origin): string
    {
        return self::PREFIX.$origin->value.':'.$employeeUuid;
    }

    /**
     * El candado de una entrada: el mismo `employee_uuid` y la misma puerta que
     * su clave, nunca el codigo de la tarjeta ni el nombre (regla dura 21).
     */
    private function lockFor(string $employeeUuid, PinOrigin $origin): string
    {
        return self::LOCK_PREFIX.$origin->value.':'.$employeeUuid;
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    /**
     * Los seis umbrales, resueltos de la configuracion (regla dura 13 y 14).
     *
     * El primer escalon reutiliza las dos claves que ya existian desde la tarea
     * 1.13 —`max_attempts` y `lockout_seconds`— en lugar de crear un
     * `tier1_attempts` paralelo: dos nombres para el mismo numero es la forma en
     * que una instalacion acaba con el valor cambiado en uno de los dos.
     */
    private function policy(): PinLockoutPolicy
    {
        return new PinLockoutPolicy(
            tier1Attempts: max(1, config()->integer('identity.pin.max_attempts')),
            tier1Seconds: max(1, config()->integer('identity.pin.lockout_seconds')),
            tier2Attempts: max(1, config()->integer('identity.pin.lockout_tier2_attempts')),
            tier2Seconds: max(1, config()->integer('identity.pin.lockout_tier2_seconds')),
            tier3Attempts: max(1, config()->integer('identity.pin.lockout_tier3_attempts')),
            tier3Seconds: max(1, config()->integer('identity.pin.lockout_tier3_seconds')),
            resetSeconds: max(1, config()->integer('identity.pin.lockout_reset_hours')) * 3600,
        );
    }
}

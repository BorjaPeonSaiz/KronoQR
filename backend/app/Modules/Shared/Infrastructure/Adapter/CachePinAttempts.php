<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Adapter;

use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\PinAttempts;
use App\Modules\Shared\Domain\Policy\PinLockoutPolicy;
use App\Modules\Shared\Domain\ValueObject\PinOrigin;
use Illuminate\Contracts\Cache\Repository as Cache;

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
 * intentos. Se acepta porque **su tarjeta sigue funcionando**: el camino de
 * RF-AT-01 no pasa por aqui, asi que la persona sigue pudiendo fichar y la regla
 * dura 19 se sostiene. La alternativa —no bloquear— deja un espacio de 10^6
 * abierto a fuerza bruta, que es lo que RS-12 existe para impedir.
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
        $failures = $this->failuresWithinWindow($employeeUuid, $origin, $policy);

        if ($failures === []) {
            return 0;
        }

        $lockSeconds = $policy->lockSecondsFor(\count($failures));

        if ($lockSeconds === 0) {
            return 0;
        }

        $unlocksAt = max($failures) + $lockSeconds;

        return max(0, $unlocksAt - $this->now());
    }

    public function recordFailure(string $employeeUuid, PinOrigin $origin): void
    {
        $policy = $this->policy();

        $failures = $this->failuresWithinWindow($employeeUuid, $origin, $policy);
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
        $this->cache->put(
            $this->keyFor($employeeUuid, $origin),
            $failures,
            $policy->resetSeconds(),
        );
    }

    public function clear(string $employeeUuid): void
    {
        foreach (PinOrigin::cases() as $origin) {
            $this->cache->forget($this->keyFor($employeeUuid, $origin));
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

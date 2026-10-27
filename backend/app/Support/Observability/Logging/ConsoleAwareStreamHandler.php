<?php

declare(strict_types=1);

namespace App\Support\Observability\Logging;

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

/**
 * El manejador del canal `stderr`, el primario del producto (doc 02 §8.2.1): la
 * salida de error del proceso, SALVO en una orden de consola lanzada a mano con
 * `docker compose exec` dentro de un contenedor ya en marcha (decision del propietario del 20-10-2026).
 *
 * ## El hueco que cierra
 *
 * `docker compose exec app php artisan …` es la forma en que el IT del hotel
 * habla con el producto, y la salida de error de ese proceso es SU TERMINAL. Todo
 * lo que el producto registraba —un INFO de rutina, un WARNING del cortacircuitos,
 * el ERROR con la excepcion entera— le salia como lineas JSON en mitad de la
 * respuesta de la orden. Para quien no conoce el sistema, un `RedisException` en
 * una orden que ha ido bien es una pista falsa. Y esas lineas no llegaban al log
 * del contenedor (`docker compose logs app`), que es donde se buscan despues.
 *
 * ## La regla: una senal explicita, no el PID
 *
 * `infra/docker/php/entrypoint.sh` exporta `KQ_CONTAINER_MAIN=1` justo antes de su
 * `exec`. Lo heredan el proceso principal de cada contenedor y TODO lo que este
 * lanza: los hijos de Horizon y de `schedule:work`, los `docker compose run --rm`
 * (`migrate`, `restore`, y el PHP que arranca `restore.sh`), cuya salida de error ya
 * es el log del contenedor o la captura que hace quien los llama (`2>&1` de
 * update.sh y de restore.sh). `docker compose exec` NO pasa por el entrypoint y no
 * la hereda: es el unico caso que se desvia. Asi que:
 *
 * - de consola (`cli`) y SIN `KQ_CONTAINER_MAIN` -> al log del contenedor,
 *   `/proc/1/fd/2`, la salida de error de su proceso principal, con el mismo
 *   mecanismo que {@see ContainerLogChannel};
 * - en cualquier otro caso -> la salida de error propia, como siempre. Fuera de un
 *   contenedor (el anfitrion de desarrollo, la CI) `/proc/1/fd/2` no se puede
 *   escribir y tambien se queda en la salida de error. La suite de pruebas declara
 *   la variable en `phpunit.xml`, para no llenar el log del contenedor de desarrollo.
 *
 * La variable no esta en el `.env` ni en el `environment` de ningun servicio: si
 * estuviera, `exec` la heredaria y la regla dejaria de funcionar.
 *
 * Lo que el operador SI necesita ver no pasa por aqui: la salida propia de cada
 * orden (`$this->error()`, `info()`, `line()`) va a su salida estandar, y una
 * excepcion no capturada la sigue pintando Symfony Console en la terminal. Lo que
 * deja de salir es la COPIA en JSON de lo mismo que ya se le esta diciendo.
 *
 * ## Solo con el destino de serie, y nunca a costa de perder una linea
 *
 * Si la configuracion apunta el canal a otro sitio (un fichero), se respeta: solo se
 * desvia `php://stderr`. Si el log del contenedor no se puede escribir, se usa la
 * salida de error desde el principio; y si una escritura falla despues (el `cat`
 * que la lleva ha muerto), no se lanza nada: el manejador pasa a la salida de error
 * y escribe ahi esa linea y las siguientes. Un fallo del log no puede tirar una
 * orden de consola.
 */
final class ConsoleAwareStreamHandler extends StreamHandler
{
    /** El destino de serie del canal. */
    public const string STDERR = 'php://stderr';

    /** El log del contenedor: la salida de error de su proceso principal. */
    public const string CONTAINER_LOG = '/proc/1/fd/2';

    /** La variable que exporta el entrypoint del contenedor antes de su `exec`. */
    public const string CONTAINER_MAIN = 'KQ_CONTAINER_MAIN';

    /** La salida de error, desde que una escritura en el log del contenedor fallo. */
    private ?StreamHandler $fallback = null;

    /**
     * @param  resource|string  $stream
     * @param  resource|null  $containerStream  Solo para pruebas: el destino del
     *                                          contenedor ya abierto, en vez de `$containerLog`.
     */
    public function __construct(
        mixed $stream,
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
        ?int $filePermission = null,
        bool $useLocking = false,
        string $fileOpenMode = 'a',
        string $containerLog = self::CONTAINER_LOG,
        mixed $containerStream = null,
    ) {
        $redirect = self::redirects($stream, PHP_SAPI, getenv(self::CONTAINER_MAIN) !== false);

        parent::__construct(
            match (true) {
                ! $redirect => $stream,
                \is_resource($containerStream) => $containerStream,
                default => ContainerLogChannel::open(ContainerLogChannel::stream($containerLog)),
            },
            $level,
            $bubble,
            $filePermission,
            $useLocking,
            $fileOpenMode,
        );
    }

    /**
     * Si el registro de este proceso tiene que ir al log del contenedor en vez de a
     * su salida de error.
     */
    public static function redirects(mixed $stream, string $sapi, bool $containerMain): bool
    {
        return $stream === self::STDERR && $sapi === 'cli' && ! $containerMain;
    }

    protected function write(LogRecord $record): void
    {
        if ($this->fallback instanceof StreamHandler) {
            $this->fallback->handle($record);

            return;
        }

        $failed = false;
        set_error_handler(static function () use (&$failed): bool {
            $failed = true;

            return true;
        });

        try {
            parent::write($record);
        } catch (Throwable) {
            $failed = true;
        } finally {
            restore_error_handler();
        }

        if (! $failed) {
            return;
        }

        try {
            parent::close();
        } catch (Throwable) {
            // Ya estaba roto: lo que importa es la linea, no cerrar bien.
        }

        $this->fallback = new StreamHandler(self::STDERR, $this->getLevel(), $this->getBubble());
        $this->fallback->setFormatter($this->getFormatter());
        $this->fallback->handle($record);
    }

    public function close(): void
    {
        $this->fallback?->close();
        parent::close();
    }
}

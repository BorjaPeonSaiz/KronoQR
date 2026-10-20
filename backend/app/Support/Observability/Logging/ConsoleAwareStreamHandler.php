<?php

declare(strict_types=1);

namespace App\Support\Observability\Logging;

use Monolog\Handler\StreamHandler;
use Monolog\Level;

/**
 * El manejador del canal `stderr`, el primario del producto (doc 02 §8.2.1): la
 * salida de error del proceso, SALVO en una orden de consola lanzada a mano
 * dentro de un contenedor ya en marcha (V1-PL-01, V21-4-PL-2, I11; decision del
 * propietario del 20-10-2026).
 *
 * ## El hueco que cierra
 *
 * `docker compose exec app php artisan …` es la forma en que el IT del hotel
 * habla con el producto, y la salida de error de ese proceso es SU TERMINAL. Todo
 * lo que el producto registraba —un INFO de rutina, un WARNING del cortacircuitos,
 * el ERROR con la excepcion entera— le salia como lineas JSON en mitad de la
 * respuesta de la orden. Para quien no conoce el sistema, un `RedisException` en
 * una orden que ha ido bien es una pista falsa, y es lo que mandaria al
 * fabricante. Y esas lineas NO llegaban al log del contenedor
 * (`docker compose logs app`), que es donde se buscan despues.
 *
 * ## La regla
 *
 * Si el proceso es de consola (`cli`) y NO es el PID 1 del contenedor, el registro
 * va al log del contenedor —`/proc/1/fd/2`, la salida de error de su proceso
 * principal— con el mismo mecanismo que {@see ContainerLogChannel}. La terminal
 * queda limpia y el registro se conserva en `docker compose logs`. En cualquier
 * otro caso, la salida de error propia, como siempre:
 *
 * - PHP-FPM (`fpm-fcgi`): sus workers no son de consola.
 * - `horizon`, `scheduler`, `reverb`: su orden ES el PID 1 (el *entrypoint* hace
 *   `exec`), y su salida de error ya es el log del contenedor.
 * - `docker compose run --rm …` (`migrate`, `restore`): el PHP del contenedor de un
 *   solo uso es su PID 1 y su log es la propia salida de la orden.
 *
 * Lo que el operador SI necesita ver no pasa por aqui: la salida propia de cada
 * orden (`$this->error()`, `info()`, `line()`) va a su salida estandar, y una
 * excepcion no capturada la sigue pintando Symfony Console en la terminal. Lo que
 * deja de salir es la COPIA en JSON de lo mismo que ya se le esta diciendo.
 *
 * ## Solo con el destino de serie
 *
 * Si la configuracion apunta el canal a otro sitio (un fichero, en una prueba o
 * en una instalacion que lo decida), se respeta: la redireccion solo aplica a
 * `php://stderr`. Y si `/proc/1/fd/2` no se puede escribir —el anfitrion de
 * desarrollo, un PID 1 de otro usuario—, se vuelve a la salida de error propia.
 */
final class ConsoleAwareStreamHandler extends StreamHandler
{
    /** El destino de serie del canal. */
    public const string STDERR = 'php://stderr';

    /** El log del contenedor: la salida de error de su proceso principal. */
    public const string CONTAINER_LOG = '/proc/1/fd/2';

    /**
     * @param  resource|string  $stream
     */
    public function __construct(
        mixed $stream,
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
        ?int $filePermission = null,
        bool $useLocking = false,
        string $fileOpenMode = 'a',
        string $containerLog = self::CONTAINER_LOG,
    ) {
        parent::__construct(
            self::redirects($stream, PHP_SAPI, getmypid())
                ? ContainerLogChannel::open(ContainerLogChannel::stream($containerLog))
                : $stream,
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
    public static function redirects(mixed $stream, string $sapi, int|false $pid): bool
    {
        return $stream === self::STDERR && $sapi === 'cli' && $pid !== 1;
    }
}

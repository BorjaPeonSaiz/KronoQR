<?php

declare(strict_types=1);

namespace App\Support\Observability\Logging;

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger as Monolog;
use Monolog\Processor\PsrLogMessageProcessor;

/**
 * El canal que escribe en el log **del contenedor** desde un proceso cuya
 * salida no llega a el (RNF-D-02, regla dura 21).
 *
 * ## El hueco que cierra
 *
 * Las tareas programadas con `runInBackground()` acaban en
 * `schedule:finish`, y Laravel lanza ese proceso con su salida y su error
 * redirigidos a `/dev/null` (`CommandBuilder::buildBackgroundCommand()`, sin
 * forma de cambiarlo). Todo lo que ese proceso escribiera por el canal
 * `stderr` se perdia: solo llegaba a Loki, y solo si la instalacion lo tiene.
 * Por eso escriben por aqui, a traves del canal `scheduler`, tanto la salida
 * de las tareas (`ScheduledTaskOutput`) como el apunte
 * `scheduler.command_failed` (`LogScheduledCommandFailure`).
 *
 * El log del contenedor es la salida de error de su proceso principal: en
 * `scheduler`, `php artisan schedule:work`, PID 1 porque el *entrypoint* hace
 * `exec`. `/proc/1/fd/2` es ese mismo descriptor, y cualquier proceso del
 * contenedor con el mismo usuario puede abrirlo y escribir: lo que se escribe
 * ahi es lo que ensena `docker compose logs scheduler`.
 *
 * ## Por que con `cat` y no con `fopen()`
 *
 * PHP no sabe abrir `/proc/1/fd/2`: su envoltorio de ficheros resuelve el
 * enlace antes de abrir, llega a `/proc/1/fd/pipe:[NNNN]`, que no existe, y
 * falla con «No such file or directory» (comprobado en el contenedor
 * `scheduler`; `echo … > /proc/1/fd/2` desde el shell si funciona, porque ahi
 * lo abre el nucleo). Asi que, para una ruta bajo `/proc/`, el canal escribe
 * en la entrada de un `cat` que la abre por el: un proceso de un solo uso por
 * cada `schedule:finish` que registra algo. La ruta es la de la configuracion,
 * versionada, y aun asi va escapada.
 *
 * ## Por que la ruta se resuelve aqui y no en `config/logging.php`
 *
 * Porque la configuracion se puede cachear en un proceso donde `/proc/1/fd/2`
 * no se puede abrir (un `docker build`, otro contenedor) y el resultado
 * viajaria congelado. Aqui se decide al construir el canal, en el proceso que
 * va a escribir. Si no se puede abrir —el anfitrion de desarrollo, un
 * contenedor cuyo PID 1 es de otro usuario—, se escribe por la salida de error
 * propia, que es exactamente lo que hacia el canal `stderr`.
 *
 * ## Lo que no hace: sanear
 *
 * El formato es el de `stderr` (JSON e interpolacion PSR-3). El saneado y la
 * correlacion los ponen los *taps* de `config/logging.php`, como en cualquier
 * otro canal: `EveryLogChannelRedactsPersonalDataTest` falla si este llega sin
 * ellos.
 */
final readonly class ContainerLogChannel
{
    /** Lo que se usa si el log del contenedor no se puede abrir. */
    public const string FALLBACK = 'php://stderr';

    /**
     * @param  array<string, mixed>  $config
     */
    public function __invoke(array $config): Monolog
    {
        $handler = new StreamHandler(
            self::open(self::stream($config['stream'] ?? null)),
            $this->level($config['level'] ?? null),
        );
        $handler->setFormatter(new JsonFormatter);

        return new Monolog('scheduler', [$handler], [new PsrLogMessageProcessor]);
    }

    /**
     * Lo que recibe el `StreamHandler`: la ruta tal cual, o la entrada de un
     * `cat` que la abre si es un descriptor de `/proc` (ver arriba).
     *
     * @return resource|string
     */
    public static function open(string $path): mixed
    {
        if (! str_starts_with($path, '/proc/')) {
            return $path;
        }

        $pipe = popen('exec cat >> '.escapeshellarg($path), 'w');

        return $pipe === false ? self::FALLBACK : $pipe;
    }

    /**
     * La ruta configurada si se puede escribir en ella; si no, la salida de
     * error del propio proceso.
     */
    public static function stream(mixed $configured): string
    {
        return \is_string($configured) && $configured !== '' && is_writable($configured)
            ? $configured
            : self::FALLBACK;
    }

    /**
     * El nivel de `LOG_LEVEL`, sin distinguir mayusculas; `debug` si no es
     * ninguno, como el resto de canales.
     */
    private function level(mixed $configured): Level
    {
        foreach (Level::cases() as $level) {
            if (\is_string($configured) && strcasecmp($level->getName(), $configured) === 0) {
                return $level;
            }
        }

        return Level::Debug;
    }
}

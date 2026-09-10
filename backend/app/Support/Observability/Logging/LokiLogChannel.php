<?php

declare(strict_types=1);

namespace App\Support\Observability\Logging;

use Monolog\Handler\NullHandler;
use Monolog\Logger as Monolog;

/**
 * La factoria del canal `loki` (`config/logging.php`, driver `custom`).
 *
 * ## Sin `LOKI_URL` el canal ni siquiera entra en la pila
 *
 * `config/logging.php` solo lo anade a `LOG_STACK` cuando la variable tiene
 * valor, asi que en la instalacion que no usa Loki esta factoria no llega a
 * ejecutarse. La rama del `NullHandler` es la red de seguridad para quien apunte
 * `LOG_CHANNEL=loki` a mano con la variable vacia: un canal que no envia a
 * ninguna parte es preferible a un arranque roto por una linea del `.env`.
 *
 * ## El handler es un singleton del contenedor
 *
 * Porque quien lo vacia no es el canal sino el `terminating()` de la aplicacion,
 * el fin de cada trabajo de la cola y el fin de cada comando
 * ({@see LoggingServiceProvider}). Con una instancia por canal, el buffer que se
 * llena y el que se vacia serian dos objetos distintos.
 *
 * ## Los processors los pone el `tap`, no esta factoria
 *
 * `config/logging.php` declara `tap => [AddCorrelation::class]` tambien en este
 * canal, y ese tap empuja los dos processors de correlacion. Aqui se empujaba
 * ademas un {@see CorrelationProcessor} propio: el resultado eran **dos**
 * instancias del mismo processor en la misma cadena, haciendo dos veces el mismo
 * trabajo sobre cada linea que iba a Loki. Se quita el de aqui — el del tap vale
 * para cualquier canal, tambien para el que alguien anada manana.
 */
final readonly class LokiLogChannel
{
    public function __construct(private LokiHandler $handler) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public function __invoke(array $config): Monolog
    {
        $url = $config['url'] ?? null;
        $handler = is_string($url) && trim($url) !== '' ? $this->handler : new NullHandler;

        return new Monolog('loki', [$handler]);
    }
}

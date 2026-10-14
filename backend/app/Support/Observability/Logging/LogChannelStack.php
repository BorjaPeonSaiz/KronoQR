<?php

declare(strict_types=1);

namespace App\Support\Observability\Logging;

/**
 * Que canales entran en la pila de log del producto (doc 02 §8.2.1, decision 8
 * de la ficha 3.1).
 *
 * ## La regla, en una frase
 *
 * **Quien pone `LOKI_URL` ya ha dicho que quiere Loki.** De ahi las dos mitades:
 *
 * - `LOKI_URL` con valor -> `loki` esta en la pila, **diga lo que diga
 *   `LOG_STACK`**. Es lo que arregla el estado del que venia el repositorio: una
 *   variable documentada que ningun codigo leia.
 * - `LOKI_URL` vacia -> `loki` **no** entra, aunque `LOG_STACK` lo nombre. Un
 *   `.env` copiado de otra instalacion no puede dejar la aplicacion intentando un
 *   `POST` por cada linea de log contra un destino que no existe.
 *
 * `stderr` es el canal primario y no es negociable: Docker lo conserva, el
 * paquete de diagnostico lo lee y sigue existiendo cuando Loki no esta. Es el
 * valor por omision de `LOG_STACK` y lo que queda si la variable llega vacia.
 *
 * ## Por que es una clase y no cuatro lineas en `config/logging.php`
 *
 * Porque estaba ahi, y ahi no se puede probar: la suite `Unit` no arranca el
 * framework y `config/logging.php` llama a `storage_path()`. Una semantica que
 * sorprende —la segunda mitad de la regla sorprende— y que ninguna prueba fija
 * es una semantica que cambia sola en el proximo `.env` que alguien edite.
 * Aqui es una funcion pura de dos cadenas, y `LogStackCompositionTest` la fija.
 *
 * ## `loki` va SIEMPRE el ultimo, y de eso depende una garantia de RL-08
 *
 * `LogManager::createStackDriver()` concatena los processors de cada canal en
 * su orden. Cada canal con el tap `AddCorrelation` aporta
 * `ContextLogProcessor` -> `CorrelationOnlyExtra` -> `CorrelationProcessor`. Si
 * un canal SIN tap (`syslog`, `papertrail`) quedara despues de `loki`, su
 * `ContextLogProcessor` correria el ultimo, volveria a volcar `Context::all()`
 * entero en `extra` sin nadie que lo podara, y ese registro llegaria al
 * `LokiHandler` con lo que hubiera en `Context`. Que `loki` cierre la pila no es
 * cosmetico: es lo que hace que la poda sea la ultima palabra.
 * `LogStackCompositionTest` fija el orden; este parrafo dice por que importa.
 */
final class LogChannelStack
{
    /** El canal que siempre esta, con o sin Loki. */
    public const string PRIMARY = 'stderr';

    /** La copia consultable, y solo si hay a donde empujarla. */
    public const string LOKI = 'loki';

    /**
     * El canal que hace de `stderr` en las tareas programadas en segundo plano.
     * Ver {@see ContainerLogChannel}.
     */
    public const string SCHEDULER_PRIMARY = 'scheduler-container';

    /**
     * @param  string  $logStack  El valor de `LOG_STACK`, canales separados por coma.
     * @param  string  $lokiUrl  El valor de `LOKI_URL`.
     * @return list<string>
     */
    public static function compose(string $logStack, string $lokiUrl): array
    {
        $channels = array_values(array_filter(
            array_map(trim(...), explode(',', $logStack)),
            static fn (string $channel): bool => $channel !== '',
        ));

        $hasDestination = trim($lokiUrl) !== '';

        // La segunda mitad de la regla: sin destino, el canal no entra aunque
        // `LOG_STACK` lo nombre.
        if (! $hasDestination) {
            $channels = array_values(array_filter(
                $channels,
                static fn (string $channel): bool => $channel !== self::LOKI,
            ));
        }

        // Una pila vacia dejaria la instalacion sin log tecnico y sin que nada
        // fallara. Pasa con `LOG_STACK=` y con `LOG_STACK=loki` sin destino.
        if ($channels === []) {
            $channels = [self::PRIMARY];
        }

        if ($hasDestination && ! \in_array(self::LOKI, $channels, true)) {
            $channels[] = self::LOKI;
        }

        return $channels;
    }

    /**
     * La pila del canal `scheduler` (V4-BE-1): la misma que la del producto,
     * con `stderr` cambiado por el log del contenedor.
     *
     * `schedule:finish` corre con su salida de error tirada a `/dev/null`, asi
     * que alli `stderr` no llega a ninguna parte. Todo lo demas se conserva —el
     * orden incluido, y con el `loki` el ultimo—: quien configuro Loki o un
     * canal extra en `LOG_STACK` lo recibe tambien de las tareas programadas.
     *
     * @param  list<string>  $stack  El resultado de {@see self::compose()}.
     * @return list<string>
     */
    public static function forScheduler(array $stack): array
    {
        return array_map(
            static fn (string $channel): string => $channel === self::PRIMARY ? self::SCHEDULER_PRIMARY : $channel,
            $stack,
        );
    }
}

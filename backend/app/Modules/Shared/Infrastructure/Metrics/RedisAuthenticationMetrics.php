<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Metrics;

use App\Modules\Shared\Application\Port\AuthenticationMetrics;
use App\Modules\Shared\Domain\ValueObject\AuthChannel;
use App\Modules\Shared\Domain\ValueObject\AuthOutcome;
use Illuminate\Contracts\Redis\Factory as Redis;
use Throwable;

/**
 * `kronoqr_auth_attempts_total{channel,outcome}` sobre Redis (doc 02 §8.2,
 * OWASP A09).
 *
 * **Redis y no el colector *textfile***, por lo mismo que
 * `Attendance\Infrastructure\Metrics\RedisScanMetrics` y
 * `Workforce\Infrastructure\Metrics\RedisPinMetrics` —nombrados en prosa y no
 * con `@see`, porque una referencia resoluble seria una dependencia entre
 * modulos que la frontera del §1.6 no concede—: quien incrementa este contador
 * es una peticion HTTP de un proceso que termina, y un contador en memoria de un
 * proceso que termina no lo lee nadie. `HINCRBY` es atomico y cuesta
 * microsegundos. El endpoint `/metrics` que lo publica es de la tarea 3.1; hasta
 * entonces el contador se acumula y las pruebas lo leen.
 *
 * **El nombre lleva el prefijo `kronoqr_` y las demas series de la instalacion
 * no.** Es deliberado y viene de fuera: las reglas de alerta de A09 estan
 * escritas contra `kronoqr_auth_attempts_total` y renombrarlo aqui las dejaria
 * sin datos en silencio, que es la peor forma de perder una alerta de seguridad.
 * La invariante que si se respeta es la que la tarea 3.1 necesita: **el sufijo de
 * la clave de Redis es, literalmente, el nombre de la metrica expuesta**, de modo
 * que el publicador las reune con un solo `SCAN` sin traducir nada.
 *
 * **Ni una etiqueta que identifique a alguien** (regla dura 21). Nueve series en
 * total: tres canales por tres desenlaces.
 */
final readonly class RedisAuthenticationMetrics implements AuthenticationMetrics
{
    /** Mismo prefijo que el resto de metricas para que la tarea 3.1 las reuna con un solo `SCAN`. */
    public const string KEY_PREFIX = 'kronoqr:metrics:';

    public const string AUTH_ATTEMPTS_TOTAL = self::KEY_PREFIX.'kronoqr_auth_attempts_total';

    public function __construct(private Redis $redis) {}

    public function attempt(AuthChannel $channel, AuthOutcome $outcome): void
    {
        try {
            $this->redis->connection()->command('HINCRBY', [
                self::AUTH_ATTEMPTS_TOTAL,
                'channel='.$channel->value.',outcome='.$outcome->value,
                1,
            ]);
        } catch (Throwable) {
            // Silencio deliberado y acotado a este metodo: cuando se llega aqui
            // el desenlace del intento ya esta decidido, y una excepcion de la
            // capa de metricas convertiria un acceso correcto en un `500` —o
            // dejaria a alguien sin fichar, que es la regla dura 19—.
        }
    }
}

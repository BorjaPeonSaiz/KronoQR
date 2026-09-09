<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Metrics;

use App\Modules\Product\Application\Port\ErrorMetrics;
use App\Modules\Shared\Domain\ValueObject\ErrorLevel;
use App\Modules\Shared\Domain\ValueObject\ErrorSource;
use Illuminate\Contracts\Redis\Factory as Redis;
use Throwable;

/**
 * `application_errors_total{source,level}` sobre Redis (doc 02 §8.2, decision 11
 * de la ficha 5.12).
 *
 * **Por que Redis y no el colector *textfile*.** El hecho medido ocurre en el
 * camino de una peticion que ya ha fallado, y puede ocurrir muchas veces por
 * segundo: un fichero reescrito en cada error seria una escritura de disco por
 * error y una carrera entre procesos PHP justo cuando algo va mal. `HINCRBY` es
 * atomico, cuesta microsegundos y ya hay Redis en el stack. Es el mismo patron
 * que `scans_total`.
 *
 * **Un hash con la etiqueta compuesta en el campo** (`api|critical`), como
 * `scans_total`, y no una clave por combinacion: son catorce campos como maximo
 * —siete origenes por dos niveles—, asi que caben de sobra en un hash y el
 * paquete de diagnostico los saca con un solo `HGETALL`.
 *
 * **Ninguna etiqueta identifica a nadie** (regla dura 21). Ni `employee_uuid`,
 * ni `device`, ni `module`: para alertar basta «hay criticos nuevos», y el
 * detalle esta a un clic en la tabla, que si tiene control de acceso y 90 dias
 * de retencion.
 *
 * **Medir no puede convertir un error en dos.** Si Redis no responde se sigue
 * adelante en silencio: se llega aqui desde el camino de un fallo que ya ocurrio
 * -o desde el latido de un quiosco- y perder un contador es infinitamente mas
 * barato que romper una peticion o dejar a alguien sin fichar (regla dura 19).
 * El endpoint `/metrics` que publica la serie es de la tarea 3.1; hasta entonces
 * los contadores se acumulan y los leen el paquete de diagnostico y las pruebas.
 */
final readonly class RedisErrorMetrics implements ErrorMetrics
{
    /** El prefijo comun del producto, para que la 3.1 los encuentre con un solo `SCAN`. */
    public const string KEY_PREFIX = 'kronoqr:metrics:';

    /** Ocurrencias: sube con cada aparicion, sea nueva o la numero mil. */
    public const string ERRORS_TOTAL = self::KEY_PREFIX.'application_errors_total';

    /**
     * Grupos nuevos o reabiertos: la serie de la alerta `ErroresCriticosNuevos`
     * (decision 14).
     *
     * Separada de la anterior porque una camara de quiosco rota emite el mismo
     * error cada pocos segundos durante dias: con la serie de ocurrencias, la
     * alerta no se apagaria nunca por un problema ya conocido, y una alerta que
     * no se apaga deja de leerse.
     */
    public const string GROUPS_OPENED_TOTAL = self::KEY_PREFIX.'application_error_groups_opened_total';

    public function __construct(private Redis $redis) {}

    public function errorRecorded(ErrorSource $source, ErrorLevel $level): void
    {
        $this->increment(self::ERRORS_TOTAL, $source, $level);
    }

    public function groupOpened(ErrorSource $source, ErrorLevel $level): void
    {
        $this->increment(self::GROUPS_OPENED_TOTAL, $source, $level);
    }

    private function increment(string $series, ErrorSource $source, ErrorLevel $level): void
    {
        try {
            $this->redis->connection()->command('HINCRBY', [
                $series,
                'source='.$source->value.',level='.$level->value,
                1,
            ]);
        } catch (Throwable) {
            // Silencio deliberado y acotado a este metodo. Ver el docblock: la
            // alternativa es que el contador tumbe la peticion que ya venia
            // fallando, o el latido de una tablet.
        }
    }
}

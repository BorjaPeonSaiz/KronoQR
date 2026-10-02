<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Workforce\Domain\Exception\ConcurrentEmployeeWrite;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;

/**
 * La transaccion de un alta, una modificacion o una importacion de la
 * plantilla, **reintentada una vez si PostgreSQL la elige para romper un ciclo**
 * (ADR-046 §1.3, caso conocido; revision del bloque 17).
 *
 * ## El ciclo que el orden de candados no evita
 *
 * El alta inserta la ficha **antes** de la cadena de `audit_log` y despues la
 * espera. Una modificacion o una importacion que ya tiene la cadena y escribe
 * **el mismo correo o el mismo documento** espera a que el alta confirme para
 * comprobar el indice unico. Cada una espera a la otra: `40P01`. No es un fallo
 * del orden —el indice unico es una espera que ningun orden de candados
 * gobierna— y no se puede evitar sin pasar el alta a cadena primero, que
 * reabriria los ciclos de §1.3 con el centro y los fichajes.
 *
 * ## Por que reintentar y no solo traducir
 *
 * La transaccion elegida como victima se deshace entera. Repetirla una vez ya
 * encuentra confirmada la otra, y entonces el indice unico responde lo que de
 * verdad pasa: el `409` de dato duplicado, con su mensaje. Solo si el reintento
 * vuelve a cruzarse —otra escritura simultanea mas— sale
 * {@see ConcurrentEmployeeWrite}, que tambien es `409`: nunca un `500`.
 *
 * ## Solo en la transaccion de fuera
 *
 * Un reintento solo tiene sentido sobre la transaccion completa: dentro de otra
 * —la modificacion por cada fila de una importacion— el `40P01` sube intacto
 * para que lo reintente la de fuera, que es la que se ha deshecho. Laravel no
 * reintenta una transaccion anidada, y por eso aqui tampoco se traduce.
 *
 * El trabajo caro (el bcrypt del PIN) va antes de llamar aqui: el reintento
 * repite escrituras, no calculos.
 */
final readonly class EmployeeWriteRetry
{
    /** Uno de reintento: un tercer cruce seguido no es una carrera, es otra cosa. */
    private const int ATTEMPTS = 2;

    /** `deadlock_detected` y `serialization_failure`. */
    private const array CONCURRENCY_SQLSTATES = ['40P01', '40001'];

    /**
     * @template TResult
     *
     * Lanza {@see ConcurrentEmployeeWrite} si se cruza tambien en el reintento,
     * y deja subir intacto cualquier otra cosa que lance `$work`. Sin `@throws`
     * a proposito: con el, el analisis daria por hecho que `$work` no lanza
     * nada mas.
     *
     * @param  callable(): TResult  $work
     * @return TResult
     */
    public static function run(ConnectionInterface $connection, callable $work): mixed
    {
        if ($connection->transactionLevel() > 0) {
            return $connection->transaction($work(...));
        }

        try {
            return $connection->transaction($work(...), self::ATTEMPTS);
        } catch (QueryException $exception) {
            if (\in_array((string) $exception->getCode(), self::CONCURRENCY_SQLSTATES, true)) {
                throw ConcurrentEmployeeWrite::make();
            }

            throw $exception;
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Workforce\Domain\Exception\ConcurrentEmployeeWrite;
use Illuminate\Database\ConnectionInterface;
use PDOException;
use Psr\Log\LoggerInterface;
use Throwable;

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
 * verdad pasa: el `409` de dato duplicado, con su mensaje. Si el reintento
 * vuelve a cruzarse —otra escritura simultanea mas— sale
 * {@see ConcurrentEmployeeWrite}, que tambien es `409`: nunca un `500`.
 *
 * ## Como se reconoce el cruce
 *
 * **No por la clase de la excepcion.** El cruce ocurre siempre en una
 * transaccion anidada —la escritura del correo va dentro de `withChainLock()`,
 * la insercion del alta dentro de la del repositorio—, y Laravel convierte ahi
 * el error en `Illuminate\Database\DeadlockException`: un `PDOException` que no
 * es `QueryException` y cuyo codigo es `0`. Lo que dice que fue un `40P01` o un
 * `40001` es el SQLSTATE de la excepcion original, que va en `getPrevious()`.
 * Por eso se recorre la cadena de causas (revision del bloque 17, segunda
 * pasada: la primera version solo atrapaba `QueryException` y el segundo cruce
 * salia como `500`).
 *
 * ## Solo en la transaccion de fuera
 *
 * Un reintento solo tiene sentido sobre la transaccion completa: dentro de otra
 * —la modificacion por cada fila de una importacion— el error sube intacto para
 * que lo reintente la de fuera, que es la que se ha deshecho.
 *
 * ## Cada reintento deja rastro
 *
 * Un `warning` en el log tecnico por cada cruce, con el caso de uso, el intento
 * y el SQLSTATE, y nada mas: ni correo, ni documento, ni nombre (regla dura 21).
 * Absorber un `40P01` en silencio esconderia una regresion del orden de
 * candados. Sin metrica: un contador nuevo exige su entrada en el catalogo, su
 * exportador y su panel, y el log ya se puede contar.
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

    public function __construct(
        private ConnectionInterface $connection,
        private LoggerInterface $logger,
    ) {}

    /**
     * Lanza {@see ConcurrentEmployeeWrite} si se cruza tambien en el reintento,
     * y deja subir intacto cualquier otra cosa que lance `$work`. Sin `@throws`
     * a proposito: con el, el analisis daria por hecho que `$work` no lanza
     * nada mas.
     *
     * @template TResult
     *
     * @param  string  $useCase  Etiqueta del caso de uso para el log: `employee.register`, `employee.update`, `employee.import`.
     * @param  callable(): TResult  $work
     * @return TResult
     */
    public function run(string $useCase, callable $work): mixed
    {
        if ($this->connection->transactionLevel() > 0) {
            return $this->connection->transaction($work(...));
        }

        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->connection->transaction($work(...));
            } catch (PDOException $exception) {
                $sqlState = self::concurrencySqlState($exception);

                if ($sqlState === null) {
                    throw $exception;
                }

                $this->logger->warning('workforce.employee_write_concurrency', [
                    'use_case' => $useCase,
                    'attempt' => $attempt,
                    'sqlstate' => $sqlState,
                    'retried' => $attempt < self::ATTEMPTS,
                ]);

                if ($attempt >= self::ATTEMPTS) {
                    throw ConcurrentEmployeeWrite::make();
                }
            }
        }
    }

    /**
     * El SQLSTATE de concurrencia de la excepcion o de alguna de sus causas, o
     * `null` si no lo es. `DeadlockException` lleva codigo `0`: el SQLSTATE
     * esta en la `QueryException` original, que va como causa.
     */
    public static function concurrencySqlState(Throwable $exception): ?string
    {
        for ($cause = $exception; $cause instanceof Throwable; $cause = $cause->getPrevious()) {
            $code = (string) $cause->getCode();

            if ($cause instanceof PDOException && \in_array($code, self::CONCURRENCY_SQLSTATES, true)) {
                return $code;
            }
        }

        return null;
    }
}

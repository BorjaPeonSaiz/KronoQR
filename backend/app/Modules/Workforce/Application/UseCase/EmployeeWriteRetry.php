<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Shared\Application\Port\DatabaseFailureTranslator;
use App\Modules\Workforce\Domain\Exception\ConcurrentEmployeeWrite;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * La transaccion de un alta, una modificacion o una importacion de la
 * plantilla, **reintentada una vez si PostgreSQL la elige para romper un ciclo**
 * (ADR-046 §1.3, caso conocido).
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
 * **No por la clase de la excepcion, y no aqui.** El cruce ocurre siempre en
 * una transaccion anidada —la escritura del correo va dentro de
 * `withChainLock()`, la insercion del alta dentro de la del repositorio—, y el
 * framework lo envuelve en una excepcion con codigo `0` cuyo SQLSTATE va en la
 * causa. Reconocer un abrazo (`40P01`) o un fallo de serializacion (`40001`)
 * en esa cadena es cosa del adaptador de {@see DatabaseFailureTranslator}: este
 * caso de uso solo pregunta si fue concurrencia.
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

    public function __construct(
        private ConnectionInterface $connection,
        private LoggerInterface $logger,
        private DatabaseFailureTranslator $databaseFailures,
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
            } catch (Throwable $exception) {
                $database = $this->databaseFailures->translate($exception);

                if ($database === null || ! $database->kind->isConcurrency()) {
                    throw $exception;
                }

                $this->logger->warning('workforce.employee_write_concurrency', [
                    'use_case' => $useCase,
                    'attempt' => $attempt,
                    'sqlstate' => $database->sqlState,
                    'retried' => $attempt < self::ATTEMPTS,
                ]);

                if ($attempt >= self::ATTEMPTS) {
                    throw ConcurrentEmployeeWrite::make();
                }
            }
        }
    }
}

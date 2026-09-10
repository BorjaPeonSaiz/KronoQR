<?php

declare(strict_types=1);

namespace App\Support\Observability\Tracing;

use App\Modules\Shared\Application\Support\SpanScope;
use Illuminate\Database\Events\QueryExecuted;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SDK\Common\Time\ClockFactory;
use Throwable;

/**
 * Un span `CLIENT` por consulta SQL: el ultimo tramo de la promesa del §8.1,
 * *«desde el `fetch` del navegador del quiosco hasta la consulta SQL»*
 * (decision 6 de la ficha 3.1).
 *
 * ## Los valores enlazados NO entran, y no es una precaucion generica
 *
 * `db.query.text` lleva la consulta **con sus marcadores `?`**, jamas
 * `$event->bindings`. Un valor enlazado del producto es un nombre, un codigo de
 * empleado o el hash de un PIN: exactamente lo que la regla dura 21 prohibe que
 * salga en un log, y una traza acaba en el mismo sitio que un log —o mas lejos,
 * porque Tempo no tiene control de acceso por dato—. Es tambien el fallo que
 * costo una revision en `ServerErrorReporter`: alli Laravel interpolaba los
 * valores dentro del mensaje de la excepcion y llegaban nombres a
 * `error_events`.
 *
 * ## La marca de inicio es RETROACTIVA
 *
 * `QueryExecuted` llega cuando la consulta **ya ha terminado**, con su duracion
 * en milisegundos. Abrir el span en ese momento lo pintaria con duracion cero al
 * final del tramo; abrirlo en `ahora − duracion` lo pinta donde ocurrio. De ahi
 * el parametro `startEpochNanos` de {@see SpanScope::start()}.
 *
 * ## Solo se registra cuando hay un destino
 *
 * `TracingServiceProvider` no engancha este oyente si
 * `OTEL_EXPORTER_OTLP_ENDPOINT` esta vacio. Con proveedor inerte el span no
 * costaria casi nada, pero «casi nada» multiplicado por las consultas de una
 * jornada de fichaje es un gasto que la instalacion sin trazas no tiene por que
 * pagar.
 */
final class DatabaseSpans
{
    /**
     * Familias de sentencia, para que la etiqueta tenga cardinalidad de mano y no
     * de catalogo.
     *
     * @var list<string>
     */
    private const array OPERATIONS = ['select', 'insert', 'update', 'delete', 'begin', 'commit', 'rollback'];

    /** Cualquier otra cosa: `SET`, `SHOW`, un `DO $$…$$` de migracion. */
    private const string OTHER = 'other';

    /**
     * Techo del texto de la consulta. Una consulta de mil lineas no se lee en un
     * visor de trazas y si engorda cada envio al colector.
     */
    private const int MAX_QUERY_LENGTH = 2000;

    public function handle(QueryExecuted $event): void
    {
        try {
            $operation = $this->operationOf($event->sql);

            // `time` viene en milisegundos con decimales; el SDK cuenta en
            // nanosegundos desde el epoch.
            $startedAt = ClockFactory::getDefault()->now() - (int) round($event->time * 1_000_000);

            SpanScope::start(
                'kronoqr.database',
                'postgresql '.$operation,
                SpanKind::KIND_CLIENT,
                [
                    // Nombres del §8.1 de la ficha. La semantica 1.38 los llama
                    // `db.system.name` y `db.operation.name`; el cambio de nombre
                    // afecta a los cuadros de mando de la 3.2 y se hace ahi o en
                    // ninguna parte, no a medias.
                    'db.system' => 'postgresql',
                    'db.operation' => $operation,
                    'db.query.text' => $this->queryTextOf($event->sql),
                    'db.connection' => $event->connectionName,
                ],
                $startedAt,
            )->end();
        } catch (Throwable) {
            // Medir una consulta no puede hacer fallar la consulta (regla dura 19).
        }
    }

    private function operationOf(string $sql): string
    {
        $first = strtolower(strtok(ltrim($sql), " \n\r\t(") ?: '');

        return in_array($first, self::OPERATIONS, true) ? $first : self::OTHER;
    }

    private function queryTextOf(string $sql): string
    {
        return mb_strlen($sql) > self::MAX_QUERY_LENGTH
            ? mb_substr($sql, 0, self::MAX_QUERY_LENGTH).'…'
            : $sql;
    }
}

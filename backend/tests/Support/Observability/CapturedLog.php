<?php

declare(strict_types=1);

namespace Tests\Support\Observability;

use App\Support\Observability\Logging\CorrelationProcessor;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\TestHandler;
use Monolog\Logger as Monolog;
use Monolog\LogRecord;
use RuntimeException;
use Tests\Support\Telemetry\RecordingTracer;

/**
 * **TODO** lo que escribe el canal de log durante una prueba, ya procesado.
 *
 * ## Por que no vale `Log::spy()`
 *
 * Porque un espia intercepta la llamada al *facade* y devuelve lo que el codigo
 * paso: mensaje y contexto. Lo que hay que comprobar aqui es lo que sale
 * DESPUES de los processors —el `trace_id` que anade
 * {@see CorrelationProcessor} y las claves que
 * copia el processor de `Context` de Laravel—, y eso solo existe dentro de
 * Monolog. Con un espia, la prueba de privacidad afirmaria sobre un registro que
 * no es el que se escribe.
 *
 * Se engancha un handler al canal por defecto, que es el que usa la aplicacion en
 * la suite, y se restauran los handlers al terminar: sin eso, el primer fichero
 * que capturara el log lo capturaria para todo el proceso.
 */
final class CapturedLog
{
    /**
     * @param  list<HandlerInterface>  $previous  Los handlers del canal antes de capturarlo.
     */
    private function __construct(
        private readonly Monolog $monolog,
        private readonly TestHandler $handler,
        private readonly array $previous,
    ) {}

    /**
     * Captura el canal por defecto mientras dure el cuerpo, y lo suelta pase lo
     * que pase.
     *
     * Es la unica forma de usar esta clase, por lo mismo que
     * {@see RecordingTracer::around()}: un `afterEach`
     * que dependiera de que el cuerpo llegue al final dejaria el handler puesto
     * en cuanto una prueba fallara.
     *
     * @param  callable(self): void  $body
     */
    public static function around(callable $body): void
    {
        $captured = self::start();

        try {
            $body($captured);
        } finally {
            $captured->release();
        }
    }

    public static function start(): self
    {
        $logger = Log::driver();

        if (! method_exists($logger, 'getLogger')) {
            throw new RuntimeException('El canal por defecto no es un logger de Monolog: no hay nada que capturar.');
        }

        $monolog = $logger->getLogger();

        if (! $monolog instanceof Monolog) {
            throw new RuntimeException('El canal por defecto no es un logger de Monolog: no hay nada que capturar.');
        }

        $handler = new TestHandler;
        $previous = $monolog->getHandlers();

        // Al principio de la pila y con burbuja: el canal real sigue escribiendo
        // lo mismo que escribiria sin la prueba.
        $monolog->pushHandler($handler);

        return new self($monolog, $handler, $previous);
    }

    public function release(): void
    {
        $this->monolog->setHandlers($this->previous);
    }

    /**
     * @return list<LogRecord>
     */
    public function records(): array
    {
        return array_values($this->handler->getRecords());
    }

    /**
     * Todo lo capturado en una sola cadena: mensaje, contexto y `extra` de cada
     * linea.
     *
     * Se busca sobre el volcado entero y no columna a columna a proposito:
     * enumerar campos dejaria fuera el que alguien anada mañana, que es justo por
     * donde se escaparia el dato.
     */
    public function dump(): string
    {
        $lines = [];

        foreach ($this->records() as $record) {
            $lines[] = json_encode([
                'message' => $record->message,
                'channel' => $record->channel,
                'level' => $record->level->getName(),
                'context' => $record->context,
                'extra' => $record->extra,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }

        return implode("\n", array_map(static fn (string|false $line): string => (string) $line, $lines));
    }

    /**
     * Los `trace_id` de cada linea, en orden, con `null` donde no lo haya.
     *
     * @return list<string|null>
     */
    public function traceIds(): array
    {
        $ids = [];

        foreach ($this->records() as $record) {
            $value = $record->extra['trace_id'] ?? $record->context['trace_id'] ?? null;

            $ids[] = is_string($value) && $value !== '' ? $value : null;
        }

        return $ids;
    }
}

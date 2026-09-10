<?php

declare(strict_types=1);

namespace App\Support\Observability\Logging;

use DateTimeInterface;
use Monolog\Formatter\FormatterInterface;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

/**
 * El log tecnico llega a Loki **desde el propio proceso**, agrupado y a prueba de
 * fallos (doc 02 §8.1 y §8.2.1, decision 8 de la ficha 3.1).
 *
 * ## Por que no hay agente de recoleccion
 *
 * Promtail llego a su fin de vida en marzo de 2026, y las dos alternativas
 * —Grafana Alloy y el *driver* de Docker para Loki— exigen el *socket* de Docker
 * o `/var/lib/docker/containers` dentro de un contenedor con privilegios. Esa es
 * exactamente la superficie que el doc 07 no quiere en el servidor de un cliente:
 * quien alcance ese socket es root en la maquina. Empujar desde el proceso cuesta
 * un `POST` por peticion y no abre nada.
 *
 * ## Un solo `POST`, y despues de responder
 *
 * Los registros se acumulan en memoria y salen **de una vez** al terminar la
 * peticion —despues de `fastcgi_finish_request`, asi que el cliente ya tiene su
 * respuesta—, al terminar cada trabajo de la cola y al terminar cada comando. Un
 * `POST` por linea multiplicaria por veinte los viajes a la red en el camino de
 * fichaje.
 *
 * ## Perder una linea es barato; retrasar un fichaje, no
 *
 * Tiempo maximo de un segundo, **sin reintentos**, y cualquier fallo se traga y se
 * cuenta (regla dura 19 y 15). Si Loki esta caido, el log sigue saliendo por
 * `stderr` —que es el canal primario y el que conserva Docker— y a Loki le faltara
 * un rato de historico. La alternativa, que es bloquear el proceso que atiende al
 * quiosco esperando a un almacen de logs, no es una alternativa.
 *
 * El buffer tiene techo por el mismo motivo: un comando que escribe cien mil
 * lineas no puede quedarse sin memoria por culpa del canal de log. A partir del
 * techo se descartan y se cuentan.
 *
 * ## Etiquetas de cardinalidad minima
 *
 * `service`, `level` y `environment`. **Nunca `trace_id` ni ningun identificador
 * como etiqueta**: en Loki cada combinacion de etiquetas es un flujo con sus
 * indices, y un `trace_id` por etiqueta crea un flujo por peticion —lo que tumba
 * el almacen y, de paso, escribe un directorio de identificadores en el indice—.
 * Todo eso va **dentro de la linea**, que es JSON y se consulta con `| json`.
 */
final class LokiHandler extends AbstractProcessingHandler
{
    /** Ruta del API de ingesta de Loki, que no es configurable: la fija Loki. */
    private const string PUSH_PATH = '/loki/api/v1/push';

    /** @var list<array{level: string, timestamp: string, line: string}> */
    private array $buffer = [];

    private int $dropped = 0;

    private int $failures = 0;

    /**
     * @param  string  $url  Raiz de Loki (`LOKI_URL`), sin la ruta de ingesta.
     * @param  string  $service  Etiqueta `service`.
     * @param  string  $environment  Etiqueta `environment`.
     * @param  float  $timeoutSeconds  Techo del envio. Un segundo: ver el docblock.
     * @param  int  $maxRecords  Techo del buffer en memoria.
     */
    public function __construct(
        private readonly LokiTransport $transport,
        private readonly string $url,
        private readonly string $service,
        private readonly string $environment,
        private readonly float $timeoutSeconds = 1.0,
        private readonly int $maxRecords = 1000,
        Level $level = Level::Debug,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);

        $this->setFormatter(new JsonFormatter);
    }

    /**
     * Envia lo acumulado. Idempotente: sin nada pendiente no toca la red.
     *
     * Lo llaman el `terminating()` de la aplicacion, el fin de cada trabajo de la
     * cola y el fin de cada comando. Ver {@see LoggingServiceProvider}.
     */
    public function flush(): void
    {
        $records = $this->buffer;
        $this->buffer = [];

        if ($records === []) {
            return;
        }

        try {
            $payload = json_encode($this->streamsOf($records), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($payload === false) {
                $this->failures++;

                return;
            }

            if (! $this->transport->push(rtrim($this->url, '/').self::PUSH_PATH, $payload, $this->timeoutSeconds)) {
                $this->failures++;
            }
        } catch (Throwable) {
            // Ver el docblock: perder una linea de log es infinitamente mas barato
            // que retrasar un fichaje.
            $this->failures++;
        }
    }

    public function close(): void
    {
        $this->flush();

        parent::close();
    }

    /** Registros pendientes de enviar. Para las pruebas y para el diagnostico. */
    public function pending(): int
    {
        return count($this->buffer);
    }

    /** Envios que Loki no acepto. No se informa como error: se cuenta. */
    public function failures(): int
    {
        return $this->failures;
    }

    /** Registros descartados por techo de buffer. */
    public function dropped(): int
    {
        return $this->dropped;
    }

    protected function write(LogRecord $record): void
    {
        if (count($this->buffer) >= $this->maxRecords) {
            $this->dropped++;

            return;
        }

        // `formatted` lo escribe el formateador de este handler, que es
        // `JsonFormatter` y siempre devuelve una cadena. La rama del mensaje
        // pelado existe por si alguien cambia el formateador por uno que
        // devuelva otra cosa: una linea sin formato sigue siendo mejor que una
        // excepcion dentro del canal de log.
        $formatted = $record->formatted;

        $this->buffer[] = [
            'level' => strtolower($record->level->getName()),
            'timestamp' => $this->nanosecondsOf($record->datetime),
            'line' => is_string($formatted) ? trim($formatted) : trim($record->message),
        ];
    }

    #[\Override]
    protected function getDefaultFormatter(): FormatterInterface
    {
        return new JsonFormatter;
    }

    /**
     * @param  list<array{level: string, timestamp: string, line: string}>  $records
     * @return array{streams: list<array{stream: array<string, string>, values: list<array{0: string, 1: string}>}>}
     */
    private function streamsOf(array $records): array
    {
        /** @var array<string, list<array{0: string, 1: string}>> $byLevel */
        $byLevel = [];

        foreach ($records as $record) {
            $byLevel[$record['level']][] = [$record['timestamp'], $record['line']];
        }

        $streams = [];

        foreach ($byLevel as $level => $values) {
            $streams[] = [
                'stream' => [
                    'service' => $this->service,
                    'level' => $level,
                    'environment' => $this->environment,
                ],
                'values' => $values,
            ];
        }

        return ['streams' => $streams];
    }

    /**
     * Nanosegundos desde el epoch **como cadena**: Loki los exige asi y un entero
     * de 19 cifras no cabe en un `float` sin perder los microsegundos, que son lo
     * que ordena dos lineas de la misma peticion.
     */
    private function nanosecondsOf(DateTimeInterface $moment): string
    {
        return $moment->format('U').str_pad($moment->format('u'), 6, '0', STR_PAD_LEFT).'000';
    }
}

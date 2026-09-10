<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\RestrictToMetricsNetwork;
use App\Modules\Shared\Infrastructure\Metrics\Exposition\RedisMetricReader;
use App\Support\Observability\Metrics\QueueDepthCollector;
use Illuminate\Http\Response;
use Prometheus\MetricFamilySamples;
use Prometheus\RenderTextFormat;
use Throwable;

/**
 * `GET /metrics` — el formato de exposicion de Prometheus (doc 02 §8.1 y §8.2,
 * decisiones 1 a 3 de la ficha 3.1).
 *
 * ## Fuera de `/api/v1` y fuera del contrato OpenAPI, a proposito
 *
 * El Anexo B del doc 01 la lista como `[red interna]`, no la consume ninguna de
 * las tres SPA ni el quiosco, y su forma la fija Prometheus —no `openapi.yaml`—.
 * Meterla bajo `/api/v1` la habria puesto en el contrato del que se genera el
 * cliente TypeScript de los tres frontends, describiendo un `text/plain` que
 * ninguno va a pedir jamas. La ruta la declara `MetricsServiceProvider`, sin
 * sesion, sin CSRF y sin el grupo `api`: nada de eso pinta en un scrape.
 *
 * ## Quien entra: la red, no un rol
 *
 * {@see RestrictToMetricsNetwork}, y solo en esta ruta.
 *
 * ## Nunca `5xx`, y esa es la decision de este controlador
 *
 * Si Redis no contesta, si una serie esta corrupta o si la cola no responde, se
 * publica **lo que se pueda componer** y se responde `200`. El motivo es
 * operativo: Prometheus marca el objetivo como `DOWN` ante un `5xx`, y la
 * alerta que salta entonces dice «la aplicacion no responde». Una averia del
 * almacen de metricas se leeria como una caida del producto, y alguien iria a
 * reiniciar un sistema que esta atendiendo fichajes perfectamente.
 *
 * ## Sin cache
 *
 * `no-store`. Un scrape es una foto del instante; una respuesta cacheada haria
 * que un contador pareciera plano justo cuando esta subiendo.
 */
final readonly class MetricsController
{
    /**
     * El tipo de contenido que exige el formato de exposicion de texto, version
     * 0.0.4, mas el juego de caracteres: los `# HELP` van en castellano y llevan
     * acentos.
     */
    public const string CONTENT_TYPE = RenderTextFormat::MIME_TYPE.'; charset=utf-8';

    public function __construct(
        private RedisMetricReader $reader,
        private QueueDepthCollector $queues,
    ) {}

    public function __invoke(): Response
    {
        $families = [
            ...$this->fromRedis(),
            ...$this->fromQueues(),
        ];

        return new Response(
            $this->render($families),
            Response::HTTP_OK,
            [
                'Content-Type' => self::CONTENT_TYPE,
                'Cache-Control' => 'no-store',
            ],
        );
    }

    /**
     * @return list<MetricFamilySamples>
     */
    private function fromRedis(): array
    {
        try {
            return $this->reader->read();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return list<MetricFamilySamples>
     */
    private function fromQueues(): array
    {
        try {
            $family = $this->queues->collect();
        } catch (Throwable) {
            return [];
        }

        return $family instanceof MetricFamilySamples ? [$family] : [];
    }

    /**
     * @param  list<MetricFamilySamples>  $families
     */
    private function render(array $families): string
    {
        if ($families === []) {
            // El renderizador devolveria un salto de linea suelto. Vacio de
            // verdad es mas honesto y Prometheus lo acepta igual: cero series.
            return '';
        }

        try {
            // `silent`: una muestra con etiquetas descuadradas sale como
            // comentario en vez de tirar el scrape entero. Es lo mismo que
            // hace el lector con una serie ilegible, un nivel mas abajo.
            return (new RenderTextFormat)->render($families, silent: true);
        } catch (Throwable) {
            return '';
        }
    }
}

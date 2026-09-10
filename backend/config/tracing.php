<?php

declare(strict_types=1);

/*
 * Trazas OTLP (doc 02 §8.1, tarea 3.1, decision 5 de la ficha).
 *
 * EL ENDPOINT VACIO ES EL ESTADO DE SERIE, Y NO ES UN CASO DEGRADADO. La
 * mayoria de las instalaciones de un hotel no exportan trazas: sin destino, el
 * `TracerProvider` NO se construye, `Globals` sigue devolviendo el proveedor
 * inerte y todo lo que ya usa `SpanScope` cuesta exactamente lo que costaba —
 * nada—. Solo cuando alguien declara un colector se paga el SDK.
 *
 * NADA DE ESTO PUEDE PARAR UN FICHAJE (regla dura 15 y 19). El exportador tiene
 * tiempo maximo corto, no reintenta y sus fallos se tragan: perder una traza es
 * infinitamente mas barato que perder una jornada. La prueba que lo fija apunta
 * el endpoint a un destino inalcanzable y comprueba que `POST /api/v1/scan`
 * responde igual y en el mismo tiempo.
 *
 * TODO ES CONFIGURACION (regla dura 13): el destino, el muestreo y el nombre del
 * servicio son del despliegue del cliente, no del repositorio.
 */

return [

    /*
     * Colector OTLP/HTTP. En desarrollo, `http://tempo:4318`; vacio desactiva la
     * exportacion entera.
     *
     * Se le anade `/v1/traces` al resolverlo: aqui va la RAIZ del colector, que
     * es lo que documentan `.env.example` y `configuracion.md`.
     */
    'endpoint' => (string) env('OTEL_EXPORTER_OTLP_ENDPOINT', ''),

    /*
     * `service.name` del recurso. Es la etiqueta por la que se busca en Tempo y
     * en Grafana, y la que separa las trazas de la API de las de cualquier otro
     * proceso que el cliente exporte al mismo colector.
     */
    'service_name' => (string) env('OTEL_SERVICE_NAME', 'kronoqr-api'),

    /*
     * Proporcion de trazas muestreadas, de 0.0 a 1.0.
     *
     * 1.0 DE SERIE A PROPOSITO: un hotel produce miles de trazas al dia, no
     * millones, y la pregunta que esto tiene que poder responder —«el empleado
     * dice que ficho a las 07:02»— es sobre UNA traza concreta. Muestrear al 10 %
     * significa no tener justo la que se busca nueve de cada diez veces.
     *
     * El muestreo es `ParentBased`: si el `traceparent` del quiosco dice que la
     * traza esta muestreada, se respeta. Lo contrario partiria la traza por la
     * mitad en el punto exacto en que empieza a ser util.
     */
    'sampler_ratio' => (float) env('OTEL_TRACES_SAMPLER_ARG', 1.0),

    /*
     * Tiempo maximo del envio al colector, en segundos. Corto y SIN REINTENTOS
     * (ver el docblock de {@see \App\Support\Observability\Tracing\TracerFactory}):
     * el envio ocurre al cerrar el proceso de la peticion, pero un colector que
     * acepta la conexion y no contesta seguiria ocupando el proceso de PHP-FPM
     * que atiende al siguiente fichaje.
     */
    'timeout_seconds' => (float) env('OTEL_EXPORTER_OTLP_TIMEOUT', 2),

];

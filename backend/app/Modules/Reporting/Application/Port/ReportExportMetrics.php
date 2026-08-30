<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Port;

/**
 * `report_exports_total{format}` — cuantos informes se descargan y en que
 * formato (doc 02 §8.2, **RF-IN-04**).
 *
 * ## Que responde
 *
 * Dos preguntas que nadie puede contestar mirando `audit_log` sin escribir una
 * consulta: si la exportacion se usa —una funcionalidad que nadie descarga es
 * una que se puede simplificar— y **cual de los tres formatos**. Lo segundo
 * importa de verdad: si el PDF resulta ser el 2 % de las descargas, la
 * dependencia de Chromium en el servidor de cada cliente deja de estar
 * justificada.
 *
 * ## Es un contador, y aqui si se puede incrementar al leer
 *
 * Al contrario que `worked_minutes_total`, que mide un hecho del negocio y por
 * eso solo puede crecer cuando el hecho ocurre. Esto mide **el uso de una
 * pantalla**: cada descarga es un suceso nuevo y contarla dos veces solo pasaria
 * si alguien pulsara dos veces, que es exactamente lo que se quiere ver.
 *
 * ## Una sola etiqueta y de cardinalidad tres
 *
 * `format` ∈ `{csv, xlsx, pdf}`. **Ni `employee_uuid`, ni el actor, ni el
 * periodo** (regla dura 21): una serie temporal por cuenta seria un registro de
 * actividad paralelo, sin retencion ni control de acceso, y quien exporto que
 * ya esta en `audit_log`, que si tiene las dos cosas.
 *
 * ## Medir no puede romper una descarga
 *
 * El adaptador traga sus propios fallos, igual que el resto de las metricas del
 * producto: perder un punto de una serie es infinitamente mas barato que dejar
 * sin informe a quien tiene que cerrar una nomina.
 */
interface ReportExportMetrics
{
    /**
     * @param  string  $format  `csv`, `xlsx` o `pdf`. Vocabulario estable y en ingles:
     *                          es una etiqueta de Prometheus, no un texto de usuario.
     */
    public function exported(string $format): void;
}

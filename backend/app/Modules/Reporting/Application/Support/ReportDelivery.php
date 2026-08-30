<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Support;

/**
 * **Como sale** el informe por periodo de la instalacion (RF-IN-04, RS-05).
 *
 * ## Por que la forma de entrega llega hasta el caso de uso
 *
 * Podria parecer presentacion pura —y en parte lo es— pero hay una razon por la
 * que este enumerado no se queda en `Http`: el asiento de `audit_log` que
 * escribe `GeneratePeriodReport` tiene que decir **en que** se llevo alguien las
 * horas de la plantilla. No es lo mismo mirar una tabla en pantalla que
 * descargar un XLSX que se puede reenviar por correo, y RL-15 obliga a poder
 * contestar cual de las dos cosas paso.
 *
 * La alternativa era un segundo asiento escrito desde el controlador, y se
 * descarta: habria dos entradas por descarga —una «consultado» y otra
 * «exportado»— sobre exactamente la misma divulgacion, y quien lea el trail
 * tendria que saber emparejarlas. Un campo en el asiento que ya existe responde
 * la misma pregunta sin duplicar nada.
 *
 * ## Los valores son vocabulario estable
 *
 * Van en ingles y en minusculas porque acaban en `audit_log` y en la cadena de
 * consulta de la API. No se traducen: lo que traduce una persona es el rotulo
 * del boton, no el valor que queda escrito en el trail durante cuatro años.
 */
enum ReportDelivery: string
{
    /** La consulta JSON del panel: `GET /api/v1/reports/period`. */
    case Json = 'json';

    case Csv = 'csv';

    case Xlsx = 'xlsx';

    case Pdf = 'pdf';
}

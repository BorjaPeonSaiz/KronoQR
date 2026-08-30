<?php

declare(strict_types=1);

/*
 * Informes por periodo (RF-IN-01..03, tarea 2.8).
 *
 * NADA ESPECIFICO DE UN CLIENTE VIVE EN EL CODIGO (ADR-017, regla dura 13). Los
 * tres numeros de aqui son presupuestos de RECURSOS, no reglas de negocio ni
 * umbrales legales —esos se leen del perfil de cumplimiento (regla dura 14)—, y
 * un cliente con un servidor mas grande o una plantilla mayor los ajusta en su
 * `.env` sin tocar el repositorio.
 *
 * LOS TRES DEFIENDEN LO MISMO: que un informe no se coma la base de datos que
 * atiende el fichaje (RNF-P-02, regla dura 19). Se disparan a distinta altura
 * porque miden cosas distintas —cuanto calendario, cuantas filas y cuanto
 * tiempo— y ninguno sustituye a los otros: un rango corto sobre una plantilla
 * enorme pasa el primero y no el segundo, y un plan de consulta que se degrada
 * pasa los dos y no el tercero.
 */

return [

    'period' => [

        /*
         * Techo del rango que se entrega en el acto, en dias.
         *
         * Tres meses, que es el criterio literal del paso 5 de `/informe-nuevo`:
         * mas de eso va a la generacion en diferido de RF-IN-06 (tarea 3.9). Se
         * comprueba ANTES de tocar la base de datos, que es lo barato.
         *
         * No sustituye al techo de `DateRange::MAXIMUM_DAYS` (366): aquel es el
         * limite del objeto de dominio para cualquier consulta de jornadas, y
         * este es el presupuesto sincrono de ESTE informe, que es mas caro
         * porque cruza la plantilla entera con el calendario.
         */
        'max_range_days' => (int) env('REPORTING_PERIOD_MAX_RANGE_DAYS', 92),

        /*
         * Techo de filas del resultado: sujetos x cubos de periodo.
         *
         * Veinte mil es, con la granularidad diaria, la plantilla de 500
         * personas durante cuarenta dias, o la de 200 durante un trimestre. Por
         * encima de eso, la respuesta ya no es una tabla que alguien lee: es una
         * exportacion, y para eso estan la tarea 2.9 y RF-IN-06.
         *
         * Se estima ANTES de ejecutar, contando sujetos del alcance y cubos del
         * rango. Un `LIMIT` no serviria: recortar en silencio un informe de
         * horas es peor que no darlo, porque nadie ve que falta media plantilla.
         */
        'max_rows' => (int) env('REPORTING_PERIOD_MAX_ROWS', 20000),

        /*
         * `statement_timeout` de la consulta del informe, en segundos.
         *
         * Diez, que es el umbral del paso 5 de `/informe-nuevo` por encima del
         * cual el informe deja de ser sincrono. Se aplica con `SET LOCAL` en la
         * transaccion de la consulta y no con un cronometro en PHP: asi lo corta
         * PostgreSQL y libera la conexion, en lugar de descubrir tarde que la
         * consulta lleva cuarenta segundos ocupando la base de datos que
         * atiende el fichaje.
         *
         * Se aplica SOLO a esta consulta. Un `statement_timeout` global cortaria
         * migraciones y reconciliaciones que legitimamente tardan mas.
         */
        'statement_timeout_seconds' => (int) env('REPORTING_PERIOD_TIMEOUT_SECONDS', 10),
    ],
];

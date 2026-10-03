<?php

declare(strict_types=1);

/*
 * El registro horario — lo que no es un umbral legal (perfil de cumplimiento,
 * regla dura 14) ni un ajuste del hotel (`installation_settings`, RF-PD-01),
 * sino una cota de seguridad de la instalacion.
 *
 * TODO LO DE AQUI ES CONFIGURACION, NO CONSTANTES (regla dura 13, ADR-017).
 */

return [

    /*
     * RN-22 (ADR-047, F6 del dictamen de seguridad del bloque 18): cuantos dias
     * hacia atras desde su recepcion puede caer el `occurred_at` de un fichaje
     * descartado para que la revision diaria abra la incidencia
     * `discarded_scan`.
     *
     * El `occurred_at` lo pone la tablet. Sin esta cota, quien tuviera un token
     * de quiosco robado y un codigo de empleado podria sembrar una incidencia
     * por cada fecha que eligiera. Treinta y un dias cubren de sobra la cola de
     * una tablet que estuvo un mes sin red; el aviso se guarda igual fuera de la
     * ventana, solo no abre incidencia.
     *
     * NO es una clave de `installation_settings` ni del perfil de cumplimiento:
     * no cambia que se considera trabajo, solo cuanto se cree a la tablet.
     *
     * Minimo 1: un cero o un negativo haria fallar `DetectAnomaliesCommand` y,
     * con el, la revision nocturna entera.
     */
    'discard_review_window_days' => max(1, (int) env('ATTENDANCE_DISCARD_REVIEW_WINDOW_DAYS', 31)),

];

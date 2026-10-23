<?php

declare(strict_types=1);
use App\Modules\Attendance\Domain\ValueObject\ScanDiscardThreshold;

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

    /*
     * RN-22 (ADR-061, 2.3.0): cuando deja el servidor de intentar un fichaje que
     * no consigue procesar y lo descarta con incidencia, para que la cola del
     * quiosco siga. Las tres condiciones a la vez:
     *
     * - `min_attempts`: intentos fallidos contados del mismo fichaje, el ultimo
     *   incluido. De serie 10; **nunca menos de 3**.
     * - `min_minutes`: minutos entre el primer intento contado y el actual. De
     *   serie 60; **nunca menos de 15**. Da margen a arreglar un defecto pasajero
     *   antes de que cueste trabajo a RRHH.
     * - `daily_budget`: descartes del servidor por quiosco en 24 h. De serie 3.
     *   **Con 0 el descarte queda apagado** y el servidor conserva para siempre,
     *   como antes de ADR-061.
     *
     * Son configuracion TECNICA, no umbrales legales: no van al perfil de
     * cumplimiento (regla dura 14). Un valor por debajo de un suelo NO se corrige
     * aqui en silencio: `ScanDiscardThreshold` se niega a construirse y el error
     * salta al leerlo. Se guardan como cadenas y los convierte el adaptador, para
     * que un valor no numerico tambien falle en voz alta en lugar de valer cero.
     */
    'scan_discard' => [
        'min_attempts' => (string) env('SCAN_DISCARD_MIN_ATTEMPTS', '10'),
        'min_minutes' => (string) env('SCAN_DISCARD_MIN_MINUTES', '60'),
        'daily_budget' => (string) env('SCAN_DISCARD_DAILY_BUDGET', '3'),
        // Techo del cupo (A6): no se configura. Lo lee `product:doctor` para
        // avisar de un `.env` por encima, que se aplica como 20.
        'budget_ceiling' => ScanDiscardThreshold::BUDGET_CEILING,
    ],

];

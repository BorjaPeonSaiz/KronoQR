<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

use DateTimeImmutable;

/**
 * Fuente de tiempo del sistema (ADR-021, regla dura 2).
 *
 * Ninguna clase de dominio llama a now(), time() ni Carbon::now(): el caso de
 * uso pide el instante a este puerto y se lo pasa ya resuelto al agregado. Es
 * lo que permite probar de forma determinista los dos cambios de hora y los
 * turnos que cruzan medianoche, que es la mitad del riesgo de este dominio.
 *
 * Vive en Shared porque lo necesitan Attendance, Compliance, Kiosk, Reporting
 * y el scheduler, y no representa una regla de negocio de ninguno de ellos: el
 * criterio de admision de ADR-021. Vive en Application y no en Domain porque
 * el dominio recibe instantes en lugar de pedirlos.
 *
 * En las pruebas se sustituye por un reloj fijo o controlable; esa es la
 * segunda implementacion que justifica la interfaz (doc 02 §3.5).
 */
interface Clock
{
    /**
     * Instante actual, siempre en UTC (regla dura 3).
     *
     * La conversion a la zona del centro ocurre en la capa de presentacion,
     * nunca aqui: la zona horaria es un atributo del centro (sites.timezone),
     * no del proceso.
     */
    public function now(): DateTimeImmutable;
}

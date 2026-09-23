<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Port;

use DateTimeImmutable;

/**
 * El registro de resumenes semanales ya enviados (`weekly_summary_deliveries`,
 * decision 6 de la ficha 3.12, reordenado por la decision 13).
 *
 * ## Para que una tabla, si ya esta el asiento de `audit_log`
 *
 * Porque `audit_log` responde «que salio de aqui» y esto responde **«hace falta
 * enviarlo»**, que es otra pregunta y se hace antes. Repetir el comando —o pasar
 * `--week=` de una semana ya enviada— no puede producir un segundo correo con
 * los mismos nombres: dos copias del mismo dato personal fuera del sistema, y un
 * responsable que deja de leer un correo que llega dos veces.
 *
 * Consultar `audit_log` para decidirlo habria convertido el trail —solo-apendice
 * y particionado por fechas— en el indice operativo de otra cosa.
 *
 * ## Reclamar, enviar, y solo despues dejar constancia
 *
 * Las tres operaciones de escritura dibujan ese orden, y el orden es lo que
 * impide dos cosas a la vez:
 *
 * 1. {@see self::claim()} **antes** de enviar. Es lo unico que cierra la carrera
 *    de dos pasadas simultaneas, porque quien decide es el
 *    `UNIQUE (manager_user_id, week_start)` y no una comprobacion en PHP: entre
 *    un `SELECT` y un `INSERT` cabe la otra pasada. El que pierde la carrera
 *    recibe `false` y no envia nada.
 * 2. {@see self::release()} si el correo **no** salio. La semana vuelve a estar
 *    pendiente y entra en la pasada del lunes siguiente o en un `--week` a mano.
 *    Es la unica fila de este producto que se borra, y se borra porque **nunca
 *    describio un hecho**: era una reserva de turno, no un envio (la regla dura
 *    5 protege el registro horario y las evidencias, no una reclamacion que no
 *    llego a nada).
 *
 * **La transaccion de cada una dura lo que dura su sentencia.** El envio queda
 * fuera de todas: con el dentro, la transaccion del asiento retenia el candado
 * de la cadena de auditoria —el mismo por el que pasa cada fichaje, ADR-010—
 * durante una conversacion SMTP completa (decision 13).
 */
interface WeeklySummaryDeliveries
{
    /**
     * Si ya salio el resumen de esa semana para esa cuenta.
     *
     * Es la comprobacion **barata** que evita componer un informe que no hace
     * falta, no el control de concurrencia: ese es el `UNIQUE` que aplica
     * {@see self::claim()}.
     *
     * @param  string  $weekStart  Lunes de la semana, fecha ISO `AAAA-MM-DD`.
     */
    public function wasSent(int $managerUserId, string $weekStart): bool;

    /**
     * Reclama el envio de esa semana para esa cuenta.
     *
     * @param  string  $weekStart  Lunes de la semana, fecha ISO `AAAA-MM-DD`.
     * @param  int  $employeeCount  Personas distintas que lleva el resumen.
     * @param  int  $rowCount  Lineas del informe, detalladas o no.
     * @return bool `false` si otra pasada se la llevo primero. **No lanza**: perder
     *              la carrera es un desenlace normal, no una averia, y quien llama
     *              lo cuenta como omitido.
     */
    public function claim(
        int $managerUserId,
        string $weekStart,
        DateTimeImmutable $sentAt,
        int $employeeCount,
        int $rowCount,
    ): bool;

    /**
     * Retira una reclamacion cuyo correo no llego a salir.
     *
     * @param  string  $weekStart  Lunes de la semana, fecha ISO `AAAA-MM-DD`.
     */
    public function release(int $managerUserId, string $weekStart): void;
}

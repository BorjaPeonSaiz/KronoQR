<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Exception;

use RuntimeException;

/**
 * El correo del resumen semanal no salio (RF-PR-05, decision 6 de la ficha
 * 3.12).
 *
 * **Existe para deshacer una transaccion, y eso es todo lo que hace.** La fila
 * de `weekly_summary_deliveries` se escribe **antes** de enviar para que dos
 * pasadas simultaneas no puedan mandar el mismo resumen dos veces; si el SMTP
 * falla, lo que tiene que quedar es el estado de antes —sin fila y sin asiento
 * de divulgacion, porque no se divulgo nada— y la semana pendiente para el
 * reintento del lunes siguiente. Un `return false` no puede hacer eso desde
 * dentro de la transaccion.
 *
 * La atrapa el propio caso de uso, la cuenta como fallo y sigue con el
 * destinatario siguiente: un SMTP que rechaza un correo no puede dejar sin
 * resumen a los otros nueve responsables.
 *
 * **No lleva ni direccion ni nombre** (regla dura 21): el identificador de la
 * cuenta basta para diagnosticar, y este mensaje puede acabar en una traza.
 */
final class WeeklySummaryNotDelivered extends RuntimeException
{
    public static function toManager(int $managerUserId, string $week): self
    {
        return new self('El resumen semanal '.$week.' no se pudo entregar a la cuenta '.$managerUserId.'.');
    }
}

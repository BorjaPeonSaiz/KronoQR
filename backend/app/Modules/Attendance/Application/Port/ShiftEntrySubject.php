<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Port;

/**
 * De quien es un tramo, para poder decidir si queda dentro del alcance de quien
 * lo quiere corregir (**RF-ID-03**).
 *
 * ## Por que no es un metodo mas de {@see ShiftEntryHistory}
 *
 * Aquel responde una sola pregunta —«¿esto es historico?»— y lo dice de si mismo:
 * es deliberadamente estrecho porque existe para elegir entre `404` y `409`. Esta
 * es otra pregunta y la hace otra capa por otro motivo: autorizar antes de
 * ejecutar. Juntarlas convertiria un puerto con un proposito en un cajon de
 * consultas sueltas sobre `shift_entries`.
 *
 * ## Se pregunta ANTES de invocar el caso de uso
 *
 * Es lo que hace que un responsable de Cocina no pueda corregir un tramo de
 * Recepcion: la comprobacion ocurre antes de tocar nada, no despues de haberlo
 * cambiado. Y devuelve el UUID **publico**, que es el unico identificador de
 * persona admitido en un asiento de auditoria o en un log (regla dura 21).
 */
interface ShiftEntrySubject
{
    /**
     * UUID publico del empleado dueño de ese tramo, o `null` si el tramo no
     * existe.
     *
     * **Incluye los tramos que ya no son vigentes** —anulados y sustituidos—:
     * quien pide corregir uno de esos recibira su `409` o su `404` del caso de
     * uso, y para decidir si puede intentarlo hace falta saber de quien era.
     */
    public function employeeUuidOf(string $shiftEntryUuid): ?string;
}

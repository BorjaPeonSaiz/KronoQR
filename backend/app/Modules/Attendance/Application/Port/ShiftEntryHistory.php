<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Port;

/**
 * El historico de tramos, para lo unico que el borde HTTP necesita saber de el:
 * **distinguir «nunca existio» de «ya no es la version vigente»**.
 *
 * ## Por que existe
 *
 * `WorkDayRepository::findWorkDayOfShiftEntry()` devuelve `null` en los dos
 * casos, y con razon: en ninguno de los dos hay una jornada que pueda corregir
 * ese tramo (ADR-026). Pero para quien llamo al endpoint no son lo mismo, y
 * ADR-035 lo fija por escrito: un `PATCH` sobre una version ya sustituida
 * responde **`409`**, no `404`, porque ese tramo existio y lo que hay que hacer
 * es volver a leer la jornada; un `uuid` inventado responde `404`.
 *
 * Confundirlos tiene consecuencia real: dos responsables corrigiendo la misma
 * jornada a la vez es lo normal en un cambio de turno, y un `404` les diria que
 * el tramo no existe cuando lo que ha pasado es que el otro llego antes.
 *
 * ## Por que es un puerto y no una consulta desde el controlador
 *
 * Porque un controlador no toca Eloquent. Es solo lectura y no participa de
 * ninguna transaccion: se consulta **despues** de que el caso de uso haya
 * abortado, para decidir con que codigo responder.
 *
 * ## Por que no lleva mas metodos
 *
 * Deliberadamente estrecho. El historico completo de una jornada —la cadena de
 * versiones que enseña el panel de detalle— es de la tarea 1.16 y tendra su
 * propia consulta de lectura. Este puerto responde una sola pregunta, la que la
 * capa HTTP necesita para elegir un codigo de estado.
 */
interface ShiftEntryHistory
{
    /**
     * Si existe un tramo con ese identificador que **ya no es vigente**:
     * anulado (`voided`) o sustituido por una version posterior (`superseded`).
     *
     * `false` tanto si el tramo no existe como si sigue siendo vigente. El
     * segundo caso no se da en la practica —quien pregunta lo hace porque el
     * repositorio no lo encontro— pero la respuesta es la correcta igualmente:
     * lo que este metodo afirma es «esto es historico», no «esto no esta».
     */
    public function isRetired(string $shiftEntryUuid): bool;
}

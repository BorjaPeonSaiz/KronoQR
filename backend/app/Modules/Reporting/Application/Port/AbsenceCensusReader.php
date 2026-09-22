<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Port;

/**
 * Cuantas personas **de alta** tienen hoy una ausencia activa, por tipo
 * (RF-GP-04, doc 02 §8.2).
 *
 * ## Personas, no ausencias
 *
 * Se cuentan sujetos distintos y no filas: la restriccion de exclusion de
 * `absences` ya impide que una persona tenga dos ausencias activas solapadas, asi
 * que para un mismo dia las dos cifras coinciden — pero decirlo aqui es lo que
 * impide que el dia de mañana alguien cuente dos veces a la misma persona por
 * cambiar la consulta.
 *
 * ## «De alta», por lo mismo que en el informe
 *
 * Entre `hired_at` y `terminated_at`. Una ausencia registrada de alguien que ya
 * causo baja no describe a nadie ausente hoy: describe un hecho del historico.
 *
 * ## Sin nombres y sin identificadores de persona
 *
 * Lo que sale de aqui son cuatro numeros. Es el puerto de una serie de
 * Prometheus, y una etiqueta con un `employee_uuid` crearia una serie por
 * empleado (regla dura 21, doc 02 §8.2).
 */
interface AbsenceCensusReader
{
    /**
     * Personas de alta con una ausencia activa que cubre `$isoDate`, por tipo.
     *
     * @param  string  $isoDate  fecha civil del centro, `AAAA-MM-DD`
     * @return array<string, int> solo los tipos con alguna; quien publica completa el resto
     */
    public function activeOn(string $isoDate): array;
}

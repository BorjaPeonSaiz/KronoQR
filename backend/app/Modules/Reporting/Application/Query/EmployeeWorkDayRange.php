<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Query;

/**
 * Lo que pide quien consulta el registro horario de alguien: a quien y de
 * cuando.
 *
 * Las dos fechas son **opcionales** y llegan tal y como vinieron en la URL, sin
 * interpretar: resolver la omision necesita la zona del centro del empleado, y
 * eso solo se sabe despues de buscarlo. Lo hace {@see ReadEmployeeWorkDays}.
 */
final readonly class EmployeeWorkDayRange
{
    public function __construct(
        public string $employeeUuid,
        /** `YYYY-MM-DD` o `null` para «los 31 dias que terminan en `to`». */
        public ?string $from = null,
        /** `YYYY-MM-DD` o `null` para «hoy en la zona del centro». */
        public ?string $to = null,
        /**
         * Si quien consulta es **la propia persona** desde su portal (RF-ID-05,
         * RL-05, tarea 1.11) en lugar de alguien de gestion mirando el registro
         * de un tercero (RF-PA-03).
         *
         * **Lo unico que cambia es si el acceso deja asiento en `audit_log`**, y
         * es lo que RS-05 dice literalmente: se registra el acceso a datos
         * personales **de terceros**. Aqui no hay tercero. Un apunte por cada
         * vez que alguien mira sus propias horas convertiria un derecho —el del
         * art. 34.9 ET— en una traza del ejercicio de ese derecho, guardada
         * cuatro años (RL-02) y consultable por su empleador.
         *
         * **Va aqui y no en el controlador** por lo mismo que la constancia
         * tampoco se escribe alli: la decision de auditar o no es del caso de
         * uso, y un tercer camino hacia este registro tendra que declarar en su
         * peticion cual de los dos es.
         *
         * Por omision es `false`: el caso que audita es el que se asume, no el
         * que hay que acordarse de pedir.
         */
        public bool $selfService = false,
    ) {}
}

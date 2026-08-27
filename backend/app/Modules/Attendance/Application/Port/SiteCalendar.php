<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Port;

use DateTimeZone;

/**
 * La zona horaria del centro, sin la cual RN-05 no es expresable: la jornada es
 * «la fecha civil, **en la zona del centro**, del `clocked_in_at` del tramo que
 * abre la jornada».
 *
 * **Lo declara el nucleo y lo implementa `Workforce`** (ADR-025), que es donde
 * esta `sites.timezone`. La zona es un atributo del centro y no del proceso:
 * `APP_TIMEZONE` es UTC siempre (regla dura 3), y una instalacion puede tener
 * hoteles en husos distintos.
 *
 * Habla en `DateTimeZone` —una clase del nucleo de PHP— y en un escalar, de
 * modo que el adaptador de Workforce no necesita importar nada del `Domain` de
 * Attendance (ADR-025, restriccion 2).
 *
 * Solo la zona, de momento. El calendario laboral del centro entrara aqui
 * cuando alguna regla lo necesite; anadirlo ahora seria un metodo sin nadie que
 * lo llame.
 */
interface SiteCalendar
{
    /**
     * Zona horaria del centro, o `null` si el centro no existe.
     *
     * Devuelve `null` en lugar de lanzar para que el caso de uso decida: un
     * empleado adscrito a un centro que no esta es una inconsistencia de datos,
     * y la respuesta correcta es registrar el escaneo y abrir una incidencia,
     * no dejar a alguien sin jornada (regla dura 19).
     */
    public function timezoneOf(int $siteId): ?DateTimeZone;
}

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

    /**
     * La zona con la que se va a **escribir** un tramo, leida con la fila del
     * centro compartida (`FOR KEY SHARE`) hasta el final de la transaccion de
     * quien llama (RN-23, ADR-056).
     *
     * Es la otra mitad de la carrera con el cambio de zona: `PATCH /site` bloquea
     * la fila con `FOR UPDATE` y despues pregunta si hay registro horario. Con
     * esta lectura, o el cambio espera a que el tramo confirme —y entonces lo ve
     * y se niega—, o el tramo espera al cambio y se atribuye con la zona nueva.
     * Sin ella, un primer fichaje concurrente podia escribir su `work_date` con
     * la zona vieja justo cuando la nueva quedaba fija.
     *
     * **Un metodo aparte y no un cambio en {@see self::timezoneOf()}**: un
     * candado de fila falla en una transaccion `READ ONLY`, y las lecturas del
     * registro —el diario, el portal, los informes— la usan asi. Solo la
     * llaman, **dentro de su transaccion**, los casos de uso que escriben un
     * tramo nuevo: el fichaje y el alta manual.
     */
    public function timezoneForRecording(int $siteId): ?DateTimeZone;
}

<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Port;

/**
 * Si la instalacion ya tiene registro horario (RN-23, ADR-056).
 *
 * **Por que es un puerto de `Workforce` y no una llamada a `Attendance`.** La
 * zona horaria es un atributo del centro y la escribe este modulo; el registro
 * horario es de `Attendance`, que este modulo no puede importar (doc 02 §1.6,
 * Deptrac). Lo unico que hace falta saber de el es si existe y cuanto abarca, y
 * eso es una lectura de la tabla `shift_entries`, no una dependencia de su
 * modelo: la misma licencia que se toma `DatabaseManagementAccountLookup` con
 * `users`.
 *
 * **Cualquier tramo cuenta, en cualquier estado** —abierto, cerrado, sustituido
 * o anulado—. Un tramo anulado tambien se fichó con la zona de entonces y su
 * `work_date` se calculo con ella: sigue en el registro legal (regla dura 5).
 *
 * **Se pregunta dentro de la transaccion y con la fila del centro bloqueada**
 * (`SiteRepository::installationSiteForUpdate()`). El primer fichaje lee la zona
 * con `FOR KEY SHARE` antes de escribir su tramo (`SiteCalendar::
 * timezoneForRecording()`): o ese fichaje ya confirmo y esta lectura lo ve, o
 * espera a que el cambio de zona confirme y ficha con la zona nueva.
 */
interface WorkRecordPresence
{
    /**
     * `true` en cuanto existe un tramo, en cualquier estado.
     */
    public function exists(): bool;

    /**
     * Cuanto registro horario hay, para la confirmacion de la correccion por
     * consola: numero de tramos y primera y ultima jornada. `null` si no hay
     * ninguno.
     */
    public function extent(): ?WorkRecordExtent;
}

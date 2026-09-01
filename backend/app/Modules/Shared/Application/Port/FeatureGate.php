<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

use App\Modules\Shared\Domain\ValueObject\Feature;
use App\Modules\Shared\Domain\ValueObject\FeatureAvailability;

/**
 * **El punto unico de decision** sobre que funcionalidad accesoria esta
 * disponible (**ADR-023**, ADR-028, RF-PD-05, regla dura 15).
 *
 * ## Por que es un puerto y por que vive en `Shared`
 *
 * Lo consumen `Reporting` (informes avanzados y presencia en tiempo real) y lo
 * consumiran `Product` (marca blanca) y `Compliance` (resumen semanal). Ninguno
 * de ellos puede importar `Product`, que es donde estan la tabla `license` y el
 * verificador (doc 02 §1.6). Lo transversal va a `Shared` y su adaptador al
 * modulo que tiene la tabla, que es exactamente la regla de ADR-025.
 *
 * ## Lo que este puerto NO puede hacer
 *
 * **No acepta cadenas.** El argumento es {@see Feature}, cuyo catalogo son las
 * siete funcionalidades accesorias de ADR-023 y ninguna mas. El conjunto legal
 * —fichaje, cola offline, consulta de jornadas, portal, exportacion para la
 * Inspeccion, auditoria, correcciones, copias y sondas— no tiene caso en ese
 * enum, de modo que **no existe forma de preguntar si el fichaje esta
 * habilitado**. La regla dura 15 no depende aqui de la disciplina de quien
 * escribe el codigo, sino del sistema de tipos.
 *
 * **No expone el estado de la licencia.** Quien consume esto se entera de si
 * puede pintar una comparativa, no de que plan tiene el cliente ni de cuando
 * caduca. Eso vive en `GET /api/v1/license` y en `license:show`, que son de
 * `admin`. `tests/Architecture/LicenseBoundaryTest.php` comprueba que ningun
 * fichero fuera de `Product` lee la licencia por otra via.
 *
 * ## Los limites del plan no estan aqui
 *
 * `max_employees` y `max_devices` **no bloquean nada** (ADR-028): no hay ningun
 * metodo del tipo `canHireAnother()`, porque la existencia de ese metodo seria
 * ya la invitacion a llamarlo desde el alta. El exceso lo cuenta un observador
 * de eventos en `Product` y produce aviso, asiento y cifra — nunca un rechazo.
 */
interface FeatureGate
{
    /**
     * Atajo para el caso en que solo hace falta decidir, sin redactar el aviso.
     *
     * Es el mismo calculo que {@see self::statusOf()}: existe para que un
     * `if` no tenga que leer `->enabled` de un objeto y para que la intencion se
     * lea en la llamada.
     */
    public function isEnabled(Feature $feature): bool;

    /**
     * La disponibilidad **con su motivo y su fecha**, para poder degradar de
     * forma honesta (ADR-019).
     */
    public function statusOf(Feature $feature): FeatureAvailability;
}

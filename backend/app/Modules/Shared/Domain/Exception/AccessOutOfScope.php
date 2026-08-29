<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Exception;

use RuntimeException;

/**
 * Alguien con el rol y el ambito correctos ha pedido datos **fuera de su
 * alcance** (RF-ID-03, escenario «Aislamiento por departamento» del doc 01 §11).
 *
 * **`403` y no `404`.** El recurso existe y quien pregunta puede saberlo: es una
 * cuenta de gestion autenticada, no una pantalla en un pasillo, asi que la regla
 * dura 17 —rechazos genericos e indistinguibles— no aplica aqui; aquella protege
 * el camino de fichaje. Un `404` ademas mentiria, y quien lo recibiera abriria una
 * incidencia buscando una ficha que si existe.
 *
 * **El asiento en `audit_log` no lo escribe esta excepcion**, lo escribe quien la
 * lanza —`Shared\Application\Authorization\ScopeGuard`— antes de lanzarla. Si
 * dependiera del manejador de excepciones, un `catch` en cualquier sitio dejaria
 * el intento sin traza.
 */
final class AccessOutOfScope extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Ese dato esta fuera del alcance de tu departamento.');
    }
}

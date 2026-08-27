<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

use App\Modules\Shared\Domain\ValueObject\UserRole;

/**
 * Quien esta autenticado en el panel, visto desde la policy que autoriza.
 *
 * **Por que este puerto existe.** Cada endpoint tiene su policy y su prueba de
 * autorizacion negativa (regla dura 18), y la policy vive en el modulo que
 * posee el recurso: la de empleados en `Workforce`, la de credenciales en
 * `Identity`. Pero la cuenta autenticada la tiene `Identity`, y `Workforce` no
 * puede importar nada suyo (doc 02 §1.6, verificado por Deptrac). Sin una
 * abstraccion comun, la policy de `Workforce` tendria que tipar el modelo
 * Eloquent de otro modulo, que es justo la dependencia que la frontera prohibe.
 *
 * Vive en `Shared/Application/Port` por el criterio de admision de ADR-021: lo
 * consumen varios modulos, no es una regla de negocio de ninguno, y el modulo
 * que **tiene** el dato —`Identity`— es quien lo implementa (ADR-025).
 *
 * **Dos metodos y ni uno mas.** Lo que una policy necesita preguntar es quien
 * es y que rol tiene. El alcance por departamento de RF-ID-03 llega en la tarea
 * 2.1 y no se anticipa aqui: cuando llegue, se añadira el dato que haga falta
 * —el departamento del actor— y sera un cambio aditivo sobre esta interfaz.
 *
 * **El ambito del token no se pregunta aqui.** Se comprueba antes, en el
 * middleware `ability` de Sanctum (doc 02 §7.3), y son dos controles distintos a
 * proposito: el ambito dice **que** puede hacer el token, la policy **sobre que
 * datos**. Mezclarlos deja pasar un token de quiosco que resulte tener el rol
 * adecuado, o al reves.
 */
interface ManagementActor
{
    /**
     * Identificador publico de la cuenta (`users.uuid`).
     *
     * Es el unico identificador de una persona que puede aparecer en un log
     * tecnico o en `audit_log.actor`. Nunca su nombre ni su correo (regla dura
     * 21).
     */
    public function actorUuid(): string;

    /**
     * Si el actor tiene **alguno** de los roles indicados.
     *
     * Se pregunta por varios de una vez porque la autorizacion de este producto
     * se expresa asi: «manager+» es el conjunto `{admin, rrhh}` en la Fase 1 y
     * `{admin, rrhh, responsable_departamento}` a partir de 2.1. Preguntando rol
     * a rol, ampliar ese conjunto obligaria a tocar cada policy.
     */
    public function actsAs(UserRole ...$roles): bool;
}

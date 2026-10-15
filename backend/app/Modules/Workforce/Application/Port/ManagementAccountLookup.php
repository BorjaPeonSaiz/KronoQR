<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Port;

/**
 * Las cuentas de gestion que pueden dirigir un departamento (RF-ID-03,
 * RF-ID-10, ADR-051 §5).
 *
 * **Por que es un puerto de `Workforce` y no una llamada a `Identity`.** El
 * responsable es un atributo del departamento (`departments.manager_user_id`),
 * y lo escribe este modulo; pero las cuentas son de `Identity`, que este modulo
 * no puede importar (doc 02 §1.6, Deptrac). Lo unico que hace falta saber de
 * ellas es si una concreta es elegible y cual es su fila, y eso es una lectura
 * sobre la tabla y sus roles, no una dependencia del modelo de otro modulo.
 *
 * **Se llama dentro de la transaccion y con la cadena de auditoria tomada**
 * (`withChainLock`). La baja de una cuenta toma la cadena antes de tocar su
 * fila (ADR-051 §6), asi que, con la cadena en la mano, la respuesta no puede
 * quedar vieja antes de confirmar: o la baja ya se aplico y esto la ve, o se
 * aplicara despues de este cambio.
 */
interface ManagementAccountLookup
{
    /**
     * La cuenta, **si y solo si** existe, esta activa y tiene el rol
     * `responsable_departamento`. Cualquier otro caso responde `null`, sin
     * distinguir el motivo: el contrato da un unico `422` para los tres.
     */
    public function eligibleManager(string $uuid): ?EligibleManager;
}

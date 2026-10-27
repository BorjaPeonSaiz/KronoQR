<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Port;

/**
 * Las ventanas de uso de **una** concesion de soporte (**RF-PD-11**, RL-15,
 * RL-18, ADR-020 §3): los asientos `support_grant.used` que dejo.
 *
 * ## No es una consulta de la auditoria
 *
 * El adaptador lee `audit_log`, pero el puerto no admite ni una accion ni un
 * filtro: solo la clave de la concesion y la pagina. Quien lo llama no puede
 * pedir asientos de otra accion ni de otra concesion, y por eso servirlo al
 * `admin` no le abre una consulta general de la auditoria —para eso esta
 * `audit:read`—.
 *
 * ## Solo lo que el asiento ya decidio que se podia guardar
 *
 * El instante y el metodo y el **patron** de la ruta de la peticion que abrio
 * la ventana. El asiento no lleva nada mas que sea de la peticion (regla dura
 * 21), y este puerto no puede devolver lo que no lleva.
 */
interface SupportGrantUses
{
    /**
     * Una pagina de ventanas, de la mas reciente a la mas antigua.
     *
     * @param  int  $grantId  `support_grants.id`, ya resuelto desde el UUID publico
     * @param  int  $page  desde 1
     * @param  int  $perPage  entre 1 y 100
     */
    public function page(int $grantId, int $page, int $perPage): SupportGrantUsePage;
}

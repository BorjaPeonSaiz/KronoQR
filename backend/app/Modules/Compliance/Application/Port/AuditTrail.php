<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Port;

use App\Modules\Compliance\Domain\ValueObject\AuditEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditEntryDraft;

/**
 * El escritor de auditoria (regla dura 6, ADR-010, doc 02 §7.4).
 *
 * **Es el unico camino de escritura de `audit_log`.** No hay repositorio
 * publico, ni modelo Eloquent expuesto, ni `DB::table('audit_log')` en ningun
 * otro sitio: quien quiera dejar traza pasa por aqui.
 *
 * **Como lo consumen los demas modulos.** No importando esta interfaz —eso seria
 * `Attendance` dependiendo de `Compliance`, arista que el §1.6 no concede—, sino
 * por las dos vias que si estan concedidas: **publicando un evento de dominio**
 * que un listener de `Compliance/Infrastructure` recoge, o **invocando el caso
 * de uso publico** `RecordAuditEntry`. Los listeners de los eventos de fichaje
 * llegan con la tarea 1.4; los de credencial y dispositivo, con la 1.13 y la
 * 1.5.
 *
 * **Transaccionalidad.** `append()` NO abre transaccion propia: se une a la del
 * caso de uso que la llama. Es deliberado y es la mitad de la garantia: si la
 * escritura de auditoria falla, la accion auditada **no se confirma** (ADR-027,
 * consecuencia del particionado). Un fichaje que ocurre sin dejar traza es peor
 * que un fichaje que no ocurre, porque el segundo se puede corregir.
 *
 * **Serializacion de la cadena.** El eslabon anterior se lee y el nuevo se
 * escribe bajo un candado que el adaptador toma sobre la propia cadena. Sin el,
 * dos apuntes simultaneos leerian el mismo `prev_hash` y la cadena naceria
 * bifurcada: `compliance:verify-audit-chain` lo denunciaria al dia siguiente y
 * seria un falso positivo permanente.
 */
interface AuditTrail
{
    /**
     * Encadena y persiste el borrador. Devuelve la entrada sellada, ya con su
     * `id`, su `prev_hash` y su `hash`.
     */
    public function append(AuditEntryDraft $draft): AuditEntry;
}

<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Port;

use App\Modules\Identity\Domain\ValueObject\OriginAttemptHistory;
use App\Modules\Identity\Domain\ValueObject\RequestOrigin;
use Closure;

/**
 * Donde se guarda la cuenta de fallos por origen del portal (RS-12, ADR-050 §2).
 *
 * **Solo almacen.** Que cuenta como fallo, cuando se abre el bloqueo y cuanto
 * dura lo decide `OriginLockoutPolicy`, en el dominio; este puerto lee y escribe
 * el estado que la politica devuelve. Asi la regla se prueba sin cache y el
 * adaptador se prueba sin la regla.
 *
 * La implementacion vive sobre la cache `resilient`, la misma que el contador
 * del PIN: sin Redis sigue contando en el disco de la unica maquina que atiende
 * peticiones.
 */
interface PortalOriginAttempts
{
    public function historyFor(RequestOrigin $origin): OriginAttemptHistory;

    /**
     * Lee el estado del origen, le aplica `$transition` y guarda el resultado
     * durante `$ttlSeconds`, **sin que otro proceso del mismo origen pueda
     * meterse entre la lectura y la escritura**. Es lo que usa el caso de uso
     * para contar un fallo: con un `historyFor()` y un `save()` sueltos, los
     * fallos simultaneos se pisan y quien paraleliza no llega nunca al umbral.
     *
     * @param  Closure(OriginAttemptHistory): OriginAttemptHistory  $transition
     * @return array{OriginAttemptHistory, OriginAttemptHistory} El estado de antes y el de despues.
     */
    public function update(RequestOrigin $origin, Closure $transition, int $ttlSeconds): array;

    /**
     * Guarda el estado durante `$ttlSeconds`; pasado ese tiempo sin cambios, se
     * olvida. Sustituye lo que hubiera sin leerlo: para contar un fallo, {@see self::update()}.
     */
    public function save(RequestOrigin $origin, OriginAttemptHistory $history, int $ttlSeconds): void;

    /**
     * Borra la cuenta y el bloqueo de un origen (`identity:origin-unlock`).
     *
     * @return bool Si habia algo que borrar.
     */
    public function forget(RequestOrigin $origin): bool;

    /**
     * Anota que se ha abierto un bloqueo en la hora que empieza en `$hourStart`
     * y devuelve cuantos van en esa hora, este incluido. Es la cuenta global del
     * techo de asientos `auth.origin_locked` (`OriginLockAuditCeiling`).
     */
    public function countLockOpening(int $hourStart): int;
}

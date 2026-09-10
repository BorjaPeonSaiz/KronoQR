<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * La punta de la cadena de auditoria en un instante dado: la huella del ultimo
 * eslabon y, si lo hay, su identificador (RS-07, tarea 5.7).
 *
 * **Para que hace falta leerla.** Un asiento `system.*` lleva en su payload la
 * huella que el instalador verifico **antes** de tocar nada (`chain_before`) y,
 * en la actualizacion, la que verifico **despues** de migrar (`chain_after`).
 * Sin poder leer la punta, el instalador no puede escribir ninguna de las dos, y
 * el asiento de una vuelta atras se quedaria sin lo unico que prueba que se
 * descarto un intervalo: que la punta de antes de restaurar no es la de despues.
 *
 * **`lastEntryId` es nulo cuando la tabla viva esta vacia**, que no significa
 * que la instalacion sea nueva: puede que la retencion soltara la ultima
 * particion (ADR-027). El `hash` sigue estando —viene del ancla, o de la
 * genesis— porque la cadena no vuelve a empezar nunca.
 */
final readonly class AuditChainHeadSnapshot
{
    public function __construct(
        public string $hash,
        public ?int $lastEntryId = null,
    ) {}
}

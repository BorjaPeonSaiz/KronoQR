<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * Las tres formas en las que una cadena de auditoria se rompe. Se distinguen
 * porque **no se investigan igual**, y el runbook
 * `docs/runbooks/rotura-cadena-auditoria.md` las separa.
 */
enum AuditChainBreakKind: string
{
    /**
     * El contenido de la fila no produce el `hash` que lleva escrito: alguien
     * cambio un campo despues de escribirla. Es el caso del escenario ineludible
     * del doc 02 §9.4.
     */
    case ContentAltered = 'content_altered';

    /**
     * El `prev_hash` de la fila no es el `hash` de la anterior: se ha borrado,
     * insertado o reordenado algo entre las dos.
     */
    case BrokenLink = 'broken_link';

    /**
     * La cadena empieza por un `prev_hash` que no es la genesis y **tampoco
     * encaja con el `last_hash` de ningun ancla**: faltan filas por delante y
     * nadie registro que fuera a quitarlas (ADR-027).
     */
    case OrphanStart = 'orphan_start';
}

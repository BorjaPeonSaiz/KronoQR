<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

use RuntimeException;
use Throwable;

/**
 * Un fallo de la base de datos ya traducido por el adaptador: su clase
 * ({@see DatabaseFailureKind}) y, si el motor lo dio, su `SQLSTATE`, solo para
 * el log. El fallo original va en `getPrevious()`.
 *
 * Existe para que `Application` no conozca los tipos del driver ni lea
 * `SQLSTATE`: lo hace el adaptador de
 * {@see DatabaseFailureTranslator}, y una
 * prueba de arquitectura impide que vuelvan a aparecer en `Application`.
 *
 * **El mensaje no lleva nada del original**: el de una consulta fallida trae
 * el SQL con los valores enlazados (regla dura 21).
 */
final class DatabaseFailure extends RuntimeException
{
    private function __construct(
        public readonly DatabaseFailureKind $kind,
        public readonly ?string $sqlState,
        Throwable $previous,
    ) {
        parent::__construct('La base de datos rechazo la operacion ('.$kind->value.').', previous: $previous);
    }

    public static function of(DatabaseFailureKind $kind, ?string $sqlState, Throwable $previous): self
    {
        return new self($kind, $sqlState, $previous);
    }
}

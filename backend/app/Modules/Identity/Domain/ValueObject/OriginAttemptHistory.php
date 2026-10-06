<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\ValueObject;

/**
 * Lo que se sabe de un origen: sus fallos recientes y, si lo hay, hasta cuando
 * dura su bloqueo (RS-12, ADR-050 §2).
 *
 * Instantes en **segundos Unix**, que es lo que guarda la cache. El dominio no
 * lee ningun reloj: los recibe (regla dura 2).
 */
final readonly class OriginAttemptHistory
{
    /**
     * @param  list<int>  $failures  Instantes de los fallos dentro de la ventana, del mas antiguo al mas reciente.
     * @param  int|null  $lockedUntil  Fin del bloqueo, o `null` si no hay ninguno abierto.
     */
    public function __construct(
        public array $failures = [],
        public ?int $lockedUntil = null,
    ) {}

    public static function empty(): self
    {
        return new self;
    }
}

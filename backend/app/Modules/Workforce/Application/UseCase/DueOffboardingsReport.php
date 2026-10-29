<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

/**
 * El resumen de una pasada de la tarea de las bajas programadas (ADR-062 §4).
 *
 * Solo recuentos y la fecha del centro: ninguna persona (regla dura 21). Es lo
 * que la orden imprime y deja en el log.
 */
final readonly class DueOffboardingsReport
{
    public function __construct(
        public bool $ranOverASite,
        public ?string $today,
        public int $applied,
        public int $deferred,
        public int $failed,
        public int $overdue,
    ) {}

    public static function withoutSite(): self
    {
        return new self(false, null, 0, 0, 0, 0);
    }
}

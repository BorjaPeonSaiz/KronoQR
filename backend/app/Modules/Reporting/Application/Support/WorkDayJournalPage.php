<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Support;

use App\Modules\Reporting\Domain\ValueObject\WorkDayJournal;

/**
 * Una pagina del registro horario de una persona (RF-PA-03).
 *
 * El registro trae **el rango pedido** —no el de la pagina— y solo las
 * jornadas de la pagina: es lo que la pantalla enseña como «del 1 de enero al
 * 31 de diciembre, pagina 3 de 12». `total` cuenta las jornadas con actividad
 * de todo el rango.
 */
final readonly class WorkDayJournalPage
{
    public function __construct(
        public WorkDayJournal $journal,
        public int $page,
        public int $perPage,
        public int $total,
    ) {}

    /** Cero sin jornadas: no hay ninguna pagina que enseñar. */
    public function totalPages(): int
    {
        return (int) ceil($this->total / max(1, $this->perPage));
    }
}

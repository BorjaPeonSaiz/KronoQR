<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Se ha cargado la plantilla desde un fichero (**RF-GP-05**, regla dura 6).
 *
 * ## Por que un asiento del LOTE y no solo los de cada alta
 *
 * Porque son dos preguntas distintas. Cada alta individual ya deja su rastro por
 * su propio camino; lo que este asiento responde es «¿quien cargo la plantilla,
 * cuando, y con que fichero?», que es lo que se pregunta cuando aparecen doce
 * personas que nadie recuerda haber dado de alta. Sin el, esa respuesta habria
 * que reconstruirla correlacionando doce asientos por su marca de tiempo.
 *
 * ## Lo que viaja, y lo que no
 *
 * Las cifras y la huella del fichero. **Ni un nombre, ni un correo, ni un
 * documento, ni el nombre del fichero** (regla dura 21): el nombre lo pone quien
 * sube y puede llevar dentro el de una persona («plantilla_ana_revisada.xlsx»),
 * y el asiento acaba en la exportacion de auditoria.
 *
 * La huella si, y es util de verdad: permite afirmar, meses despues, que el
 * fichero que alguien conserva es exactamente el que se cargo.
 */
final readonly class EmployeesImported implements DomainEvent
{
    public function __construct(
        public string $fileSha256,
        public int $created,
        public int $updated,
        public int $unchanged,
        public int $rejected,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventName(): string
    {
        return 'workforce.employees_imported';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}

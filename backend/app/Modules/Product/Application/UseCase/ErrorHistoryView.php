<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

use App\Modules\Product\Domain\ValueObject\ErrorEventPage;
use DateTimeImmutable;

/**
 * La pagina del historico **mas lo que hace falta para pintarla**: la zona del
 * centro y el reloj del servidor (RF-PD-15, esquema `ErrorEventPageMeta`).
 *
 * Es el hermano de `IncidentBoardView` y existe por lo mismo. La pantalla pinta
 * la antiguedad de cada grupo —«visto por ultima vez hace tres horas»— y esa
 * cuenta se hace contra `generatedAt`, no contra el reloj del navegador: un
 * portatil con la hora mal puesta escribiria «hace 3 horas» sobre algo de
 * anteayer, y quien lo lee decide con eso a que atiende primero.
 *
 * La zona viaja por lo mismo que en el resto de la API (regla dura 3): todos los
 * instantes salen en UTC y el cliente no adivina la zona ni usa la suya.
 */
final readonly class ErrorHistoryView
{
    public function __construct(
        public ErrorEventPage $page,
        public string $timeZone,
        public DateTimeImmutable $generatedAt,
    ) {}
}

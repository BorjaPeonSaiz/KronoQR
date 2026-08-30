<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\UseCase;

use App\Modules\Compliance\Application\Port\IncidentBoardPage;
use DateTimeImmutable;

/**
 * Lo que {@see ReadIncidentBoard} devuelve: la pagina mas el marco en el que se
 * lee (RF-PA-05).
 *
 * **La zona y el instante no son de la pagina y por eso no estan en ella.** La
 * pagina la produce el adaptador de persistencia, que no sabe ni que hora es
 * —eso viene del puerto `Clock`— ni en que zona vive el centro. Meterlos ahi
 * habria obligado al adaptador a conocer las dos cosas para responder a una
 * consulta que no depende de ninguna.
 *
 * Los dos existen por lo mismo: la bandeja pinta la **antiguedad** de cada
 * incidencia, y esa cuenta se hace contra el reloj del servidor y se muestra en
 * la zona del centro, nunca con lo que diga el navegador (regla dura 3).
 */
final readonly class IncidentBoardView
{
    public function __construct(
        public IncidentBoardPage $page,
        /** Zona del centro de la instalacion. Hay exactamente uno (ADR-040). */
        public string $timeZone,
        /** Reloj del servidor al responder, en UTC. Del puerto `Clock`, nunca de `now()`. */
        public DateTimeImmutable $generatedAt,
    ) {}
}

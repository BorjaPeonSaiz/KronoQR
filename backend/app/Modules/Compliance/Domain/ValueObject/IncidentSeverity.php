<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * Con que urgencia entra una incidencia en la bandeja (`incidents.severity`,
 * doc 01 §5.5).
 *
 * Tres niveles y no cinco: la severidad solo sirve si cambia el orden en que
 * alguien mira la bandeja, y nadie distingue de verdad entre cinco prioridades a
 * las seis de la manana. El reparto lo decide {@see IncidentType::defaultSeverity()},
 * que es donde esta escrito el criterio.
 *
 * Es tambien una etiqueta de la metrica `incidents_open{type,severity}` (doc 02
 * §8.2), asi que sus valores son de cardinalidad fija a proposito.
 */
enum IncidentSeverity: string
{
    /** El registro es valido y el dato es raro. Se mira cuando se puede. */
    case Low = 'low';

    /** El registro esta incompleto o no cuadra: hay que corregirlo con traza (RN-13). */
    case Medium = 'medium';

    /** Se ha incumplido una norma con consecuencia sancionadora, o hay un indicio sobre una persona. */
    case High = 'high';
}

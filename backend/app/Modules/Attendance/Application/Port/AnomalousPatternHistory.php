<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Port;

use App\Modules\Attendance\Domain\ValueObject\PatternReviewState;

/**
 * Que se ha hecho ya con los indicios de patron de cada persona (RF-PR-06,
 * decision 13c de la ficha 3.11).
 *
 * ## Por que la deteccion tiene que mirar la bandeja
 *
 * Para el resto de hallazgos no hace falta: describen una jornada concreta y
 * `one_incident_per_finding` los deduplica sola. Un patron describe un
 * **habito**, y su jornada avanza cada noche: sin esto, las mismas dos personas
 * coincidiendo cada manana abrian una incidencia `high` nueva cada madrugada y
 * la bandeja que el runbook manda vaciar crecia sola. Es el hallazgo R-1 de la
 * revision de seguridad.
 *
 * ## Se lee UNA vez por pasada
 *
 * Una consulta con todos los empleados que aparecen en los escaneos de la
 * ventana, no una por persona. El estado entra en la politica **ya resuelto**
 * (regla dura 14): el dominio no consulta la bandeja, igual que no consulta la
 * configuracion.
 *
 * ## Solo dice «que hay», no «que hacer»
 *
 * Devuelve el estado; quien decide si eso silencia un hallazgo o recorta el
 * recuento es `CredentialPatternPolicy`, que es donde la regla se lee y se
 * prueba. Un puerto que devolviera «¿emito o no?» tendria la regla escrita en
 * una consulta SQL.
 */
interface AnomalousPatternHistory
{
    /**
     * El estado de revision de cada persona para un patron concreto.
     *
     * **Devuelve una entrada por cada UUID pedido**, tambien para quien no tiene
     * ninguna incidencia: un mapa con huecos obliga a quien lo lee a decidir que
     * significa la ausencia, y esa decision ya esta tomada
     * ({@see PatternReviewState::untouched()}).
     *
     * @param  list<string>  $employeeUuids  Identificadores publicos, nunca claves internas.
     * @param  string  $pattern  Valor de `incidents.context->>'pattern'`.
     * @return array<string, PatternReviewState> Indexado por UUID de empleado.
     */
    public function forEmployees(array $employeeUuids, string $pattern): array;
}

<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Port;

use App\Modules\Compliance\Domain\ValueObject\WorkRecordReconciliationResult;
use DateTimeImmutable;

/**
 * Publicacion del resultado de la conciliacion entre el registro horario y su
 * auditoria (ADR-057 §4).
 *
 * **Se publica siempre, tambien sin discrepancias.** Una serie que desaparece se
 * lee igual que una que nunca fallo, y la regla que avisa de que la conciliacion
 * no ha corrido mira la marca de la ultima ejecucion. Sin esa marca, apagar la
 * tarea programada seria la forma mas comoda de silenciar la alerta.
 *
 * Una serie por alcance (`recent`, `full`): la pasada diaria no puede apagar lo
 * que encontro la semanal.
 */
interface WorkRecordReconciliationMetrics
{
    public function record(WorkRecordReconciliationResult $result, DateTimeImmutable $at): void;
}

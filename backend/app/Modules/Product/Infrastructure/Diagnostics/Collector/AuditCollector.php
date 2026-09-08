<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics\Collector;

use App\Modules\Product\Application\Port\DiagnosticsCollector;
use App\Modules\Product\Domain\ValueObject\DiagnosticsOptions;
use App\Modules\Product\Infrastructure\Diagnostics\ServiceInspector;
use App\Modules\Shared\Application\Port\Clock;

/**
 * Seccion `audit`: **solo recuentos** de `audit_log` y el estado de la cadena
 * (doc 02 §11.6.6, ADR-020).
 *
 * ## Ningun payload, y la razon es concreta
 *
 * Los asientos de la familia `license_lifecycle` llevan la razon social del
 * cliente y los de `personal_data_access` llevan **a quien se consulto**. Un
 * volcado de asientos convertiria el paquete anonimizado en un fichero con
 * nombres de empleados dentro, que es exactamente lo que ADR-020 y la regla dura
 * 21 prohiben.
 *
 * Lo que si responde: «¿esta escribiendo la cadena?», «¿hubo un pico de
 * correcciones el martes?» y «¿el ultimo año sellado sigue verificando?». Tres
 * preguntas utiles con cero datos personales.
 */
final readonly class AuditCollector implements DiagnosticsCollector
{
    /** Ventana del recuento por dia. Un mes cubre la incidencia y su antecedente. */
    private const int WINDOW_DAYS = 30;

    public function __construct(
        private ServiceInspector $services,
        private Clock $clock,
    ) {}

    public function section(): string
    {
        return 'audit';
    }

    public function collect(DiagnosticsOptions $options): array
    {
        return $this->services->auditTally(self::WINDOW_DAYS, $this->clock->now());
    }
}

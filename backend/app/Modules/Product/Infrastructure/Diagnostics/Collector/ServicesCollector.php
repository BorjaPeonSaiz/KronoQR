<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics\Collector;

use App\Modules\Product\Application\Port\DiagnosticsCollector;
use App\Modules\Product\Domain\ValueObject\DiagnosticsOptions;
use App\Modules\Product\Infrastructure\Diagnostics\ServiceInspector;

/**
 * Seccion `services`: si los cuatro servicios de los que depende el producto
 * estan vivos y en que estado (doc 02 §11.6.6).
 *
 * Base de datos, Redis, cola y Reverb, mas el tamaño del `audit_log` y la fecha
 * de su ultimo asiento. Es la seccion que descarta media docena de hipotesis en
 * los primeros treinta segundos de una incidencia.
 *
 * ## Del `audit_log` sale el TAMAÑO y la FECHA, jamas un asiento
 *
 * Los asientos de `license_lifecycle` llevan la razon social del cliente y los
 * de `personal_data_access` llevan a quien se consulto. La cuenta de filas y el
 * instante del ultimo responden a «¿esta escribiendo la cadena?» sin sacar nada
 * de ella (ADR-020).
 *
 * ## Ninguna comprobacion propaga su excepcion
 *
 * Un servicio caido es exactamente el caso en el que este paquete se genera. La
 * mecanica esta en {@see ServiceInspector}, compartida con las sondas de
 * `doctor` para que el paquete y el informe no puedan discrepar.
 */
final readonly class ServicesCollector implements DiagnosticsCollector
{
    public function __construct(private ServiceInspector $services) {}

    public function section(): string
    {
        return 'services';
    }

    public function collect(DiagnosticsOptions $options): array
    {
        return [
            'database' => $this->services->database(),
            'redis' => $this->services->redis(),
            'queue' => $this->services->queue(),
            'realtime' => $this->services->realtime(),
            'audit_log' => $this->services->auditLog(),
        ];
    }
}

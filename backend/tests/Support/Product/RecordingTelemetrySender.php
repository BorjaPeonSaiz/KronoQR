<?php

declare(strict_types=1);

namespace Tests\Support\Product;

use App\Modules\Product\Application\Port\TelemetrySender;
use App\Modules\Product\Domain\ValueObject\TelemetryDelivery;
use App\Modules\Product\Domain\ValueObject\TelemetryReport;

/**
 * El envio de telemetria, **anotando cada llamada** (RF-PD-12).
 *
 * Esa cuenta es la prueba entera de la garantia principal de la tarea: con la
 * telemetria apagada no se construye ni se envia nada. Comprobar solo que no
 * hubo peticion HTTP dejaria pasar una implementacion que recorre las tablas, lee
 * Redis y ejecuta `doctor` cada semana en una instalacion que jamas quiso
 * telemetria. Aqui se comprueba que **nadie toca nada**.
 */
final class RecordingTelemetrySender implements TelemetrySender
{
    /** @var list<array{report: TelemetryReport, endpoint: string}> */
    public array $sent = [];

    public function __construct(private readonly bool $delivers = true) {}

    public function send(TelemetryReport $report, string $endpoint): TelemetryDelivery
    {
        $this->sent[] = ['report' => $report, 'endpoint' => $endpoint];

        return $this->delivers
            ? TelemetryDelivery::delivered(202, 1)
            : TelemetryDelivery::failed('Tests\Support\Product\Unreachable', 2);
    }
}

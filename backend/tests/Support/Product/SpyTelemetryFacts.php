<?php

declare(strict_types=1);

namespace Tests\Support\Product;

use App\Modules\Product\Application\Port\TelemetryFacts;

/**
 * Los tres datos de base de datos del informe, sin base de datos, y contando las
 * llamadas (RF-PD-12). Ver {@see SpyTelemetryCounters}.
 */
final class SpyTelemetryFacts implements TelemetryFacts
{
    public int $calls = 0;

    public function __construct(
        private readonly ?string $version = '17.11',
        private readonly int $departments = 4,
        private readonly ?int $incidents = 0,
    ) {}

    public function databaseVersion(): ?string
    {
        $this->calls++;

        return $this->version;
    }

    public function departments(): int
    {
        $this->calls++;

        return $this->departments;
    }

    public function openIncidents(): ?int
    {
        $this->calls++;

        return $this->incidents;
    }
}

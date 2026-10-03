<?php

// Casos de prueba de las reglas de kronoqr-php.yaml (`semgrep --test .semgrep`).
// No es codigo del producto: cada linea marcada con `ruleid:` tiene que
// disparar la regla y cada `ok:` no.

declare(strict_types=1);

namespace App\SemgrepFixture;

use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

final class LogContextFixture
{
    public function __construct(private LoggerInterface $logger) {}

    public function run(object $employee, object $user): void
    {
        // ruleid: kronoqr-log-con-datos-de-persona
        Log::info('workforce.created', ['name' => 'x', 'employee_uuid' => 'u']);

        // ruleid: kronoqr-log-con-datos-de-persona
        $this->logger->warning('workforce.updated', ['display_name' => 'x']);

        // ruleid: kronoqr-log-con-datos-de-persona
        $this->logger->error('workforce.updated', ['nombre' => 'x']);

        // ruleid: kronoqr-log-con-datos-de-persona
        $this->logger->info('x', ['who' => $employee->first_name]);

        // ruleid: kronoqr-log-con-datos-de-persona
        $this->logger->info('x', ['who' => $user->resolvedByName]);

        // ruleid: kronoqr-log-con-datos-de-persona
        $this->logger->log('error', 'x', ['correo' => 'x']);

        // ok: kronoqr-log-con-datos-de-persona
        $this->logger->info('attendance.scan_processed', ['employee_uuid' => $employee->uuid]);

        // ok: kronoqr-log-con-datos-de-persona
        Log::info('product.error_history_resanitized', ['rows' => 2, 'merged' => 1]);
    }
}

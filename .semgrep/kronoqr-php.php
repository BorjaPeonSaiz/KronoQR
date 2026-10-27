<?php

// Casos de prueba de las reglas de kronoqr-php.yaml (`semgrep --test .semgrep`).
// No es codigo del producto: cada linea marcada con `ruleid:` tiene que
// disparar la regla y cada `ok:` no.

declare(strict_types=1);

namespace App\SemgrepFixture;

use DateTime;
use DateTimeImmutable;
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

        // ruleid: kronoqr-phpstan-ignore-line
        $a = (string) $employee; // @phpstan-ignore-line cast.string (motivo)

        // ruleid: kronoqr-phpstan-ignore-line
        // @phpstan-ignore-next-line cast.string (motivo)

        // ok: kronoqr-phpstan-ignore-line
        $b = (string) $user; // @phpstan-ignore cast.string (motivo)
    }
}

final class DomainClockFixture
{
    public function run(string $isoDate, \DateTimeZone $zone): void
    {
        // ruleid: kronoqr-domain-lee-el-reloj-del-sistema
        $a = new \DateTimeImmutable();

        // ruleid: kronoqr-domain-lee-el-reloj-del-sistema
        $b = new DateTimeImmutable('now', $zone);

        // ruleid: kronoqr-domain-lee-el-reloj-del-sistema
        $c = new \DateTimeImmutable('today');

        // ruleid: kronoqr-domain-lee-el-reloj-del-sistema
        $d = new DateTime("midnight", $zone);

        // ruleid: kronoqr-domain-lee-el-reloj-del-sistema
        $e = new DateTimeImmutable('+1 day');

        // ruleid: kronoqr-domain-lee-el-reloj-del-sistema
        $f = new DateTimeImmutable('08:00', $zone);

        // ruleid: kronoqr-domain-lee-el-reloj-del-sistema
        $g = date_create();

        // ruleid: kronoqr-domain-lee-el-reloj-del-sistema
        $h = \date_create_immutable($isoDate);

        // ok: kronoqr-domain-lee-el-reloj-del-sistema
        $i = new DateTimeImmutable('2026-03-29 00:00:00', $zone);

        // ok: kronoqr-domain-lee-el-reloj-del-sistema
        $j = new DateTimeImmutable('@1700000000');

        // ruleid: kronoqr-domain-createfromformat-sin-exclamacion
        $k = DateTimeImmutable::createFromFormat('Y-m-d', $isoDate, $zone);

        // ruleid: kronoqr-domain-createfromformat-sin-exclamacion
        $l = \DateTime::createFromFormat("Y-m-d H:i", $isoDate);

        // ok: kronoqr-domain-createfromformat-sin-exclamacion
        $m = DateTimeImmutable::createFromFormat('!Y-m-d', $isoDate, $zone);

        // ok: kronoqr-domain-createfromformat-sin-exclamacion
        $n = DateTimeImmutable::createFromFormat('Y-m-d|', $isoDate);

        // ok: kronoqr-domain-createfromformat-sin-exclamacion
        $o = DateTimeImmutable::createFromFormat('U', $isoDate);

        // ruleid: kronoqr-fecha-civil-concatenada-con-medianoche
        $p = new DateTimeImmutable($isoDate.' 00:00:00', $zone);

        // ruleid: kronoqr-fecha-civil-concatenada-con-medianoche
        $q = new \DateTime(substr($isoDate, 0, 10).'T00:00', $zone);

        // ok: kronoqr-fecha-civil-concatenada-con-medianoche
        $r = new DateTimeImmutable($isoDate.' 08:30:00', $zone);

        // ok: kronoqr-fecha-civil-concatenada-con-medianoche
        $s = new DateTimeImmutable($isoDate, $zone);
    }

    // ruleid: kronoqr-phpstan-ignore-sin-justificacion
    /** @phpstan-ignore return.type */
    public function sinMotivoEnBloque(): void {}

    // ruleid: kronoqr-phpstan-ignore-sin-justificacion
    /* @phpstan-ignore argument.type */
    public function sinMotivoEnComentario(): void {}

    // ok: kronoqr-phpstan-ignore-sin-justificacion
    /** @phpstan-ignore return.type (la firma de la libreria miente: devuelve null) */
    public function conMotivoEnBloque(): void {}
}

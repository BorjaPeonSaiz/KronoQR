<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics\Probe;

use App\Modules\Product\Application\Port\DoctorProbe;
use App\Modules\Product\Domain\ValueObject\DoctorFinding;
use App\Modules\Product\Infrastructure\Diagnostics\TextfileMetricsReader;
use App\Modules\Shared\Application\Port\Clock;
use DateTimeImmutable;

/**
 * Sonda `scheduler.heartbeat` de `product:doctor` (RF-PD-13; V3-PL-07 de la
 * verificacion final de la 2.2.0).
 *
 * ## El latido que ya existia
 *
 * El planificador (`scheduler`) no deja ninguna marca propia, pero lanza cada
 * minuto `backup:wal-metrics`, que escribe
 * `kronoqr_wal_exporter_last_run_timestamp_seconds` en `kronoqr_wal.prom`. Es
 * la serie que vigila la alerta `MedicionDeWalAusente`, con el mismo umbral de
 * 5 minutos. Leerla es abrir un fichero de unos cientos de bytes.
 *
 * ## Por que `warning` y no `failure`
 *
 * Porque el latido es **indirecto**: tambien se para si el planificador corre
 * pero `wal-metrics.sh` no puede leer PostgreSQL con el rol de copias. El texto
 * nombra las dos causas y el comando para distinguirlas, en lugar de afirmar la
 * que no puede saber. La consecuencia grave —la copia nocturna que no se hace—
 * la convierte en `failure` `backup.last_good_copy` en cuanto pasan 26 h.
 */
final readonly class SchedulerProbe implements DoctorProbe
{
    /** 5 min: el mismo umbral que `MedicionDeWalAusente`. La tarea corre cada minuto. */
    public const int MAX_HEARTBEAT_AGE_SECONDS = 300;

    public const string FILE = 'kronoqr_wal.prom';

    public function __construct(
        private TextfileMetricsReader $metrics,
        private Clock $clock,
    ) {}

    public function family(): string
    {
        return 'scheduler';
    }

    public function run(): array
    {
        $id = 'scheduler.heartbeat';
        $details = ['path' => $this->metrics->path(self::FILE)];

        if ($this->metrics->state(self::FILE) === 'unreadable') {
            return [DoctorFinding::warning($id, 'unknown', ['path' => $details['path']], $details)];
        }

        $lastRun = $this->metrics->value(self::FILE, 'kronoqr_wal_exporter_last_run_timestamp_seconds');

        if ($lastRun === null || $lastRun <= 0.0) {
            return [DoctorFinding::warning($id, 'never', details: $details)];
        }

        $timestamp = (int) $lastRun;
        $age = max(0, $this->clock->now()->getTimestamp() - $timestamp);
        $details += ['last_run_at' => $timestamp, 'age_seconds' => $age];
        $params = [
            'minutes' => intdiv($age, 60),
            // Etiquetada como UTC en el texto (regla dura 3).
            'last_run_at' => new DateTimeImmutable('@'.$timestamp)->format('Y-m-d H:i'),
        ];

        if ($age > self::MAX_HEARTBEAT_AGE_SECONDS) {
            return [DoctorFinding::warning($id, params: $params, details: $details)];
        }

        return [DoctorFinding::ok($id, $details, $params)];
    }
}

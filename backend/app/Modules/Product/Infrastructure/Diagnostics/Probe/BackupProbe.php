<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics\Probe;

use App\Modules\Product\Application\Port\DoctorProbe;
use App\Modules\Product\Domain\ValueObject\DoctorFinding;
use App\Modules\Product\Infrastructure\Diagnostics\TextfileMetricsReader;
use App\Modules\Shared\Application\Port\Clock;
use DateTimeImmutable;

/**
 * Sonda `backup.last_good_copy` de `product:doctor` (RF-PD-13, RNF-D-02, RL-12;
 * V3-PL-07 de la verificacion final de la 2.2.0).
 *
 * ## Que faltaba
 *
 * `permissions.backup_*` comprueba que las copias **se pueden** escribir, no que
 * **se esten** haciendo. Con el planificador parado o la copia nocturna fallando
 * cada noche, `doctor` salia en verde: el unico aviso eran las alertas, y en una
 * instalacion sin Alertmanager configurado no llega a nadie.
 *
 * ## De donde sale el dato
 *
 * De los mismos ficheros que leen las alertas de
 * `infra/observability/prometheus/rules/backup.yml`, con el mismo umbral:
 *
 * - `kronoqr_backup_run.prom` → `kronoqr_backup_last_result` (alerta
 *   `CopiaDeSeguridadFallida`): la ultima copia, diaria o semanal, fallo.
 * - `kronoqr_backup_verify.prom` → `kronoqr_backup_last_verify_result` y
 *   `kronoqr_backup_last_verified_timestamp_seconds` (alerta
 *   `CopiaDeSeguridadSinVerificar`): la ultima verificacion fallo, o la ultima
 *   buena tiene mas de 26 h. Una copia sin verificar no cuenta: es la que
 *   `backup.sh` publica solo cuando pasa la verificacion.
 *
 * Sin ningun fichero sale `warning` y no `failure`: es lo normal entre la
 * instalacion y la primera copia nocturna, e `install.sh` se para con un `2`.
 *
 * El reloj es el puerto {@see Clock}: la antiguedad de la copia es justo lo que
 * una prueba tiene que poder fijar.
 */
final readonly class BackupProbe implements DoctorProbe
{
    /** 26 h: la copia nocturna mas su margen. El mismo umbral que `CopiaDeSeguridadSinVerificar`. */
    public const int MAX_VERIFIED_AGE_SECONDS = 93600;

    public const string RUN_FILE = 'kronoqr_backup_run.prom';

    public const string VERIFY_FILE = 'kronoqr_backup_verify.prom';

    /**
     * @param  string  $dailyAt  La hora (UTC) de la copia diaria, `backup.daily_at`: el texto la cita.
     */
    public function __construct(
        private TextfileMetricsReader $metrics,
        private Clock $clock,
        private string $dailyAt,
    ) {}

    public function family(): string
    {
        return 'backup';
    }

    public function run(): array
    {
        return [$this->lastGoodCopy()];
    }

    private function lastGoodCopy(): DoctorFinding
    {
        $id = 'backup.last_good_copy';
        $details = [
            'path' => $this->metrics->path(self::VERIFY_FILE),
            'max_age_hours' => intdiv(self::MAX_VERIFIED_AGE_SECONDS, 3600),
        ];
        $params = ['daily_at' => $this->dailyAt];

        foreach ([self::RUN_FILE, self::VERIFY_FILE] as $file) {
            if ($this->metrics->state($file) === 'unreadable') {
                return DoctorFinding::warning($id, 'unknown', ['path' => $this->metrics->path($file)], $details);
            }
        }

        $lastResult = $this->metrics->value(self::RUN_FILE, 'kronoqr_backup_last_result');
        $verifyResult = $this->metrics->value(self::VERIFY_FILE, 'kronoqr_backup_last_verify_result');
        $verifiedAt = $this->metrics->value(self::VERIFY_FILE, 'kronoqr_backup_last_verified_timestamp_seconds');

        $details += [
            'last_result' => $lastResult,
            'last_verify_result' => $verifyResult,
            'last_verified_at' => $verifiedAt,
        ];

        if ($lastResult !== null && $lastResult < 1.0) {
            return DoctorFinding::failure($id, 'last_failed', $params, $details);
        }

        if ($verifyResult !== null && $verifyResult < 1.0) {
            return DoctorFinding::failure($id, 'verify_failed', $params, $details);
        }

        if ($verifiedAt === null || $verifiedAt <= 0.0) {
            return DoctorFinding::warning($id, 'never', $params, $details);
        }

        $timestamp = (int) $verifiedAt;
        $age = max(0, $this->clock->now()->getTimestamp() - $timestamp);
        $params += [
            'hours' => intdiv($age, 3600),
            // Etiquetada como UTC en el texto, como `queue.worker`: el
            // contenedor vive en UTC (regla dura 3).
            'verified_at' => new DateTimeImmutable('@'.$timestamp)->format('Y-m-d H:i'),
        ];

        if ($age > self::MAX_VERIFIED_AGE_SECONDS) {
            return DoctorFinding::failure($id, 'stale', $params, $details);
        }

        return DoctorFinding::ok($id, $details, $params);
    }
}

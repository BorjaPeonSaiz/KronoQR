<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics\Probe;

use App\Modules\Product\Application\Port\DoctorProbe;
use App\Modules\Product\Domain\ValueObject\DoctorFinding;
use App\Modules\Product\Infrastructure\Diagnostics\TextfileMetricsReader;

/**
 * Sonda `backup.offsite` de `product:doctor`: ¿las copias salen del servidor?
 * (V5-DV-05; RF-PD-13, RF-PD-15, RL-12, RL-14).
 *
 * ## Por que existe
 *
 * La promesa de perder como mucho 15 minutos (doc 05) vale «siempre que las
 * copias y el registro continuo de cambios salgan del servidor». Si
 * `BACKUP_PATH` vive en el mismo disco que los datos de PostgreSQL y de Docker,
 * el dia que ese disco se pierde se pierden la base y todas las copias juntas, y
 * nada lo detectaba: las copias se hacen, se verifican y hasta se restauran.
 *
 * ## De donde sale el dato
 *
 * Esta sonda corre dentro de `app`, que no ve los discos del anfitrion. La medida
 * la hace `backup.sh` (lib/offsite.sh) desde `scheduler` y la deja en
 * `kronoqr_backup_run.prom`, junto al resultado de la copia:
 *
 * - `kronoqr_backup_destination_offsite`: `BACKUP_PATH`.
 * - `kronoqr_backup_wal_offsite`: `BACKUP_PATH/wal`, donde archiva PostgreSQL.
 *
 * Valen 1 (red, o dispositivo distinto del de los datos), 0 (el mismo
 * dispositivo) o -1 (no se pudo medir). `./doctor.sh`, desde el anfitrion, hace
 * la medida completa contra los datos de PostgreSQL.
 *
 * ## Es un aviso, nunca un fallo
 *
 * El cliente puede sacar las copias por su cuenta (rsync, cinta, nube europea) y
 * esto no puede saberlo: un fallo daria un `doctor` en rojo, y un `update.sh`
 * parado, en una instalacion que no tiene nada roto. Por eso solo hay `ok` y
 * `warning`.
 */
final readonly class BackupOffsiteProbe implements DoctorProbe
{
    public const string RUN_FILE = BackupProbe::RUN_FILE;

    public const string DESTINATION_METRIC = 'kronoqr_backup_destination_offsite';

    public const string WAL_METRIC = 'kronoqr_backup_wal_offsite';

    /**
     * @param  string  $dailyAt  La hora (UTC) de la copia diaria, `backup.daily_at`: el texto la cita.
     */
    public function __construct(
        private TextfileMetricsReader $metrics,
        private string $dailyAt,
    ) {}

    public function family(): string
    {
        return 'backup';
    }

    public function run(): array
    {
        $id = 'backup.offsite';
        $destination = $this->metrics->value(self::RUN_FILE, self::DESTINATION_METRIC);
        $wal = $this->metrics->value(self::RUN_FILE, self::WAL_METRIC);
        $params = ['daily_at' => $this->dailyAt];
        $details = [
            'path' => $this->metrics->path(self::RUN_FILE),
            'destination_offsite' => $destination,
            'wal_offsite' => $wal,
        ];

        if ($destination !== null && $destination === 0.0) {
            return [DoctorFinding::warning($id, 'same_disk', $params, $details)];
        }

        if ($wal !== null && $wal === 0.0) {
            return [DoctorFinding::warning($id, 'wal_same_disk', $params, $details)];
        }

        if ($destination === null || $wal === null || $destination < 0.0 || $wal < 0.0) {
            return [DoctorFinding::warning($id, 'unknown', $params, $details)];
        }

        return [DoctorFinding::ok($id, $details, $params)];
    }
}

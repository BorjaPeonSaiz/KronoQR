<?php

declare(strict_types=1);

namespace App\Console\Commands\Backup;

use Illuminate\Console\Command;

/**
 * `php artisan backup:wal-metrics` — mide cada minuto cuanto WAL esta sin
 * archivar (RNF-D-02, RL-12; bloque 20 de la 2.2.0, hallazgo R5-DV-01).
 *
 * ## Por que existe
 *
 * Hasta la 2.1.0 la edad del ultimo WAL archivado la publicaba `backup.sh` al
 * terminar la copia de las 03:15: una foto al dia, no una serie. Si el
 * archivado se paraba a las 10:00, la alerta no lo veia hasta la madrugada
 * siguiente, y el RPO de 15 minutos prometido era una afirmacion sin medida.
 * Lo que mide `infra/scripts/wal-metrics.sh` es la exposicion real —cuanto
 * lleva sin protegerse el dato mas antiguo no archivado, incluido el segmento
 * en curso— y la escribe en `BACKUP_PATH/metrics/kronoqr_wal.prom` para
 * `node-exporter`.
 *
 * ## Delgado a proposito, como `backup:run`
 *
 * Toda la medida vive en el script, que se conecta con el rol de copia
 * `fichaje_backup` que el `scheduler` ya tiene y sin privilegios nuevos
 * (`pg_current_wal_insert_lsn()`, `pg_stat_archiver`, `SHOW archive_timeout`).
 * Este comando solo lo localiza, le pone un tiempo maximo y devuelve su codigo.
 *
 * ## Un fallo no es un incidente del planificador
 *
 * Si la base no responde o `metrics/` no se puede escribir, el script sale con
 * su codigo y el comando lo devuelve tal cual, sin mas. Lo que avisa es la
 * alerta `MedicionDeWalAusente`, que mira el latido del propio fichero: un
 * error por minuto en `error_events` no diria nada que la alerta no diga ya.
 */
final class WalMetricsCommand extends Command
{
    /**
     * Tiempo maximo de una medida. Se ejecuta cada minuto: una medida que no
     * termina en 50 s es una base que no responde, y la siguiente lo reintenta.
     */
    private const float TIMEOUT_SECONDS = 50.0;

    protected $signature = 'backup:wal-metrics';

    protected $description = 'Publica cuanto WAL esta sin archivar y desde cuando, para la alerta del RPO (RNF-D-02)';

    public function handle(): int
    {
        return (new BackupScript($this))->run('wal-metrics.sh', [], self::TIMEOUT_SECONDS);
    }
}

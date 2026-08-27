<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Console;

use App\Modules\Compliance\Application\UseCase\EnsureAuditLogPartitions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * `php artisan compliance:ensure-audit-partitions` — se asegura de que
 * `audit_log` tiene particion donde caer (ADR-027).
 *
 * **Por que es una tarea programada y no un apaño anual.** Un `INSERT` cuyo
 * `occurred_at` no cae en ninguna particion falla, y un fallo al escribir
 * auditoria **tumba la accion auditada**: el 1 de enero a las 00:00 —turno de
 * noche, hotel lleno— nadie podria fichar. No puede quedarse en silencio, pero
 * sobre todo no puede llegar a ocurrir.
 *
 * **Crea a partir de noviembre la del año siguiente**, con dos meses de margen,
 * y **si falta la del año en curso la crea y lo declara como fallo**: que
 * faltara significa que hasta ese momento se estaba fichando contra una tabla
 * que no admitia la traza.
 *
 * **Corre sobre la conexion de migracion.** Crear una particion es DDL y el rol
 * de la aplicacion no tiene DDL desde la tarea 1.14 (regla dura 6). Es la misma
 * conexion con la que se despliega, no un permiso nuevo.
 */
final class EnsureAuditPartitionsCommand extends Command
{
    protected $signature = 'compliance:ensure-audit-partitions';

    protected $description = 'Crea la particion anual de audit_log que falte y avisa si faltaba la del año en curso (ADR-027)';

    public function handle(EnsureAuditLogPartitions $ensure): int
    {
        $status = $ensure->handle();

        foreach ($status->createdYears as $year) {
            $this->info('Particion audit_log_'.$year.' creada.');
        }

        if ($status->currentYearWasMissing) {
            Log::critical('audit_log_partition_missing', [
                'year' => $status->currentYear,
                'created' => $status->createdYears,
            ]);

            $this->error(
                'Faltaba la particion del año en curso ('.$status->currentYear.'). '
                .'Se ha creado, pero hasta ahora TODA accion auditable estaba fallando. '
                .'Procedimiento: docs/runbooks/rotura-cadena-auditoria.md'
            );

            return self::FAILURE;
        }

        $this->info(
            'Particiones al dia: '.$status->currentYear.' presente'
            .($status->nextYearReady ? ' y '.($status->currentYear + 1).' preparada.' : '.')
        );

        return self::SUCCESS;
    }
}

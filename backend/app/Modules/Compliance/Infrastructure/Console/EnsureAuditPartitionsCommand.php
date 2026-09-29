<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Console;

use App\Modules\Compliance\Application\Exception\AuditPartitionCreationUnavailable;
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
 * **Corre sobre la conexion de la aplicacion** (ADR-042). Crear una particion es
 * DDL y el rol de la aplicacion no tiene DDL desde la tarea 1.14 (regla dura 6),
 * ni debe tener ninguna credencial que pueda alterar el registro. La particion
 * la crea la funcion `audit_log_create_partition` de la base, con los permisos
 * del propietario y solo para el año en curso o el siguiente. **Si la funcion
 * falta** —la migracion 2026_09_29_100000 no se ha aplicado—, el comando falla
 * con un mensaje propio, deja la metrica `audit_log_partition_ready` a 0 para
 * que suenen las alertas y NO busca otra credencial.
 */
final class EnsureAuditPartitionsCommand extends Command
{
    private const string RUNBOOK = 'docs/runbooks/rotura-cadena-auditoria.md';

    protected $signature = 'compliance:ensure-audit-partitions';

    protected $description = 'Crea la particion anual de audit_log que falte y avisa si faltaba la del año en curso (ADR-027)';

    public function handle(EnsureAuditLogPartitions $ensure): int
    {
        try {
            $status = $ensure->handle();
        } catch (AuditPartitionCreationUnavailable $unavailable) {
            // Solo el año: ni nombres ni datos de nadie (regla dura 21). La
            // metrica ya la ha publicado el caso de uso antes de relanzar.
            Log::critical('audit_log_partition_creation_unavailable', [
                'year' => $unavailable->year,
            ]);

            $this->error($unavailable->getMessage().' Procedimiento: '.self::RUNBOOK);

            return self::FAILURE;
        }

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
                .'Procedimiento: '.self::RUNBOOK
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

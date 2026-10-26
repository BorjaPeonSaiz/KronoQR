<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Console;

use App\Modules\Product\Application\UseCase\RecordPendingDiagnosticsAudits;
use App\Modules\Shared\Infrastructure\Console\SpeaksInstallationLanguage;
use Illuminate\Console\Command;

/**
 * `php artisan product:diagnostics:record-pending` — escribe en `audit_log` los
 * asientos de los paquetes de diagnostico generados con la base de datos caida
 * (ADR-020, enmienda del 14-10-2026; RL-04, regla dura 6).
 *
 * Lo ejecuta el planificador cada cinco minutos (`routes/console.php`). No hace
 * falta lanzarlo a mano: `product:diagnostics` lo hace tambien al arrancar.
 * Sin pendientes no toca la base, asi que con la base caida y nada pendiente
 * no gasta ni un intento de conexion.
 *
 * ## Lo que imprime
 *
 * Nada si no habia pendientes. Si los habia, recuentos y, si falla, la
 * **clase** de la excepcion. Nunca su mensaje (regla
 * dura 21): la salida de una tarea programada acaba en el log del contenedor y,
 * de ahi, en el paquete de diagnostico.
 *
 * ## Codigos de salida
 *
 * | Codigo | Significado |
 * |---|---|
 * | `0` | No habia pendientes, o se han escrito todos. |
 * | `1` | Queda alguno: la base sigue sin responder, u otro fallo. Se reintenta en la siguiente pasada. |
 */
final class ProductDiagnosticsRecordPendingCommand extends Command
{
    use SpeaksInstallationLanguage;

    /** Seccion de `lang/*\/console.php`. */
    private const string TEXTS = 'console.product_diagnostics_record_pending';

    protected $signature = 'product:diagnostics:record-pending';

    protected $description = 'Escribe en la auditoria los asientos de paquetes de diagnostico generados con la base de datos caida';

    public function handle(RecordPendingDiagnosticsAudits $recordPending): int
    {
        $result = $recordPending->handle();

        // Sin pendientes, en silencio: corre cada cinco minutos y su salida va
        // al log del contenedor. Una linea por pasada sin nada que
        // hacer seria ruido; el exito silencioso no deja apunte.
        if ($result['recorded'] === 0 && $result['remaining'] === 0) {
            return self::SUCCESS;
        }

        if ($result['recorded'] > 0) {
            $this->line($this->text('recorded', ['count' => $result['recorded']]));
        }

        if ($result['remaining'] === 0) {
            return self::SUCCESS;
        }

        $this->line($this->text('remaining', [
            'count' => $result['remaining'],
            'error' => $result['failure'] ?? $this->text('unknown_error'),
        ]));
        $this->paragraph('retry');

        return self::FAILURE;
    }
}

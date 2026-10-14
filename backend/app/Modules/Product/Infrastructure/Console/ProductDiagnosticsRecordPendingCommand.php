<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Console;

use App\Modules\Product\Application\UseCase\RecordPendingDiagnosticsAudits;
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
    protected $signature = 'product:diagnostics:record-pending';

    protected $description = 'Escribe en la auditoria los asientos de paquetes de diagnostico generados con la base de datos caida';

    public function handle(RecordPendingDiagnosticsAudits $recordPending): int
    {
        $result = $recordPending->handle();

        // Sin pendientes, en silencio: corre cada cinco minutos y su salida va
        // al log del contenedor (V4-BE-1). Una linea por pasada sin nada que
        // hacer seria ruido; el exito silencioso no deja apunte.
        if ($result['recorded'] === 0 && $result['remaining'] === 0) {
            return self::SUCCESS;
        }

        if ($result['recorded'] > 0) {
            $this->line('Asientos de diagnostico pendientes escritos: '.$result['recorded'].'.');
        }

        if ($result['remaining'] === 0) {
            return self::SUCCESS;
        }

        $this->line('Asientos de diagnostico que siguen pendientes: '.$result['remaining']
            .' (error '.($result['failure'] ?? 'desconocido').').');
        $this->line('Se reintentara en la siguiente pasada. Si la base de datos responde y esto se repite,');
        $this->line('ejecuta php artisan product:doctor y revisa las comprobaciones database.*.');

        return self::FAILURE;
    }
}

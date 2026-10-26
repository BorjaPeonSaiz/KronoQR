<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure\Console;

use App\Modules\Attendance\Application\UseCase\PruneScanProcessingFailures;
use App\Modules\Shared\Infrastructure\Console\SpeaksInstallationLanguage;
use Illuminate\Console\Command;
use Throwable;

/**
 * `attendance:prune-scan-failures` — borra los intentos fallidos de procesar un
 * fichaje de mas de 30 dias (RN-22, ADR-061 §1). Ver
 * {@see PruneScanProcessingFailures}: es una purga tecnica, sin confirmacion ni
 * asiento, como la del historico de errores.
 *
 * La salida es una linea con el recuento, para el log del planificador. Ante un
 * fallo, la **clase** y nunca el mensaje: el de PostgreSQL puede llevar el valor
 * de una fila, y ese log viaja en el paquete de diagnostico (regla dura 21).
 */
final class PruneScanFailuresCommand extends Command
{
    use SpeaksInstallationLanguage;

    /** Seccion de `lang/*\/console.php`. */
    private const string TEXTS = 'console.prune_scan_failures';

    protected $signature = 'attendance:prune-scan-failures';

    protected $description = 'Borra los intentos fallidos de procesar un fichaje de mas de 30 dias (RN-22, ADR-061)';

    public function handle(PruneScanProcessingFailures $prune): int
    {
        try {
            $deleted = $prune->handle();
        } catch (Throwable $failure) {
            $this->error(\sprintf('attendance.scan_failures_prune_failed %s', $failure::class));

            return self::FAILURE;
        }

        $this->line($this->text('pruned', [
            'count' => $deleted,
            'days' => PruneScanProcessingFailures::RETENTION_DAYS,
        ]));

        return self::SUCCESS;
    }
}

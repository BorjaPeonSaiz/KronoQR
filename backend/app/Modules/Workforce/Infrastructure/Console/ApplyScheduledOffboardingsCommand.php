<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure\Console;

use App\Modules\Shared\Infrastructure\Console\SpeaksInstallationLanguage;
use App\Modules\Workforce\Application\UseCase\ApplyDueOffboardings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * `workforce:apply-scheduled-offboardings` — hace efectivas las bajas
 * programadas vencidas (RN-14, RF-GP-03, ADR-062 §4).
 *
 * La programa `routes/console.php` cada hora (`hourlyAt(7)`, sin solaparse). Se
 * puede lanzar a mano sin riesgo: es idempotente, y una baja que ya se aplico o
 * que se anulo no se toca. Lo que decide que aplicar lo decide el caso de uso con
 * el reloj y la zona del centro; aqui solo se imprime el resumen.
 *
 * El log lleva recuentos y la fecha del centro, **nunca personas** (regla dura
 * 21): cada persona aplazada o fallida deja su propia linea con `employee_uuid`
 * desde el caso de uso. Sale con `FAILURE` si alguna fallo, para que el
 * planificador lo registre (`scheduler.command_failed`).
 */
final class ApplyScheduledOffboardingsCommand extends Command
{
    use SpeaksInstallationLanguage;

    private const string TEXTS = 'console.apply_scheduled_offboardings';

    protected $signature = 'workforce:apply-scheduled-offboardings';

    protected $description = 'Hace efectivas las bajas programadas cuya fecha de cese ya ha pasado (RN-14)';

    public function handle(ApplyDueOffboardings $apply): int
    {
        $report = $apply->handle();

        if (! $report->ranOverASite) {
            $this->warn($this->text('no_site'));

            return self::SUCCESS;
        }

        Log::info('workforce.scheduled_offboardings', [
            'today' => $report->today,
            'applied' => $report->applied,
            'deferred' => $report->deferred,
            'failed' => $report->failed,
            'overdue' => $report->overdue,
        ]);

        $this->info($this->text('summary', [
            'today' => (string) $report->today,
            'applied' => $report->applied,
            'deferred' => $report->deferred,
        ]));

        if ($report->overdue > 0) {
            $this->warn($this->text('overdue', ['count' => $report->overdue]));
        }

        if ($report->failed > 0) {
            $this->error($this->text('failures', ['count' => $report->failed]));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}

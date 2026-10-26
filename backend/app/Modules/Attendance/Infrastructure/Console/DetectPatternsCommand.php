<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure\Console;

use App\Modules\Attendance\Application\Command\DetectPatternsCommand as DetectPatterns;
use App\Modules\Attendance\Application\UseCase\DetectCredentialPatterns;
use App\Modules\Attendance\Application\UseCase\PatternScanResult;
use App\Modules\Shared\Infrastructure\Console\SpeaksInstallationLanguage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * `php artisan attendance:detect-patterns` — la deteccion de patrones anomalos
 * de uso de credencial (RF-PR-06, RN-16, doc 02 Anexo C).
 *
 * Lo ejecuta el planificador de madrugada, a las 04:35 UTC
 * (`routes/console.php`), entre la deteccion de incidencias y las metricas de
 * cumplimiento. A mano sirve para revisar una ventana mas ancha despues de
 * ajustar los umbrales, que es la unica situacion en la que mirar mas atras
 * tiene sentido.
 *
 * ## Treinta dias, y no siete
 *
 * La ventana es `COMPLIANCE_PATTERN_LOOKBACK_DAYS` (30 de serie), mas ancha que
 * la de `attendance:detect-incidents`: «sistematico» no se puede afirmar sobre
 * una semana. No es un umbral —no dice cuando algo es anomalo, eso lo dicen los
 * tres ajustes de `installation_settings`— sino hasta donde mira el proceso.
 *
 * ## Lo que este comando NO hace
 *
 * **No anula, no marca y no corrige ningun fichaje** (reglas duras 5 y 19) y
 * **no concluye que haya fraude** (RF-PR-06): emite indicios que `Compliance`
 * convierte en incidencias `anomalous_pattern` asignadas al responsable, para
 * que una persona las contraste con la supervision presencial siguiendo el
 * runbook `patron-anomalo-credencial.md`. Su codigo de salida es `0` aunque
 * encuentre cien: encontrar cosas es su trabajo, no un fallo.
 *
 * **No imprime ningun nombre** (regla dura 21): el resumen son recuentos por
 * patron. Ni siquiera dice a cuantas personas afecta —eso ya es informacion
 * sobre quien—: el detalle vive en la bandeja, que se lee con autorizacion.
 */
final class DetectPatternsCommand extends Command
{
    use SpeaksInstallationLanguage;

    /** Seccion de `lang/*\/console.php`. */
    private const string TEXTS = 'console.detect_patterns';

    protected $signature = 'attendance:detect-patterns
        {--days= : Dias hacia atras a revisar. Por defecto, compliance.pattern_detection.lookback_days}';

    protected $description = 'Busca patrones anomalos de uso de credencial y abre las incidencias para revision humana (RF-PR-06)';

    public function handle(DetectCredentialPatterns $detect): int
    {
        $days = $this->lookbackDays();

        if ($days === null || $days < 1) {
            // `< 1` tambien aqui y no solo en el comando de dominio:
            // dejarselo a la excepcion del `DetectPatternsCommand` de aplicacion
            // convertia un `--days=0` escrito de mas en una traza de PHP en el log
            // del planificador, y una traza no dice que corregir.
            $this->error($this->text('invalid_days', ['value' => (string) $this->option('days')]));

            return self::INVALID;
        }

        $result = $detect->handle(new DetectPatterns($days));

        if (! $result->ranOverASite) {
            $this->warn($this->text('no_site'));

            // Cero y no error: antes de la puesta en marcha esto es lo esperado.
            return self::SUCCESS;
        }

        // El log lleva recuentos y ventana, nunca personas (regla dura 21).
        Log::info('attendance.pattern_detection', [
            'days_inspected' => $result->daysInspected,
            'scans_inspected' => $result->scansInspected,
            'patterns' => $result->byPattern,
            'failures' => $result->failures,
        ]);

        $this->info($this->text('inspected', [
            'scans' => $result->scansInspected,
            'days' => $result->daysInspected,
        ]));

        if ($result->total() === 0) {
            $this->line($this->text('none'));

            return $this->report($result);
        }

        foreach ($result->byPattern as $pattern => $count) {
            $this->line('  '.$pattern.': '.$count);
        }

        $this->line($this->text('hints_only'));

        return $this->report($result);
    }

    /**
     * El desenlace de la pasada.
     *
     * **Distinto de cero si algun hallazgo no se pudo abrir**, aunque el resto si
     * se abriera: una revision que termina en verde habiendo perdido indicios es
     * peor que una que falla. El detalle esta en el log, con `employee_uuid` y
     * sin nombres (regla dura 21), y la cifra se publica como
     * `pattern_detection_last_failures`.
     */
    private function report(PatternScanResult $result): int
    {
        if ($result->failures === 0) {
            return self::SUCCESS;
        }

        $this->error($this->text('failures', ['count' => $result->failures]));

        return self::FAILURE;
    }

    /**
     * La ventana efectiva: la de `--days` si se indico, y si no la configurada.
     * `null` cuando la opcion llego y no es un numero.
     *
     * Nada de esto se corrige en silencio: caer al valor configurado sin decir
     * nada haria que quien escribio mal la opcion creyera que reviso tres meses
     * cuando reviso treinta dias.
     */
    private function lookbackDays(): ?int
    {
        $option = $this->option('days');

        if (! is_string($option) || trim($option) === '') {
            return Config::integer('compliance.pattern_detection.lookback_days', 30);
        }

        return preg_match('/^-?\d+$/', $option) === 1 ? (int) $option : null;
    }
}

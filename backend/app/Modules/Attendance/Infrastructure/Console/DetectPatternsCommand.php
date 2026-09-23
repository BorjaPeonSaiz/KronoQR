<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure\Console;

use App\Modules\Attendance\Application\Command\DetectPatternsCommand as DetectPatterns;
use App\Modules\Attendance\Application\UseCase\DetectCredentialPatterns;
use App\Modules\Attendance\Application\UseCase\PatternScanResult;
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
    protected $signature = 'attendance:detect-patterns
        {--days= : Dias hacia atras a revisar. Por defecto, compliance.pattern_detection.lookback_days}';

    protected $description = 'Busca patrones anomalos de uso de credencial y abre las incidencias para revision humana (RF-PR-06)';

    public function handle(DetectCredentialPatterns $detect): int
    {
        $days = $this->lookbackDays();

        if ($days === null || $days < 1) {
            // `< 1` tambien aqui y no solo en el comando de dominio (decision 17):
            // dejarselo a la excepcion del `DetectPatternsCommand` de aplicacion
            // convertia un `--days=0` escrito de mas en una traza de PHP en el log
            // del planificador, y una traza no dice que corregir.
            $this->error(
                '--days espera un numero entero de dias mayor que cero y ha llegado «'.(string) $this->option('days').'». '
                .'Sin valor valido no se adivina la ventana: un patron se afirma sobre los dias que se miran, '
                .'y mirar de menos deja de verlo.'
            );

            return self::INVALID;
        }

        $result = $detect->handle(new DetectPatterns($days));

        if (! $result->ranOverASite) {
            $this->warn('Todavia no hay centro de trabajo: sin zona horaria no hay dia civil con el que agrupar (RF-PD-03).');

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

        $this->info(sprintf(
            'Revisados %d escaneos de quiosco de los ultimos %d dias.',
            $result->scansInspected,
            $result->daysInspected,
        ));

        if ($result->total() === 0) {
            $this->line('Sin patrones anomalos.');

            return $this->report($result);
        }

        foreach ($result->byPattern as $pattern => $count) {
            $this->line('  '.$pattern.': '.$count);
        }

        $this->line(
            'Son indicios para revision humana, no una conclusion: ningun fichaje se ha anulado ni marcado '
            .'como fraudulento (RF-PR-06). Repetir el comando es seguro: los hallazgos ya abiertos no se duplican.'
        );

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

        $this->error(sprintf(
            '%d hallazgo(s) no se pudieron abrir como incidencia. El resto si. '
            .'Busca «attendance.pattern_incident_not_opened» en el log para ver cuales.',
            $result->failures,
        ));

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

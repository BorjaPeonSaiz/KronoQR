<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Retention;

use App\Modules\Compliance\Application\Port\RetentionReportStore;
use App\Modules\Compliance\Domain\ValueObject\RetentionMode;
use App\Modules\Compliance\Domain\ValueObject\RetentionReport;
use App\Modules\Compliance\Domain\ValueObject\RetentionScope;
use App\Modules\Compliance\Domain\ValueObject\RetentionTally;
use Illuminate\Support\Facades\Config;
use RuntimeException;

/**
 * El informe de la pasada, escrito en el servidor del cliente (RF-PR-03,
 * regla dura 16).
 *
 * ## Texto plano y no JSON
 *
 * Lo lee una persona -el responsable que autoriza la purga, o quien la defiende
 * dos anos despues-, no un programa. Un JSON obligaria a que alguien lo formatee
 * para poder leerlo, y lo que se archiva acabaria siendo esa version formateada,
 * que ya no es el fichero que el sistema produjo.
 *
 * ## Se escribe SIEMPRE, tambien en simulacion
 *
 * La propuesta programada deja su informe cada semana aunque no la lea nadie: es
 * la constancia de que el sistema esta vigilando el plazo, y es lo que permite
 * ver, cuando por fin se ejecuta la purga, que se llevo lo mismo que se llevaba
 * proponiendo desde hace meses.
 *
 * ## Sin datos personales y sin rutas del servidor
 *
 * Ambitos, tablas, recuentos y rangos de fecha. Ni un nombre, ni un `uuid`
 * (regla dura 21): el informe se archiva y se adjunta a cualquier reclamacion.
 *
 * ## Permisos del directorio
 *
 * `0750`: el informe dice cuantas jornadas hay de que ano, que no es un dato
 * personal pero si informacion de negocio de la instalacion.
 */
final readonly class FileRetentionReportStore implements RetentionReportStore
{
    public function store(RetentionReport $report): string
    {
        $directory = $this->directory();

        if (! is_dir($directory) && ! mkdir($directory, 0o750, true) && ! is_dir($directory)) {
            throw new RuntimeException('No se ha podido crear el directorio de informes «'.$directory.'».');
        }

        $path = $directory.'/'.$this->filename($report);

        if (file_put_contents($path, $this->render($report)) === false) {
            throw new RuntimeException('No se ha podido escribir el informe de retencion en «'.$path.'».');
        }

        return $path;
    }

    private function filename(RetentionReport $report): string
    {
        $prefix = $report->mode === RetentionMode::Simulation ? 'propuesta' : 'purga';

        return 'retencion-'.$prefix.'-'.$report->generatedAt->format('Ymd-His').'.txt';
    }

    private function render(RetentionReport $report): string
    {
        $lines = [
            'KronoQR — informe de retencion (RL-02, RL-11, RF-PR-03)',
            '=======================================================',
            '',
            'Modo:              '.$report->mode->label(),
            'Generado:          '.$report->generatedAt->format('Y-m-d H:i:s').' UTC',
            'Centro:            '.$report->policy->siteId,
            'Plazos aplicados:  registro de jornada y auditoria '.$report->policy->legalRecordYears.' anos · '
                .'log tecnico '.$report->policy->technicalLogDays.' dias · '
                .'historico de errores '.$report->policy->errorHistoryDays.' dias',
            'Corte de jornada:  anterior a '.$report->workRecordCutoff->format('Y-m-d')
                .' (ese dia NO se purga: el plazo lo incluye)',
            '',
        ];

        foreach (RetentionScope::cases() as $scope) {
            $lines = [...$lines, ...$this->section($report, $scope)];
        }

        $lines[] = 'TOTAL de registros '.($report->mode === RetentionMode::Simulation ? 'a purgar' : 'purgados')
            .': '.$report->totalRows();
        $lines[] = '';

        if ($report->notes !== []) {
            $lines[] = 'Observaciones';
            $lines[] = '-------------';

            foreach ($report->notes as $note) {
                $lines[] = '  · '.$note;
            }

            $lines[] = '';
        }

        if ($report->mode === RetentionMode::Simulation) {
            $lines[] = 'No se ha borrado nada. Para ejecutar ESTA purga, con la confirmacion del responsable:';
            $lines[] = '';
            $lines[] = '    php artisan compliance:apply-retention --confirm='.$report->confirmationToken();
            $lines[] = '';
            $lines[] = 'La frase caduca cuando cambia el corte o el perfil de cumplimiento: se ejecuta lo que';
            $lines[] = 'se aprobo, no lo que hoy toque.';
        } else {
            $lines[] = 'Purga ejecutada con la confirmacion '.$report->confirmationToken().'.';
            $lines[] = 'Queda asiento en audit_log; las particiones soltadas quedan selladas en audit_chain_anchors.';
        }

        $lines[] = '';
        $lines[] = 'Este informe se queda en el servidor del cliente (ADR-020). No contiene datos personales.';

        return implode("\n", $lines)."\n";
    }

    /**
     * @return list<string>
     */
    private function section(RetentionReport $report, RetentionScope $scope): array
    {
        $tallies = $report->talliesFor($scope);

        if ($tallies === []) {
            return [];
        }

        $lines = [
            $scope->label().' ('.$this->term($report, $scope).')',
            str_repeat('-', 55),
        ];

        foreach ($tallies as $tally) {
            $lines[] = sprintf(
                '  %-22s %8s %-9s %s',
                $tally->dataset,
                (string) $tally->rows,
                $scope->unit(),
                $tally->range(),
            );
        }

        $lines[] = sprintf('  %-22s %8s %s', 'subtotal', (string) $this->subtotal($tallies), $scope->unit());
        $lines[] = '';

        return $lines;
    }

    /** El plazo de ese ambito, dicho en su unidad: anos para lo legal, dias para el ciclo corto. */
    private function term(RetentionReport $report, RetentionScope $scope): string
    {
        return match ($scope) {
            RetentionScope::WorkRecords, RetentionScope::AuditLog => $report->policy->legalRecordYears.' anos',
            RetentionScope::TechnicalLog => $report->policy->technicalLogDays.' dias',
            RetentionScope::ErrorHistory => $report->policy->errorHistoryDays.' dias',
        };
    }

    /**
     * @param  list<RetentionTally>  $tallies
     */
    private function subtotal(array $tallies): int
    {
        return array_sum(array_map(static fn (RetentionTally $tally): int => $tally->rows, $tallies));
    }

    private function directory(): string
    {
        $configured = Config::string('compliance.retention.report_path', '');

        return rtrim($configured === '' ? storage_path('app/retention-reports') : $configured, '/\\');
    }
}

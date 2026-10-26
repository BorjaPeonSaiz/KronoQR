<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Retention;

use App\Modules\Compliance\Application\Port\RetentionReportStore;
use App\Modules\Compliance\Domain\ValueObject\RetentionMode;
use App\Modules\Compliance\Domain\ValueObject\RetentionReport;
use App\Modules\Compliance\Domain\ValueObject\RetentionScope;
use App\Modules\Compliance\Domain\ValueObject\RetentionTally;
use App\Modules\Shared\Domain\Support\UtcInstant;
use App\Modules\Shared\Domain\ValueObject\UtcWallTime;
use App\Modules\Shared\Infrastructure\Console\InstallationText;
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
    /** Clave de `lang/*\/retention.php` bajo la que vive el texto del informe. */
    private const string TEXTS = 'retention.report';

    /**
     * El informe sale en el idioma de la instalacion, como la consola que lo
     * anuncia: lo archiva y lo defiende el hotel, no quien lo programo. El
     * NOMBRE del fichero no se traduce: lo buscan por patron los scripts de
     * rescate (`retencion-propuesta-*`, `retencion-purga-*`).
     */
    public function __construct(private InstallationText $texts) {}

    public function store(RetentionReport $report): string
    {
        $directory = $this->directory();

        if (! is_dir($directory) && ! mkdir($directory, 0o750, true) && ! is_dir($directory)) {
            throw new RuntimeException('No se ha podido crear el directorio de informes «'.$directory.'».');
        }

        [$path, $handle] = $this->createExclusively($directory, $this->filename($report));

        try {
            // `0640` ANTES de escribir el contenido, y explicito: con `file_put_contents`
            // el modo era el de la umask del proceso (`0666` con un `exec` de umask
            // `0000`). Es el modo que fija ADR-045 y el que deja el rescate de
            // `update.sh`.
            @chmod($path, 0o640);

            if (fwrite($handle, $this->render($report)) === false) {
                throw new RuntimeException('No se ha podido escribir el informe de retencion en «'.$path.'».');
            }
        } finally {
            fclose($handle);
        }

        return $path;
    }

    /**
     * Crea el fichero con `x` —falla si ya existe— para **no pisar nunca un
     * informe**: dos pasadas en el mismo segundo, o un informe rescatado de la
     * 2.1.0 con el mismo nombre, conservan el suyo y este recibe un sufijo.
     *
     * @return array{string, resource}
     */
    private function createExclusively(string $directory, string $fileName): array
    {
        $base = substr($fileName, 0, -4);

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $path = $directory.'/'.($attempt === 0 ? $fileName : $base.'-'.$attempt.'.txt');
            $handle = @fopen($path, 'xb');

            if ($handle !== false) {
                return [$path, $handle];
            }

            if (! file_exists($path)) {
                break;
            }
        }

        throw new RuntimeException('No se ha podido crear el informe de retencion en «'.$directory.'».');
    }

    private function filename(RetentionReport $report): string
    {
        $prefix = $report->mode === RetentionMode::Simulation ? 'propuesta' : 'purga';

        return 'retencion-'.$prefix.'-'.UtcInstant::wallTime($report->generatedAt, UtcWallTime::FileStamp).'.txt';
    }

    private function render(RetentionReport $report): string
    {
        $policy = $report->policy;
        $title = $this->text('title');

        $lines = [
            $title,
            str_repeat('=', mb_strlen($title)),
            '',
            $this->text('mode', ['mode' => $this->text('modes.'.$report->mode->value)]),
            $this->text('generated', ['at' => UtcInstant::wallTime($report->generatedAt, UtcWallTime::Second)]),
            $this->text('site', ['site' => $policy->siteId]),
            $this->text('terms', [
                'years' => $policy->legalRecordYears,
                'log_days' => $policy->technicalLogDays,
                'error_days' => $policy->errorHistoryDays,
            ]),
            $this->text('cutoff', ['date' => $report->workRecordCutoff->format('Y-m-d')]),
            '',
        ];

        foreach (RetentionScope::cases() as $scope) {
            $lines = [...$lines, ...$this->section($report, $scope)];
        }

        $lines[] = $this->text($report->mode === RetentionMode::Simulation ? 'total_to_purge' : 'total_purged', [
            'count' => $report->totalRows(),
        ]);
        $lines[] = '';

        if ($report->notes !== []) {
            $notes = $this->text('notes');
            $lines[] = $notes;
            $lines[] = str_repeat('-', mb_strlen($notes));

            foreach ($report->notes as $note) {
                $lines[] = '  · '.$note;
            }

            $lines[] = '';
        }

        $lines = [...$lines, ...$this->closing($report)];

        $lines[] = '';
        $lines[] = $this->text('stays_on_server');

        return implode("\n", $lines)."\n";
    }

    /**
     * Lo que queda por hacer, o lo que se hizo: la orden que ejecuta ESTA purga
     * en una simulacion, la confirmacion en una ejecucion.
     *
     * @return list<string>
     */
    private function closing(RetentionReport $report): array
    {
        if ($report->mode !== RetentionMode::Simulation) {
            return [
                $this->text('executed', ['token' => $report->confirmationToken()]),
                $this->text('executed_entries'),
            ];
        }

        return [
            $this->text('nothing_deleted'),
            '',
            // La misma orden que imprime la consola: sale de un solo sitio.
            '    '.RetentionExecutionCommand::for($report),
            '',
            ...$this->texts->lines(self::TEXTS.'.phrase_expires'),
        ];
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

        $unit = $this->text('units.'.$scope->value);

        $lines = [
            $this->text('scopes.'.$scope->value).' ('.$this->term($report, $scope).')',
            str_repeat('-', 55),
        ];

        foreach ($tallies as $tally) {
            $lines[] = \sprintf(
                '  %-22s %8s %-9s %s',
                $tally->dataset,
                (string) $tally->rows,
                $unit,
                $tally->available ? $tally->range() : $this->text('not_installed'),
            );
        }

        $lines[] = \sprintf('  %-22s %8s %s', $this->text('subtotal'), (string) $this->subtotal($tallies), $unit);
        $lines[] = '';

        return $lines;
    }

    /** El plazo de ese ambito, dicho en su unidad: anos para lo legal, dias para el ciclo corto. */
    private function term(RetentionReport $report, RetentionScope $scope): string
    {
        $policy = $report->policy;

        return match ($scope) {
            RetentionScope::WorkRecords, RetentionScope::AuditLog,
            RetentionScope::ManagementAccounts => $this->text('term.years', ['years' => $policy->legalRecordYears]),
            RetentionScope::PersonnelRecords => $this->text('term.years_since_leaving', ['years' => $policy->personnelRecordYears]),
            RetentionScope::TechnicalLog => $this->text('term.days', ['days' => $policy->technicalLogDays]),
            RetentionScope::ErrorHistory => $this->text('term.days', ['days' => $policy->errorHistoryDays]),
        };
    }

    /** @param  array<string, int|string>  $replace */
    private function text(string $key, array $replace = []): string
    {
        return $this->texts->line(self::TEXTS.'.'.$key, $replace);
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
        // La ruta la resuelve `config/compliance.php` y solo ella (tambien el
        // caso de la variable vacia): la misma que comprueba `product:doctor`.
        return rtrim(Config::string('compliance.retention.report_path'), '/\\');
    }
}

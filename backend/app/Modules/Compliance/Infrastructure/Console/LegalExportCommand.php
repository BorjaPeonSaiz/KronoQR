<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Console;

use App\Modules\Compliance\Application\Command\GenerateLegalExportCommand;
use App\Modules\Compliance\Application\UseCase\GenerateLegalExport;
use App\Modules\Compliance\Domain\Exception\InvalidLegalExportRequest;
use App\Modules\Compliance\Domain\ValueObject\LegalExportPeriod;
use App\Modules\Compliance\Domain\ValueObject\LegalExportScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * `php artisan compliance:legal-export --from= --to= --employee=` — la
 * exportacion para la Inspeccion **sin depender del panel** (RF-IN-05, RL-03,
 * RL-06, doc 02 Anexo C, plan 1.17 paso 7).
 *
 * ## Por que existe habiendo endpoint
 *
 * Porque un requerimiento llega con plazo y no siempre con el panel disponible.
 * Un despliegue a medias, una sesion caducada, un navegador que no descarga
 * ficheros grandes o simplemente que quien atiende el requerimiento es el
 * administrador del servidor y no un usuario de RRHH: en cualquiera de esos
 * casos, el registro horario tiene que poder salir igual. El runbook
 * `docs/runbooks/requerimiento-inspeccion.md` se apoya en esta orden.
 *
 * ## Escribe donde le digan, y por defecto donde se pueda encontrar
 *
 * `--output` acepta una ruta; sin ella, el fichero cae en
 * `storage/app/legal-exports/` con el nombre que decide el manifiesto —que
 * **nunca lleva el nombre de una persona** (regla dura 21)—. La ruta se imprime
 * al terminar, porque un comando que escribe un fichero y no dice donde obliga a
 * buscarlo.
 *
 * ## Queda auditado igual que la descarga
 *
 * El asiento lo escribe el caso de uso dentro de su transaccion (regla dura 6).
 * Aqui no hay sesion, asi que el actor es `system`: es la verdad —lo lanzo
 * alguien con acceso al servidor— y se cruza con el registro de acceso de la
 * maquina. Lo que **no** cambia es que la exportacion deja traza; una via de
 * escape sin auditoria seria justo la que se usaria para no dejarla.
 *
 * ## Codigos de salida
 *
 * `0` si el fichero se escribio, `1` si el periodo no es valido. Un periodo sin
 * jornadas registradas es `0` y un fichero con su cabecera de criterios: «no hay
 * nada» tambien es una afirmacion que hay que poder entregar.
 */
final class LegalExportCommand extends Command
{
    protected $signature = 'compliance:legal-export
        {--from= : Primer dia del periodo, YYYY-MM-DD, por fecha de jornada}
        {--to= : Ultimo dia del periodo, inclusive}
        {--employee= : UUID del trabajador. Sin esta opcion se exporta la plantilla completa}
        {--output= : Ruta del fichero a escribir. Por defecto storage/app/legal-exports/}';

    protected $description = 'Genera la exportacion normalizada para la Inspeccion de Trabajo (RF-IN-05, RL-06)';

    public function handle(GenerateLegalExport $generate): int
    {
        try {
            $period = LegalExportPeriod::between($this->stringOption('from'), $this->stringOption('to'));
        } catch (InvalidLegalExportRequest $invalid) {
            $this->error($invalid->getMessage());
            $this->line('Ejemplo: php artisan compliance:legal-export --from=2026-01-01 --to=2026-01-31');

            return self::FAILURE;
        }

        $employee = $this->stringOption('employee');
        $scope = $employee === '' ? LegalExportScope::everyone() : LegalExportScope::employee($employee);

        $export = $generate->handle(new GenerateLegalExportCommand(
            period: $period,
            scope: $scope,
            destinationPath: $this->destinationPath($period->slug()),
        ));

        // El log NO lleva nombres (regla dura 21): periodo, alcance y cifras. El
        // fichero si lleva datos personales, por su finalidad legal; su
        // constancia esta en `audit_log`, no aqui.
        Log::notice('compliance.legal_export_generated', [
            'period_from' => $export->manifest->period->from,
            'period_to' => $export->manifest->period->to,
            'scope' => $export->manifest->scope->metricLabel(),
            'employee_uuid' => $export->manifest->scope->employeeUuid,
            'shift_entry_rows' => $export->tally->shiftEntries,
            'correction_rows' => $export->tally->corrections,
            'employees_exported' => $export->tally->employees,
            'channel' => 'console',
        ]);

        $this->info('Exportacion legal generada.');
        $this->table(['Concepto', 'Valor'], [
            ['Periodo', $period->from.' → '.$period->to],
            ['Alcance', $scope->isEveryone() ? 'plantilla completa' : (string) $scope->employeeUuid],
            ['Tramos', (string) $export->tally->shiftEntries],
            ['Correcciones', (string) $export->tally->corrections],
            ['Trabajadores', (string) $export->tally->employees],
            ['Fichero', $export->path],
        ]);

        return self::SUCCESS;
    }

    /**
     * El nombre lo decide el manifiesto y no esta orden: es el mismo que se
     * descarga desde el panel, de modo que dos vias distintas de atender el
     * mismo requerimiento producen el mismo adjunto.
     */
    private function destinationPath(string $slug): string
    {
        $output = $this->stringOption('output');

        if ($output !== '') {
            return $output;
        }

        return storage_path('app/legal-exports/registro-horario-'.$slug.'.csv');
    }

    private function stringOption(string $name): string
    {
        $value = $this->option($name);

        return is_string($value) ? trim($value) : '';
    }
}

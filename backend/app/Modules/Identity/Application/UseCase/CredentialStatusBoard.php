<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

use App\Modules\Identity\Application\Port\CredentialMetrics;
use App\Modules\Identity\Application\Port\CredentialRepository;
use App\Modules\Identity\Application\Query\CredentialStatusQuery;
use App\Modules\Identity\Domain\ValueObject\CredentialLifecycleStatus;
use App\Modules\Identity\Domain\ValueObject\SiteCredentialCoverage;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\EmployeeCardDirectory;
use App\Modules\Shared\Domain\ValueObject\EmployeeCardProfile;

/**
 * El panel de estado de credenciales (RF-QR-08).
 *
 * El doc 02 §5.5 lo justifica: *«RF-QR-08 existe para que RRHH vea de un vistazo
 * quien no puede fichar todavia. Sin el, el problema se descubre delante del
 * quiosco a las 06:00.»*
 *
 * ## Los cinco estados son derivados, y por eso se calculan aqui
 *
 * No hay columna `status` en `credentials` y no la habra: un estado almacenado y
 * otro derivado acaban discrepando, y aqui discrepar significa que RRHH da por
 * entregada una tarjeta que nadie tiene. La regla vive en
 * {@see CredentialLifecycleStatus}, se prueba sin base de datos, y este caso de
 * uso solo la aplica.
 *
 * ## Dos consultas, no N+1
 *
 * Una para la plantilla de alta —con su centro y su departamento ya resueltos— y
 * otra para la credencial vigente de cada uno. En un hotel de trescientas
 * personas eso son dos consultas; preguntando persona a persona serian
 * trescientas una, y este panel se abre varias veces al dia.
 *
 * ## El recuento no se filtra
 *
 * `coverage` se calcula **antes** de aplicar `pendingOnly`. Es lo que permite
 * decir «faltan 3 de 60» en lugar de «faltan 3 de 3», y es tambien lo que
 * publican las dos metricas del §8.2.
 */
final readonly class CredentialStatusBoard
{
    public function __construct(
        private EmployeeCardDirectory $directory,
        private CredentialRepository $credentials,
        private CredentialMetrics $metrics,
        private Clock $clock,
    ) {}

    public function handle(CredentialStatusQuery $query): CredentialStatusReport
    {
        $employees = $this->directory->activeProfiles($query->siteId);

        $employeeIds = array_map(
            static fn (EmployeeCardProfile $profile): int => $profile->employeeId,
            $employees,
        );

        $latest = $this->credentials->latestForEmployees($employeeIds);

        $rows = [];

        foreach ($employees as $employee) {
            $credential = $latest[$employee->employeeId] ?? null;

            $rows[] = new CredentialStatusRow(
                employee: $employee,
                status: CredentialLifecycleStatus::of($credential),
                credential: $credential,
            );
        }

        $coverage = $this->coverageOf($rows);

        if ($query->pendingOnly) {
            $rows = array_values(array_filter(
                $rows,
                // «Pendiente» es todo el que todavia no tiene la tarjeta en la
                // mano, no solo quien esta pendiente de imprimir: incluye a quien
                // no tiene credencial, a quien la tiene revocada y a quien la
                // tiene impresa esperando en una bandeja.
                static fn (CredentialStatusRow $row): bool => ! $row->status->canClockWithCard(),
            ));
        }

        return new CredentialStatusReport($rows, $coverage);
    }

    /**
     * Como {@see handle()}, pero ademas **publica las dos metricas** del §8.2.
     *
     * Se separa a proposito: el endpoint del panel se consulta muchas veces al dia
     * y no tiene por que escribir en disco en cada peticion. Quien publica es el
     * comando de consola `credentials:status`, que el planificador ejecuta cada
     * hora y que es el productor natural de un fichero para el colector *textfile*
     * de `node-exporter`.
     */
    public function handleAndPublishMetrics(CredentialStatusQuery $query): CredentialStatusReport
    {
        $report = $this->handle($query);

        $this->metrics->recordCoverage($report->coverage, $this->clock->now());

        return $report;
    }

    /**
     * El recuento por centro, con **todos** los centros del alcance, tambien los
     * que estan a cero.
     *
     * @param  list<CredentialStatusRow>  $rows
     * @return list<SiteCredentialCoverage>
     */
    private function coverageOf(array $rows): array
    {
        /** @var array<int, array{name: string, employees: int, pending_print: int, without: int}> $bySite */
        $bySite = [];

        foreach ($rows as $row) {
            $siteId = $row->employee->siteId;

            $bySite[$siteId] ??= [
                'name' => $row->employee->siteName,
                'employees' => 0,
                'pending_print' => 0,
                'without' => 0,
            ];

            $bySite[$siteId]['employees']++;

            if ($row->status->isPendingPrint()) {
                $bySite[$siteId]['pending_print']++;
            }

            if (! $row->status->canClockWithCard()) {
                $bySite[$siteId]['without']++;
            }
        }

        ksort($bySite);

        $coverage = [];

        foreach ($bySite as $siteId => $counts) {
            $coverage[] = new SiteCredentialCoverage(
                siteId: $siteId,
                siteName: $counts['name'],
                employees: $counts['employees'],
                pendingPrint: $counts['pending_print'],
                withoutDeliveredCredential: $counts['without'],
            );
        }

        return $coverage;
    }
}

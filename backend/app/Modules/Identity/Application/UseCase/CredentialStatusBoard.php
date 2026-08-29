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
use App\Modules\Shared\Application\Port\InstallationSiteProvider;
use App\Modules\Shared\Application\Port\PersonalDataAccessLog;
use App\Modules\Shared\Domain\Exception\InstallationSiteMissing;
use App\Modules\Shared\Domain\ValueObject\EmployeeCardProfile;
use App\Modules\Shared\Domain\ValueObject\InstallationSite;

/**
 * El panel de estado de credenciales (RF-QR-08): **quien no puede fichar
 * todavia**, con el recuento que permite decir «faltan 3 de 60».
 *
 * **La fila es del empleado y no de la credencial.** Quien no tiene ninguna
 * emitida es precisamente el caso que hay que ver, y un listado de credenciales
 * lo dejaria fuera. Por eso se parte de la plantilla de alta
 * ({@see EmployeeCardDirectory}) y se le busca a cada persona su credencial
 * mas reciente en una sola consulta.
 *
 * **El recuento se calcula antes de aplicar `pending`.** Es lo que hace que
 * `summary` diga «faltan 3 de 60» y no «faltan 3 de 3»: el denominador es la
 * plantilla, no las filas devueltas. Con `employee_uuid`, en cambio, el
 * recuento es de la fila devuelta (1 o 0): calcular la cobertura de la
 * plantilla obligaria a recorrerla entera, que es justo lo que ese filtro evita.
 *
 * **Hay un solo recuento** (ADR-040): la instalacion tiene un centro. El centro
 * se pide a {@see InstallationSiteProvider} y no se deduce de las filas, porque
 * con cero filas —una plantilla recien creada, o una persona que no existe— las
 * metricas siguen necesitando la etiqueta del centro para escribir su cero.
 *
 * **Esta lectura queda registrada** (RS-05, RL-15, ADR-037) porque reparte un
 * conjunto de personas, salvo cuando se acota a una (`employee_uuid`) o cuando
 * nadie ve un nombre (`unattended`, el planificador). El asiento describe el
 * alcance y jamas lo divulgado (regla dura 21).
 */
final readonly class CredentialStatusBoard
{
    private const string DATASET = 'credential_status';

    public function __construct(
        private EmployeeCardDirectory $directory,
        private CredentialRepository $credentials,
        private CredentialMetrics $metrics,
        private PersonalDataAccessLog $disclosures,
        private InstallationSiteProvider $installation,
        private Clock $clock,
    ) {}

    /**
     * @throws InstallationSiteMissing antes de la puesta en marcha
     */
    public function handle(CredentialStatusQuery $query): CredentialStatusReport
    {
        $site = $this->installation->installationSite();

        if (! $site instanceof InstallationSite) {
            throw InstallationSiteMissing::make();
        }

        $employees = $this->directory->activeProfiles($query->employeeUuid);

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

        $coverage = $this->coverageOf($site, $rows);

        if ($query->pendingOnly) {
            $rows = array_values(array_filter(
                $rows,
                static fn (CredentialStatusRow $row): bool => ! $row->status->canClockWithCard(),
            ));
        }

        if (! $query->unattended && $query->employeeUuid === null) {
            // Antes de devolver, no despues: si la escritura de auditoria falla,
            // la divulgacion no ocurre (regla dura 6, ADR-027). Se anota el
            // alcance —cuantas filas y con que filtro— y nunca un nombre.
            $this->disclosures->recordDisclosure(self::DATASET, \count($rows), [
                'pending_only' => $query->pendingOnly,
            ]);
        }

        return new CredentialStatusReport($rows, $coverage);
    }

    /**
     * El mismo informe, y ademas publica las metricas de cobertura (doc 02 §8.2).
     *
     * Lo llama el comando programado, no el endpoint: el panel no escribe en
     * disco en cada peticion.
     *
     * @throws InstallationSiteMissing
     */
    public function handleAndPublishMetrics(CredentialStatusQuery $query): CredentialStatusReport
    {
        $report = $this->handle($query);

        $this->metrics->recordCoverage($report->coverage, $this->clock->now());

        return $report;
    }

    /**
     * @param  list<CredentialStatusRow>  $rows
     */
    private function coverageOf(InstallationSite $site, array $rows): SiteCredentialCoverage
    {
        $employees = 0;
        $pendingPrint = 0;
        $without = 0;

        foreach ($rows as $row) {
            $employees++;

            if ($row->status->isPendingPrint()) {
                $pendingPrint++;
            }

            if (! $row->status->canClockWithCard()) {
                $without++;
            }
        }

        return new SiteCredentialCoverage(
            siteId: $site->id,
            siteName: $site->name,
            employees: $employees,
            pendingPrint: $pendingPrint,
            withoutDeliveredCredential: $without,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

use App\Modules\Identity\Application\Port\CredentialMetrics;
use App\Modules\Identity\Application\Port\CredentialRepository;
use App\Modules\Identity\Application\Port\QrKeyProvider;
use App\Modules\Identity\Application\Query\CredentialStatusQuery;
use App\Modules\Identity\Domain\Model\Credential;
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
 * **`key_id` acota a quien le falta reimprimir** durante una rotacion de clave
 * (RF-QR-07, §5.3): devuelve las personas cuya tarjeta **en uso** sigue firmada
 * con esa clave. Cuando no devuelve a nadie, la clave se puede retirar, y eso es
 * literalmente el procedimiento del §5.3. Filtra las filas y **no** el recuento,
 * igual que `pending`: el denominador sigue siendo la plantilla.
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
        // Solo para saber **que** clave esta saliendo, nunca su material: el
        // recuento de reimpresion pendiente necesita el `key_id` de la clave
        // anterior, que ademas va impreso en cada tarjeta (ADR-005).
        private QrKeyProvider $keys,
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

        $coverage = $this->coverageOf($site, $rows, $employeeIds);

        if ($query->pendingOnly) {
            $rows = array_values(array_filter(
                $rows,
                static fn (CredentialStatusRow $row): bool => ! $row->status->canClockWithCard(),
            ));
        }

        if ($query->keyId !== null) {
            $rows = array_values(array_filter(
                $rows,
                static fn (CredentialStatusRow $row): bool => self::stillSignedWith($row, $query->keyId),
            ));
        }

        if (! $query->unattended && $query->employeeUuid === null) {
            // Antes de devolver, no despues: si la escritura de auditoria falla,
            // la divulgacion no ocurre (regla dura 6, ADR-027). Se anota el
            // alcance —cuantas filas y con que filtro— y nunca un nombre.
            // El `key_id` solo entra en el alcance cuando se ha filtrado por el:
            // el contrato del puerto no admite nulos, y un `key_id: ""` en el
            // asiento diria que se filtro por una clave vacia.
            $scope = ['pending_only' => $query->pendingOnly];

            if ($query->keyId !== null) {
                $scope['key_id'] = $query->keyId;
            }

            $this->disclosures->recordDisclosure(self::DATASET, \count($rows), $scope);
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
     * Si esta persona sigue fichando con una tarjeta firmada por esa clave.
     *
     * **Solo la activa cuenta.** Una credencial revocada firmada con la clave
     * saliente ya no impide retirarla —no la resuelve ningun escaneo—, y
     * listarla mandaria a RRHH a buscar una tarjeta que nadie tiene.
     */
    private static function stillSignedWith(CredentialStatusRow $row, string $keyId): bool
    {
        return $row->credential instanceof Credential
            && $row->credential->isActive()
            && $row->credential->signedWithKey($keyId);
    }

    /**
     * @param  list<CredentialStatusRow>  $rows
     * @param  list<int>  $employeeIds
     */
    private function coverageOf(
        InstallationSite $site,
        array $rows,
        array $employeeIds,
    ): SiteCredentialCoverage {
        // La clave saliente sale del llavero y no de la peticion: el recuento
        // que se publica como metrica tiene que ser el mismo lo pida quien lo
        // pida, y ademas asi vale cero —serie escrita, sin rotacion en curso—
        // en vez de desaparecer.
        $retiringKeyId = $this->keys->keyring()->previousId();

        // **La cola de impresion se cuenta sobre las credenciales, no sobre el
        // estado de la fila.** Son dos preguntas distintas y en una rotacion se
        // separan: la fila dice si esa persona puede fichar —y durante el solape
        // puede, con su tarjeta vieja—, mientras que la cola dice cuantas
        // tarjetas hay esperando a la impresora. `credentials_pending_print` es
        // exactamente lo que imprime `credentials:print-batch --pending`
        // (doc 02 §8.2), asi que tiene que salir de la misma seleccion o el
        // panel diria «0 pendientes» mientras el lote saca 229 tarjetas.
        $pendingPrint = \count($this->credentials->pendingPrintForEmployees($employeeIds));

        $employees = 0;
        $without = 0;
        $pendingReprint = 0;

        foreach ($rows as $row) {
            $employees++;

            if (! $row->status->canClockWithCard()) {
                $without++;
            }

            if ($retiringKeyId !== null && self::stillSignedWith($row, $retiringKeyId)) {
                $pendingReprint++;
            }
        }

        return new SiteCredentialCoverage(
            siteId: $site->id,
            siteName: $site->name,
            employees: $employees,
            pendingPrint: $pendingPrint,
            withoutDeliveredCredential: $without,
            retiringKeyId: $retiringKeyId,
            pendingReprint: $pendingReprint,
        );
    }
}

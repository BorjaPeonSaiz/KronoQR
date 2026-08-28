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
use App\Modules\Shared\Application\Port\PersonalDataAccessLog;
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
 *
 * ## Abrir este panel deja constancia
 *
 * RS-05 no admite matices: *«todo acceso a datos personales de terceros queda
 * registrado en el trail de auditoria»*. Cada fila lleva nombre completo, codigo
 * de empleado, centro y departamento de una persona, y la respuesta las lleva
 * **todas**: es el directorio del hotel. Sin asiento no se puede responder a la
 * pregunta que RL-15 obliga a poder responder en 72 horas —«¿que se llevo esa
 * cuenta?»— justo para el conjunto de datos mas completo que expone la API.
 *
 * La incoherencia que esto corrige es concreta: `GET /kiosk/roster` divulga
 * **menos** —un hash y el nombre de pila— y si dejaba asiento.
 *
 * Se registra **el alcance** —que centro, si venia filtrado, a que persona si se
 * acoto a una, cuantas filas— y nunca lo divulgado (regla dura 21): ni un
 * nombre, ni un codigo, ni la lista de `employee_uuid` de los afectados.
 * Enumerarlos aqui seria una segunda copia de la plantilla con cuatro años de
 * retencion, que es exactamente lo que se intenta proteger. El `employee_uuid`
 * que si consta es el **filtro que pidio quien consulta**, no la lista de a
 * quien se leyo: es la diferencia entre un asiento que dice «abrio la ficha de
 * esa persona» y otro que dice «se llevo el directorio del centro».
 *
 * El apunte se escribe **antes** de devolver: si la escritura de auditoria
 * falla, la divulgacion no ocurre. Misma decision que en el padron del quiosco y
 * en el fichaje (regla dura 6, ADR-027).
 */
final readonly class CredentialStatusBoard
{
    /** Vocabulario estable del `audit_log`, en ingles y sin datos dentro. */
    private const string DATASET = 'credential_status';

    public function __construct(
        private EmployeeCardDirectory $directory,
        private CredentialRepository $credentials,
        private CredentialMetrics $metrics,
        private PersonalDataAccessLog $disclosures,
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

        if ($query->employeeUuid !== null) {
            // Se filtra aqui, y no en la consulta del directorio, porque
            // `coverage` tiene que seguir siendo el del centro: la ficha de
            // empleado enseña una fila, pero el resumen que la acompaña dice
            // «faltan 3 de 60», no «falta 1 de 1». Mismo motivo que
            // `pendingOnly`, y por eso el mismo sitio.
            $rows = array_values(array_filter(
                $rows,
                static fn (CredentialStatusRow $row): bool => $row->employee->employeeUuid === $query->employeeUuid,
            ));
        }

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

        if (! $query->unattended) {
            // El recuento es el de las filas que **salen**, no el de la
            // plantilla: es lo que convierte «alguien miro» en «alguien se llevo
            // el directorio entero».
            $this->disclosures->recordDisclosure(self::DATASET, \count($rows), [
                // `site_id` solo cuando lo hay: un `0` seria el identificador de
                // un centro que no existe. Su ausencia es el alcance mas amplio
                // —toda la instalacion—, que es tambien el que mas importa saber.
                ...($query->siteId === null ? [] : ['site_id' => $query->siteId]),
                // El UUID que aparece aqui es **el alcance pedido**, no la lista
                // de lo divulgado: es lo que distingue «se consulto la ficha de
                // una persona» de «se descargo el directorio del centro», que es
                // justo lo que RL-15 obliga a poder responder. Un UUID no es un
                // dato personal (regla dura 21: lo prohibido son los nombres, y
                // enumerar a los afectados de una lectura masiva), y ya se
                // registra igual en `employee_workdays`.
                ...($query->employeeUuid === null ? [] : ['employee_uuid' => $query->employeeUuid]),
                'pending_only' => $query->pendingOnly,
            ]);
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
     *
     * **Publicar metricas no es divulgar.** Cuando el planificador llama con
     * `unattended`, las filas nominales no salen de este proceso: lo que sale son
     * dos contadores por centro. Escribir un asiento de RS-05 cada hora afirmaria
     * en la tabla que se enseña en una inspeccion que alguien accedio a la
     * plantilla cuando no accedio nadie, y 8.760 apuntes al año de ese tipo
     * estorban justo a quien intente acotar el alcance de una brecha (RL-15).
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

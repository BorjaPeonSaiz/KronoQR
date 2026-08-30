<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\UseCase;

use App\Modules\Compliance\Application\Port\IncidentBoard;
use App\Modules\Compliance\Application\Port\IncidentBoardQuery;
use App\Modules\Compliance\Domain\ValueObject\IncidentSeverity;
use App\Modules\Compliance\Domain\ValueObject\IncidentType;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\InstallationSiteProvider;
use App\Modules\Shared\Application\Port\PersonalDataAccessLog;
use App\Modules\Shared\Domain\Exception\InstallationSiteMissing;
use App\Modules\Shared\Domain\ValueObject\InstallationSite;

/**
 * Lee la bandeja de incidencias y deja constancia de que alguien la leyo
 * (RF-PA-05, RS-05).
 *
 * ## Que decide esta clase y que no
 *
 * Decide **dos** cosas y ninguna es una regla de negocio: que consultar la
 * bandeja es un acceso a datos personales de terceros y por tanto deja apunte, y
 * en que marco se lee la pagina —la zona del centro y el instante del servidor
 * contra el que se calcula la antiguedad—. El alcance ya viene resuelto en la
 * consulta —lo pone el `FormRequest` a partir del token— y el orden y la
 * paginacion los decide el adaptador, que es quien puede apoyarse en el indice.
 *
 * **`now()` no aparece por ningun lado**: el instante entra por el puerto `Clock`
 * (regla dura 2). Sin eso, la prueba de la antiguedad dependeria del momento en
 * que se ejecute la suite.
 *
 * ## Por que la lectura deja apunte
 *
 * Cada fila lleva el nombre, el codigo y el departamento de una persona junto a
 * una afirmacion sobre sus horas. RS-05 no admite matices: *«todo acceso a datos
 * personales de terceros queda registrado en el trail de auditoria»*. La bandeja
 * es exactamente eso, y ademas es la pantalla desde la que se llega al registro
 * horario de cualquiera de esas personas.
 *
 * **Se registra el alcance y jamas lo divulgado** (regla dura 21): cuantas filas
 * y con que filtros. Ni un nombre, ni la lista de `employee_uuid` de los
 * afectados —eso seria una segunda copia de la bandeja con cuatro años de
 * retencion—; el `employee_uuid` solo aparece cuando **se filtro por el**, porque
 * entonces forma parte de la pregunta que se hizo y no de la respuesta que se
 * llevo.
 *
 * ## Sin agrupacion por ventana, y a proposito
 *
 * La presencia en vivo agrupa sus apuntes porque el panel la **sondea** cada
 * quince segundos (ADR-037). La bandeja no se sondea: se abre, se filtra y se
 * pagina, y cada una de esas acciones es un acto distinto de una persona. Un
 * apunte por consulta es aqui la respuesta correcta a «quien miro que».
 */
final readonly class ReadIncidentBoard
{
    /** Vocabulario estable del `audit_log`, en ingles y sin datos dentro. */
    private const string DATASET = 'incident_board';

    public function __construct(
        private IncidentBoard $board,
        private PersonalDataAccessLog $disclosures,
        private InstallationSiteProvider $installation,
        private Clock $clock,
    ) {}

    /**
     * @throws InstallationSiteMissing antes de la puesta en marcha, cuando no hay centro
     */
    public function handle(IncidentBoardQuery $query): IncidentBoardView
    {
        $site = $this->installation->installationSite();

        if (! $site instanceof InstallationSite) {
            // Sin centro no hay zona en la que mostrar nada, y sin zona la
            // antiguedad de una incidencia seria una cifra en la hora del
            // servidor presentada como si fuera la del hotel. Mismo criterio
            // —y misma excepcion— que la presencia en vivo.
            throw new InstallationSiteMissing;
        }

        $page = $this->board->page($query);

        $this->disclosures->recordDisclosure(self::DATASET, \count($page->rows), [
            'status' => $query->status->value,
            // Cadena vacia y no `null` cuando el filtro no se uso: el payload de
            // `audit_log` es canonico y un contexto con las mismas claves
            // siempre se compara mucho mejor seis meses despues.
            'type' => $query->type instanceof IncidentType ? $query->type->value : '',
            'severity' => $query->severity instanceof IncidentSeverity ? $query->severity->value : '',
            'department_id' => $query->departmentId ?? 0,
            // Solo si se filtro por una persona: entonces es parte de la
            // pregunta. Sin filtro no se enumera a nadie.
            'employee_uuid' => $query->employeeUuid ?? '',
            'page' => $page->page,
            'total' => $page->total,
        ]);

        return new IncidentBoardView($page, $site->timezone, $this->clock->now());
    }
}

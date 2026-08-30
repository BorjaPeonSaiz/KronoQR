<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Query;

use App\Modules\Reporting\Application\Port\LivePresenceReader;
use App\Modules\Reporting\Domain\ValueObject\PresenceBoard;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\InstallationSiteProvider;
use App\Modules\Shared\Application\Port\PersonalDataAccessLog;
use App\Modules\Shared\Domain\Exception\InstallationSiteMissing;

/**
 * La foto de la presencia que pinta el panel (**RF-PA-01**, RF-PA-02).
 *
 * ## Solo lee, y aun asi deja constancia
 *
 * Lo que sale por aqui es un conjunto de personas con nombre, departamento y la
 * hora a la que entraron a trabajar: datos personales de terceros, y RS-05 no
 * admite matices sobre eso. El asiento describe **el alcance** —cuantas filas,
 * que filtros, con que ambito— y jamas lo divulgado (regla dura 21), igual que
 * en el listado de plantilla.
 *
 * ## Y por eso el asiento se agrupa
 *
 * Esta vista **se sondea**: el panel la pide cada 15 s cuando el WebSocket no
 * llega (RNF-D-03, ADR-011), y con tres puestos abiertos eso son mas de veinte
 * mil asientos al dia de la misma lectura. Dos problemas, y el segundo es el
 * grave:
 *
 *   1. `audit_log` se retiene cuatro años y se enseña en una inspeccion. Veinte
 *      mil filas diarias que dicen «RRHH miro quien estaba dentro» no responden
 *      mejor a RL-15 que una fila por ventana: la ahogan.
 *   2. Cada escritura toma el `pg_advisory_xact_lock` **global** de ADR-010, el
 *      mismo por el que pasa cada fichaje. Una funcionalidad accesoria (ADR-023)
 *      no puede meter escrituras serializadas en el camino critico del cambio de
 *      turno (regla dura 15, RNF-P-02).
 *
 * La agrupacion vive **detras del puerto**, en
 * `Compliance\Infrastructure\Adapter\GroupedPersonalDataAccessLog`, por lo mismo
 * que la de las denegaciones de ADR-037: es una decision de infraestructura
 * —necesita la cache y el actor de la peticion— y este caso de uso no tiene que
 * saber que existe. **El hecho no se pierde**: el primer asiento de cada ventana
 * se escribe siempre y lleva cuantas lecturas representa.
 *
 * ## El instante y la zona los resuelve este caso de uso
 *
 * `generatedAt` sale del puerto `Clock` (regla dura 2) y la zona, del centro de
 * la instalacion (ADR-040). Los dos entran en el tablero y salen en la respuesta
 * porque el cliente no debe adivinar ninguno de los dos: el tiempo transcurrido
 * se calcula contra `generatedAt` y la hora se presenta en la zona del centro,
 * no en la del navegador (regla dura 3).
 */
final readonly class ReadLivePresence
{
    /** Vocabulario estable del `audit_log`, en ingles y sin datos dentro. */
    private const string DATASET = 'live_presence';

    public function __construct(
        private LivePresenceReader $presence,
        private InstallationSiteProvider $installation,
        private Clock $clock,
        private PersonalDataAccessLog $disclosures,
    ) {}

    /**
     * @throws InstallationSiteMissing antes de la puesta en marcha, cuando no hay centro
     *                                 del que tomar la zona horaria (RF-PD-03)
     */
    public function handle(LivePresenceQuery $query): PresenceBoard
    {
        $site = $this->installation->installationSite();

        if ($site === null) {
            // Sin centro no hay zona, y sin zona la respuesta obligaria al panel
            // a adivinarla. Es un estado de la instalacion, no un error de quien
            // pregunta: `409` (ver bootstrap/app.php).
            throw new InstallationSiteMissing;
        }

        $board = $this->presence->board(
            scope: $query->scope,
            departmentId: $query->departmentId,
            search: $query->search,
            status: $query->status,
            generatedAt: $this->clock->now(),
            timeZone: $site->timezone,
        );

        // Antes de devolver, no despues: si la escritura de auditoria falla, la
        // divulgacion no ocurre (regla dura 6, ADR-027). El recuento es el de
        // las filas que salen de verdad, no el del filtro.
        $this->disclosures->recordDisclosure(self::DATASET, \count($board->entries), [
            ...($query->departmentId === null ? [] : ['department_id' => $query->departmentId]),
            'status' => $query->status->value,
            // **El termino NO se guarda, solo si lo hubo.** Quien busca en el
            // panel escribe el nombre de una persona, asi que el termino es un
            // dato personal y copiarlo a la tabla de cuatro años de retencion
            // seria copiar nombres (regla dura 21). Mismo criterio que el
            // listado de plantilla.
            'search' => $query->search !== null,
            // El alcance con el que se sirvio la foto (RF-ID-03): distingue «RRHH
            // vio el hotel entero» de «un responsable vio su cocina», que ante
            // una brecha (RL-15) no es lo mismo.
            'scope' => $query->scope->isUnrestricted() ? 'all' : 'departments',
        ]);

        return $board;
    }
}

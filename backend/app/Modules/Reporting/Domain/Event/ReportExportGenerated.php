<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * El fichero del informe en diferido existe en el disco (**RF-IN-06**, RS-05).
 *
 * ## El actor es quien lo PIDIO, aunque el asiento lo escriba el trabajador
 *
 * Sin eso saldria firmado como `system` —en la cola no hay sesion que
 * `CurrentAuditContext` pueda leer— y responder «¿quien se llevo estas horas?»
 * exigiria emparejar a mano este asiento con el `report_export.requested` de
 * unos minutos antes. Por eso `requestedByUserId` viaja dentro del evento.
 *
 * ## La lista de afectados va aqui, acotada
 *
 * Con `group_by = employee`, el fichero contiene las horas de personas
 * identificadas y RL-15 obliga a poder decir de quienes. Se enumeran **solo
 * cuando el conjunto es pequeño**, con el mismo tope y el mismo motivo que
 * `GeneratePeriodReport::affectedSubjects()`: por debajo del tope el informe es
 * de un equipo concreto y saber de quien eran las horas es la pregunta; por
 * encima, el recuento y el alcance la contestan mejor que quinientos
 * identificadores, y enumerarlos convertiria el trail —cuatro años de retencion
 * (RL-02)— en una segunda copia de la plantilla.
 *
 * Identificadores, **nunca nombres** (regla dura 21). Y nunca la ruta absoluta
 * del fichero: solo su nombre, que es lo que permite reconocerlo en una
 * conversacion.
 */
final readonly class ReportExportGenerated implements DomainEvent
{
    /**
     * @param  list<string>  $employeeUuids  Vacio cuando el informe no es por empleado o cuando
     *                                       el conjunto supera el tope de enumeracion.
     */
    public function __construct(
        public string $uuid,
        public string $kind,
        public string $format,
        public string $fileName,
        public string $sha256,
        public int $sizeBytes,
        public int $rowCount,
        public string $expiresAt,
        public array $employeeUuids,
        public int $requestedByUserId,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventName(): string
    {
        return 'reporting.report_export_generated';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}

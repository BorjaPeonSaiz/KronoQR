<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Listener;

use App\Modules\Compliance\Application\Command\RecordAuditEntryCommand;
use App\Modules\Compliance\Application\UseCase\RecordAuditEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use App\Modules\Compliance\Infrastructure\Audit\CurrentAuditContext;
use App\Modules\Product\Domain\Event\ComplianceThresholdChanged;

/**
 * Sella en `audit_log` el cambio de un campo del perfil de cumplimiento
 * (**RF-PD-07**, RL-04, regla dura 6, `/revision-cumplimiento` bloque E).
 *
 * ## Por que tiene relevancia legal, y mas que ningun otro ajuste
 *
 * El perfil decide **que jornadas se consideran anomalas**. Una inspeccion que
 * pregunte por que una jornada de marzo con once horas de descanso no genero
 * alerta solo se puede contestar si consta que entonces el umbral era otro, quien
 * lo cambio y cuando. Sin el asiento, la respuesta honesta es «no lo sabemos», y
 * eso convierte el registro en una afirmacion sin respaldo.
 *
 * ## `calculation_setting.changed`, que ya existia
 *
 * No se estrena accion. Pertenece a la familia `AuthorityOrCalculationChange` del
 * bloque D —la misma que un cambio de rol, de permiso o del anti-rebote— y la
 * pregunta que responde es literalmente la de esa familia: «¿quien movio las
 * reglas?». Una accion propia obligaria a consultar dos veces para contestarla, y
 * el `subject_type` ya distingue de que se habla.
 *
 * ## `subject_type` es `compliance_profile` y lleva `subject_id`
 *
 * Al contrario que la configuracion de instalacion —cuyo sujeto es una clave de
 * texto y por eso va sin `subject_id`—, aqui el sujeto **es una fila** con
 * identificador estable, el del perfil. Ponerlo permite reconstruir el historial
 * de un perfil concreto sin filtrar por el JSON.
 *
 * ## Sincrono y dentro de la transaccion de quien publica
 *
 * No implementa `ShouldQueue` y no debe hacerlo: si el asiento falla, el cambio
 * de umbral no se guarda (ADR-027). Un umbral legal cambiado sin traza es peor
 * que un cambio que no llega a producirse, porque el segundo se vuelve a
 * intentar y el primero no se descubre.
 *
 * **Por un listener y no por una llamada desde `Product`**: el §1.6 no concede la
 * arista `Product -> Compliance`. Es la misma via que la configuracion de
 * instalacion, el alta de empleado, la credencial, el PIN y el contrato.
 */
final readonly class RecordComplianceProfileChange
{
    public function __construct(
        private RecordAuditEntry $audit,
        private CurrentAuditContext $context,
    ) {}

    public function handle(ComplianceThresholdChanged $event): void
    {
        $this->audit->handle(new RecordAuditEntryCommand(
            // Quien lo hizo lo resuelve la sesion en curso, no el evento: quien
            // hace el cambio no puede declarar quien es.
            actor: $this->context->actor(),
            action: AuditAction::CalculationSettingChanged,
            subject: AuditSubject::of('compliance_profile', $event->profileId),
            payload: AuditPayload::of([
                'field' => $event->field,
                // El antes y el despues, que es lo que hace el asiento
                // reconstruible. Ninguno lleva datos personales: son umbrales
                // legales, el nombre de un convenio y fechas de festivos
                // (regla dura 21).
                'previous_value' => $event->previousValue,
                'new_value' => $event->newValue,
                // Las dos consecuencias, separadas porque quien lee el trail
                // busca una o la otra: «¿cambio esto que alertas saltan?» y
                // «¿cambio esto que se puede borrar?».
                'affects_incident_detection' => $event->affectsIncidentDetection,
                // Explica el `false` de arriba cuando el campo SI gobierna una
                // regla legal: RN-12 se evalua y tiene sus pruebas, pero su
                // apertura de incidencia esta suspendida hasta que el quiosco
                // registre la pausa declarada (ADR-024, tarea 3.5). Sin este
                // dato, este asiento seria indistinguible del de un cambio de
                // nombre del convenio.
                'detection_suspended' => $event->detectionSuspended,
                'affects_retention' => $event->affectsRetention,
                // La decision de retroactividad, escrita en el propio asiento
                // para que quien lo lea dentro de dos años no tenga que buscarla:
                // el umbral nuevo rige desde aqui y el historico no se reproceso.
                'applies_from' => 'change_forward_only',
            ]),
            occurredAt: $event->occurredAt(),
            ip: $this->context->ip(),
            userAgent: $this->context->userAgent(),
        ));
    }
}

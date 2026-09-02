<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Listener;

use App\Modules\Compliance\Application\Command\RecordAuditEntryCommand;
use App\Modules\Compliance\Application\UseCase\RecordAuditEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use App\Modules\Compliance\Infrastructure\Audit\CurrentAuditContext;
use App\Modules\Product\Domain\Event\InstallationSettingChanged;

/**
 * Sella en `audit_log` el cambio de una clave de configuracion de la
 * instalacion (**RF-PD-01**, RL-04, regla dura 6,
 * `/revision-cumplimiento` bloque E).
 *
 * ## Por que tiene relevancia legal
 *
 * Doc 01 §5, nota de `installation_settings`: *«todo cambio queda auditado,
 * porque algunos afectan al calculo de horas»*. La ventana anti-rebote de
 * RF-AT-06 es el caso claro: un escaneo que la ventana se traga no cierra el
 * tramo, y el total de la jornada sale distinto **sin que nadie haya tocado un
 * fichaje**. Quien investigue una discrepancia de nomina seis meses despues
 * necesita poder ver ese cambio con su autor, su momento y su antes y despues.
 *
 * ## `calculation_setting.changed`, que ya existia
 *
 * No se estrena accion. El catalogo la declaro desde la tarea 0.x precisamente
 * para esto, y pertenece a la familia `AuthorityOrCalculationChange` del bloque
 * D, la misma que un cambio de rol o de permiso. Ninguna familia nueva: cambiar
 * el umbral que decide que es un tramo anomalo y cambiar quien puede corregirlo
 * son la misma pregunta —«¿quien movio las reglas?»— y separarlas obligaria a
 * consultar dos veces para responderla.
 *
 * ## Sin `subject_id`
 *
 * `audit_log.subject_id` es un entero y el sujeto de este hecho es una **clave
 * de texto**. Meter el `id` de la fila de `installation_settings` seria peor que
 * no poner nada: ese entero no significa nada para quien lee el trail, cambia si
 * la fila se reescribe y no identifica la clave. La clave va en el payload,
 * donde se lee.
 *
 * ## Sincrono y dentro de la transaccion de quien publica
 *
 * No implementa `ShouldQueue` y no debe hacerlo: si el asiento falla, el cambio
 * de configuracion no se guarda (ADR-027). Un parametro del calculo cambiado sin
 * traza es peor que un cambio que no llega a producirse, porque el segundo se
 * vuelve a intentar y el primero no se descubre.
 *
 * **Por un listener y no por una llamada desde `Product`**: el §1.6 no concede
 * la arista `Product -> Compliance`. Es la misma via del alta de empleado, la
 * credencial, el PIN y el contrato.
 */
final readonly class RecordInstallationSettingChange
{
    public function __construct(
        private RecordAuditEntry $audit,
        private CurrentAuditContext $context,
    ) {}

    public function handle(InstallationSettingChanged $event): void
    {
        $this->audit->handle(new RecordAuditEntryCommand(
            // Quien lo hizo lo resuelve la sesion en curso, no el evento: quien
            // hace el cambio no puede declarar quien es.
            actor: $this->context->actor(),
            action: AuditAction::CalculationSettingChanged,
            subject: AuditSubject::of('installation_setting'),
            payload: AuditPayload::of([
                'key' => $event->key,
                // El antes y el despues, que es lo que hace el asiento
                // reconstruible. Ninguno lleva datos personales: son umbrales,
                // nombres de marca e idiomas (regla dura 21).
                'previous_value' => $event->previousValue,
                'new_value' => $event->newValue,
                // Sobre que actua la clave: `worked_hours`, `compliance_review`
                // o `presentation`.
                'impact' => $event->impact,
                // El booleano que separa «esto pudo cambiar las horas» de «esto
                // cambio un color». Es lo que pide la nota de doc 01 §5 y lo que
                // busca quien llega con una discrepancia de nomina delante.
                'affects_worked_hours' => $event->affectsWorkedHours,
                // Si antes no habia fila y regia el valor de serie del producto.
                // Distingue «se bajo de 12 a 10» de «nunca se habia tocado».
                'was_product_default' => $event->wasProductDefault,
            ]),
            occurredAt: $event->occurredAt(),
            ip: $this->context->ip(),
            userAgent: $this->context->userAgent(),
        ));
    }
}

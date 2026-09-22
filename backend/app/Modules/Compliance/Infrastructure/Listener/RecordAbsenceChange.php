<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Listener;

use App\Modules\Compliance\Application\Command\RecordAuditEntryCommand;
use App\Modules\Compliance\Application\UseCase\RecordAuditEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use App\Modules\Compliance\Infrastructure\Audit\CurrentAuditContext;
use App\Modules\Workforce\Domain\Event\AbsenceCorrected;
use App\Modules\Workforce\Domain\Event\AbsenceRegistered;
use App\Modules\Workforce\Domain\Event\AbsenceVoided;

/**
 * Sella en `audit_log` el alta, la correccion y la anulacion de una ausencia
 * (**RF-GP-04**, regla dura 6, `/revision-cumplimiento` bloque E).
 *
 * ## Por que tiene relevancia legal
 *
 * Una ausencia registrada cambia el resultado del informe de absentismo: los
 * dias que cubre dejan de contar como no justificados. Quien la registra puede
 * convertir cinco faltas en cinco dias de vacaciones sin tocar un solo fichaje,
 * y quien la anula puede hacer lo contrario. Sin traza, ninguna de las dos se
 * puede investigar, y lo que hay al final de esa cadena es un expediente
 * disciplinario o una nomina. Es exactamente la familia «cambia roles, permisos
 * o parametros del calculo» del bloque D.
 *
 * ## Que lleva el asiento y que no
 *
 * Lo suficiente para reconstruir el hecho: a quien —por su UUID publico—, de que
 * tipo, entre que dos dias, en que version y si llevaba nota. Al corregir, ademas
 * el **antes completo** y el motivo; al anular, el motivo.
 *
 * **`note` no entra nunca** (regla dura 21, decision 6 de la ficha 3.10). Puede
 * llevar un diagnostico —una baja medica es dato de salud— y el trail no la
 * necesita para reconstruir el cambio: de ella consta `has_note`, que es lo que
 * permite explicar por que una ausencia de tipo `other` era valida.
 *
 * **El tipo si entra, y la asimetria es deliberada.** Sin el, el asiento no
 * describe el hecho: «hubo una ausencia de cinco dias» no dice nada. Y
 * `audit_log` es el registro legal con acceso restringido y cuatro años de
 * conservacion, no un log tecnico que viaja al fabricante. En logs, mensajes de
 * excepcion y `error_events` no aparece ni el tipo ni la nota.
 *
 * **Ningun nombre.** La persona viaja por su UUID publico, que es el
 * identificador con el que trabajan la API, el registro legal y el trail.
 *
 * **Sin actor en el evento**: quien lo hizo lo resuelve {@see CurrentAuditContext}
 * de la sesion en curso. Quien hace el cambio no puede declarar quien es.
 *
 * ## Sincrono y dentro de la transaccion de quien publica
 *
 * No implementa `ShouldQueue` y no debe hacerlo: si el asiento falla, la ausencia
 * no se registra (ADR-027). Una ausencia sin traza es peor que una que no llega
 * a registrarse, porque la segunda se vuelve a intentar y la primera no se
 * descubre.
 *
 * **Por un listener y no por una llamada desde `Workforce`**: el §1.6 no concede
 * la arista `Workforce -> Compliance`. Es la misma via del alta, la baja, el PIN
 * y el contrato.
 */
final readonly class RecordAbsenceChange
{
    /** Vocabulario estable del trail, en ingles. */
    private const string SUBJECT = 'absence';

    public function __construct(
        private RecordAuditEntry $audit,
        private CurrentAuditContext $context,
    ) {}

    public function registered(AbsenceRegistered $event): void
    {
        $this->audit->handle(new RecordAuditEntryCommand(
            actor: $this->context->actor(),
            action: AuditAction::AbsenceRegistered,
            // Sin `subject_id`: la columna es un entero y el evento transporta el
            // identificador PUBLICO. Resolver aqui la clave interna obligaria a
            // `Compliance` a consultar una tabla de `Workforce`, que es la
            // dependencia que este listener evita.
            subject: AuditSubject::of(self::SUBJECT),
            payload: AuditPayload::of([
                ...$this->factOf(
                    $event->absenceUuid,
                    $event->employeeUuid,
                    $event->type,
                    $event->startsOn,
                    $event->endsOn,
                    $event->version,
                    $event->hasNote,
                ),
                // De donde vino. Es lo que distingue «RRHH tecleo esta baja» de
                // «entro en la carga del cuadrante del martes», que ante una
                // revision no es lo mismo.
                'source' => $event->source,
                // La huella ata las cuarenta lineas de una carga entre si. El
                // NOMBRE del fichero no: lo pone quien sube y puede llevar dentro
                // el nombre de una persona.
                'file_sha256' => $event->fileSha256,
            ]),
            occurredAt: $event->occurredAt(),
            ip: $this->context->ip(),
            userAgent: $this->context->userAgent(),
        ));
    }

    public function corrected(AbsenceCorrected $event): void
    {
        $this->audit->handle(new RecordAuditEntryCommand(
            actor: $this->context->actor(),
            action: AuditAction::AbsenceCorrected,
            subject: AuditSubject::of(self::SUBJECT),
            payload: AuditPayload::of([
                ...$this->factOf(
                    $event->absenceUuid,
                    $event->employeeUuid,
                    $event->type,
                    $event->startsOn,
                    $event->endsOn,
                    $event->version,
                    $event->hasNote,
                ),
                // A que version sustituye: sin esto, el asiento describiria un
                // alta y no una correccion.
                'supersedes_uuid' => $event->supersedesUuid,
                // Y el ANTES completo. Con solo el estado final, «¿quien alargo
                // esta baja y de cuanto a cuanto?» solo se contesta
                // reconstruyendo la cadena de versiones desde la primera.
                'previous_type' => $event->previousType,
                'previous_starts_on' => $event->previousStartsOn,
                'previous_ends_on' => $event->previousEndsOn,
                'reason' => $event->reason,
            ]),
            occurredAt: $event->occurredAt(),
            ip: $this->context->ip(),
            userAgent: $this->context->userAgent(),
        ));
    }

    public function voided(AbsenceVoided $event): void
    {
        $this->audit->handle(new RecordAuditEntryCommand(
            actor: $this->context->actor(),
            action: AuditAction::AbsenceVoided,
            subject: AuditSubject::of(self::SUBJECT),
            payload: AuditPayload::of([
                ...$this->factOf(
                    $event->absenceUuid,
                    $event->employeeUuid,
                    $event->type,
                    $event->startsOn,
                    $event->endsOn,
                    $event->version,
                    $event->hasNote,
                ),
                'reason' => $event->reason,
            ]),
            occurredAt: $event->occurredAt(),
            ip: $this->context->ip(),
            userAgent: $this->context->userAgent(),
        ));
    }

    /**
     * El nucleo comun de los tres asientos: **que ausencia, de quien y cuando**.
     *
     * Escrito una sola vez para que las tres acciones se puedan comparar entre
     * si con la misma consulta. Si cada una nombrara sus claves a su manera,
     * reconstruir la historia de una ausencia obligaria a tres consultas
     * distintas.
     *
     * @return array<string, scalar|null>
     */
    private function factOf(
        string $absenceUuid,
        string $employeeUuid,
        string $type,
        string $startsOn,
        string $endsOn,
        int $version,
        bool $hasNote,
    ): array {
        return [
            'absence_uuid' => $absenceUuid,
            'employee_uuid' => $employeeUuid,
            'type' => $type,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'version' => $version,
            // SI LA HABIA, NUNCA SU CONTENIDO (regla dura 21).
            'has_note' => $hasNote,
        ];
    }
}

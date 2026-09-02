<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Se ha cerrado el asistente de puesta en marcha (**RF-PD-03**, regla dura 6).
 *
 * ## Por que esto se audita
 *
 * Porque **es irreversible, y la irreversibilidad se justifica con RL-04**: el
 * asistente no se reabre precisamente para que nadie pueda reconfigurar la
 * instalacion —zona horaria del centro incluida— sin dejar asiento. Un acto que
 * se justifica por el trail tiene que estar **en** el trail; si no, la
 * justificacion es una promesa sin conducta.
 *
 * Ademas fija un momento con significado: a partir de aqui, cada cambio de
 * configuracion pasa por su recurso y deja su propio asiento. Sin este, el trail
 * empieza a media puesta en marcha y no dice cuando termino.
 *
 * ## Que viaja
 *
 * **Los pasos que se omitieron.** Es lo unico que no se puede reconstruir
 * despues: `setup_progress` conserva la marca, pero es una tabla normal —se
 * puede editar— mientras que `audit_log` es solo-append y encadenado por hash.
 * Saber que se cerro sin activar licencia y sin vincular ningun quiosco explica
 * media conversacion de soporte de la primera semana.
 *
 * **Ningun dato personal** (regla dura 21): nombres de paso y nada mas.
 *
 * ## Y **quien** lo cerro no viaja aqui
 *
 * Una sola fuente para el actor del asiento, y es la sesion en curso
 * (`Compliance\Infrastructure\Audit\CurrentAuditContext`), igual que en
 * `InstallationSettingChanged` y en `SiteConfigured`: quien hace el cambio no
 * puede declarar quien es. Con el actor tambien en el evento habria dos fuentes
 * que podrian discrepar, y la discrepancia solo se descubriria leyendo un
 * `audit_log` de hace meses.
 *
 * `setup_progress.recorded_by_user_id` es otra cosa —la marca operativa de la
 * tabla— y ese si lo pasa el caso de uso al repositorio.
 */
final readonly class SetupCompleted implements DomainEvent
{
    /**
     * @param  list<string>  $skippedSteps
     */
    public function __construct(
        public array $skippedSteps,
        private DateTimeImmutable $occurredAt,
    ) {}

    public function eventName(): string
    {
        return 'product.setup_completed';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}

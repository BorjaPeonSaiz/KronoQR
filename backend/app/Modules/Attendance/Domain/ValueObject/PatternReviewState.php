<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\ValueObject;

use App\Modules\Attendance\Domain\Policy\CredentialPatternPolicy;
use DateTimeImmutable;

/**
 * Lo que ya se ha hecho con los indicios de una persona: **lo que hay abierto y
 * cuando se cerro lo ultimo** (RF-PR-06, decision 13c de la ficha 3.11).
 *
 * ## Que problema resuelve
 *
 * La restriccion `one_incident_per_finding` deduplica por jornada, y eso basta
 * cuando el hallazgo describe una jornada concreta —un turno abierto, un tramo
 * largo—. Un patron no: describe un **habito** que sigue ocurriendo, y su
 * jornada avanza cada noche. Sin este estado, la misma coincidencia de las
 * mismas dos personas abria una incidencia `high` nueva cada madrugada, y la
 * bandeja que el runbook manda vaciar crecia sola.
 *
 * ## Las dos mitades, y por que son dos
 *
 * - **`hasOpen`**: el indicio ya esta sobre la mesa de alguien. No se vuelve a
 *   emitir; las contrapartes nuevas entraran en la pasada siguiente a que
 *   alguien lo trabaje. Repetirlo no aporta un dato, aporta una fila.
 * - **`lastResolvedAt`**: quien descarto «llegan juntos en coche» no puede
 *   volver a ver la misma incidencia a la noche siguiente. Solo cuentan los dias
 *   de coincidencia **posteriores** al cierre, asi que hacen falta `minRepeats`
 *   dias nuevos para reabrir — y si el patron persiste, quien la revise la
 *   volvera a ver, con datos nuevos y no con los que ya descarto.
 *
 * **La resolucion no borra el hecho**: los escaneos siguen en `scan_events` y la
 * incidencia cerrada sigue en la bandeja con su nota (regla dura 5). Lo unico
 * que este objeto decide es si hay algo **nuevo** que contar.
 *
 * Lo resuelve el caso de uso por su puerto y lo recibe
 * {@see CredentialPatternPolicy} **ya resuelto**: el dominio no consulta la
 * bandeja, igual que no consulta la configuracion (regla dura 14).
 */
final readonly class PatternReviewState
{
    public function __construct(
        /** Hay una incidencia de este patron **abierta** para esta persona. */
        public bool $hasOpen = false,
        /**
         * Cuando se cerro la ultima —`resolved` o `dismissed`—, en UTC. `null`
         * si nunca hubo ninguna.
         */
        public ?DateTimeImmutable $lastResolvedAt = null,
    ) {}

    /** Ni indicio abierto ni ninguno cerrado: todo lo observado cuenta. */
    public static function untouched(): self
    {
        return new self;
    }

    /**
     * Si una coincidencia observada en ese instante cuenta para el recuento.
     *
     * **Estricto**: una coincidencia exactamente en el instante del cierre ya
     * estaba delante de quien la cerro.
     */
    public function counts(DateTimeImmutable $at): bool
    {
        return ! $this->lastResolvedAt instanceof DateTimeImmutable
            || $at->getTimestamp() > $this->lastResolvedAt->getTimestamp();
    }
}

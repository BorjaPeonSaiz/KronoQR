<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Un campo del perfil de cumplimiento ha cambiado de valor (**RF-PD-07**,
 * RL-04, regla dura 6).
 *
 * ## Por que este hecho se audita, y por que mas que ningun otro ajuste
 *
 * Cambiar `min_rest_hours` cambia **que jornadas se consideran anomalas**. Una
 * inspeccion que pregunte «¿por que esta jornada de marzo no genero alerta de
 * descanso insuficiente?» solo se puede contestar si consta que el umbral era
 * otro, quien lo cambio y cuando. Sin el asiento, la respuesta honesta es «no lo
 * sabemos», y eso convierte un registro con valor legal en una afirmacion sin
 * respaldo.
 *
 * ## Uno por campo, no uno por peticion
 *
 * Mismo criterio que {@see InstallationSettingChanged}: un `PATCH` que cambia
 * tres campos produce tres eventos y tres asientos, cada uno con su antes, su
 * despues y sus dos booleanos. Un asiento por peticion obligaria a decidir un
 * unico `affects_incident_detection` para un conjunto mixto —el nombre del
 * convenio y el descanso minimo cambiados a la vez— y ese booleano perderia
 * justo el matiz para el que existe.
 *
 * ## Los dos booleanos, que no son el mismo de la configuracion
 *
 * `affectsIncidentDetection` responde «¿cambia esto que alertas saltan?» y
 * `affectsRetention` responde «¿cambia esto que se puede borrar?». Son dos
 * consecuencias distintas y quien lee el trail busca una o la otra. Ninguno de
 * los campos del perfil cambia los **minutos** que se calculan —las reglas de
 * cumplimiento clasifican, no corrigen (doc 01 §4, regla dura 19)— asi que el
 * `affects_worked_hours` de la configuracion de instalacion no aplica aqui, y
 * escribirlo siempre a `false` seria ruido.
 *
 * ## Y un tercero que explica el `false`
 *
 * `detectionSuspended` existe porque `affectsIncidentDetection` tiene que decir
 * la verdad de **hoy**, y hoy RN-12 se evalua pero no abre incidencia
 * (ADR-024, RF-AT-12, tarea 3.5). Sin el matiz, el asiento de un cambio de
 * `break_required_after_hours` seria indistinguible del de un cambio de nombre
 * del convenio, y son cosas muy distintas: la primera vuelve a mover alertas en
 * cuanto llegue la 3.5. Los dos se derivan de
 * `Shared\Domain\ValueObject\ComplianceRuleSuspension`, que es donde la decision
 * vive, para que reactivar la regla no exija tocar nada de aqui.
 */
final readonly class ComplianceThresholdChanged implements DomainEvent
{
    /**
     * @param  int|string|list<string>  $previousValue  lo que regia antes
     * @param  int|string|list<string>  $newValue  lo que queda escrito
     */
    public function __construct(
        /** El identificador del perfil, que es el sujeto real del cambio. */
        public int $profileId,
        /** El campo, tal como lo nombran el esquema y el contrato (`min_rest_hours`). */
        public string $field,
        public int|string|array $previousValue,
        public int|string|array $newValue,
        /** Si cambia que incidencias abre la revision diaria (RN-10, RN-11, RN-12). */
        public bool $affectsIncidentDetection,
        /**
         * Si el campo gobierna una regla que hoy **no abre incidencias** aunque
         * se evalue (RN-12 hasta la tarea 3.5).
         *
         * Es lo que explica un `affects_incident_detection: false` sobre un
         * umbral legal: sin este dato, quien lea el asiento dentro de dos años no
         * puede distinguir «este campo nunca movio alertas» de «las movia, pero
         * en ese momento la regla estaba suspendida», y esa diferencia es
         * exactamente la que le interesa.
         */
        public bool $detectionSuspended,
        /** Si cambia que datos considera vencidos la purga por retencion (RL-02). */
        public bool $affectsRetention,
        private DateTimeImmutable $occurredAt,
    ) {}

    /**
     * Nombre estable. No se deriva del nombre de la clase (doc 02 §1.6):
     * renombrar una clase no puede cambiar lo que ya esta escrito en un registro
     * con valor legal.
     */
    public function eventName(): string
    {
        return 'product.compliance_threshold_changed';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}

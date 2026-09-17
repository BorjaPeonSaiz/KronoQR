<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

use App\Modules\Product\Application\Command\UpdateComplianceProfileCommand;
use App\Modules\Product\Application\Port\ComplianceProfileMetrics;
use App\Modules\Product\Application\Port\ComplianceProfileRepository;
use App\Modules\Product\Application\Port\ProductEventPublisher;
use App\Modules\Product\Domain\Event\ComplianceThresholdChanged;
use App\Modules\Product\Domain\Exception\InvalidComplianceProfileValue;
use App\Modules\Product\Domain\ValueObject\ComplianceProfileField;
use App\Modules\Product\Domain\ValueObject\ComplianceProfileSnapshot;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\InstallationSiteProvider;
use App\Modules\Shared\Application\Port\OperationalSettingsProvider;
use App\Modules\Shared\Domain\ValueObject\ComplianceRuleSuspension;
use Illuminate\Database\ConnectionInterface;

/**
 * Cambia el perfil de cumplimiento del centro y deja traza de cada campo
 * (**RF-PD-07**, RL-04, regla dura 6).
 *
 * ## El orden de los pasos significa algo, igual que en la configuracion
 *
 * 1. **Se abre la transaccion y se toma el candado.** Ver abajo.
 * 2. **Se lee el perfil sin cache** (`forSiteForWrite`). Sin el estado actual no
 *    se puede saber que cambia de verdad ni cual era el valor anterior, que es la
 *    mitad del asiento.
 * 3. **Se valida el PERFIL COMPLETO** (`with()`), no los campos sueltos. La
 *    invariante «la semanal no puede quedar por debajo de la diaria» habla de dos
 *    campos que pueden no viajar juntos. **Antes de escribir nada.**
 * 4. **Se descarta lo que no cambia.** Abrir la pantalla y pulsar «guardar» no
 *    escribe ni fila ni asiento.
 * 5. **Se escribe y se audita, en la misma transaccion.** Un umbral legal
 *    cambiado sin traza es un cambio que nadie puede explicar despues (ADR-027).
 *
 * ## El candado
 *
 * Mismo razonamiento que en `UpdateSettingsHandler` y misma convencion de clave
 * —fase y tarea—, con un numero propio: el espacio de `pg_advisory_lock` es
 * global a la base de datos y dos usos con el mismo numero se bloquearian entre
 * si sin ninguna relacion.
 *
 * Aqui protege ademas una carrera concreta: dos `PATCH` simultaneos, uno que
 * baja `max_weekly_hours` a 10 y otro que sube `max_daily_hours` a 12. Cada uno
 * comprueba su invariante contra un perfil que el otro esta a punto de cambiar, y
 * las dos escrituras son validas por separado.
 *
 * ## Sin retroactividad, y esto no es una omision
 *
 * El valor nuevo rige **desde el cambio**. Este caso de uso no reprocesa el
 * historico, no cierra incidencias abiertas ni reabre las resueltas: la revision
 * diaria volvera a evaluar su ventana con el umbral que encuentre vigente. Es la
 * decision escrita en el doc 01 §4 y en `docs/cliente/configuracion.md`, y el
 * motivo es el mismo que acota esa ventana: una incidencia abierta hoy sobre una
 * jornada de hace dos años no describe nada que nadie pueda corregir, y cerrar
 * automaticamente las que dejaron de serlo destruiria el rastro de una decision
 * que tomo una persona (regla dura 5).
 *
 * ## Sin facades y sin reloj propio
 *
 * `Application` no usa facades (doc 02 §3.5) y el instante entra por el puerto
 * `Clock` (regla dura 2): sin eso, «cuando se cambio este umbral» no se podria
 * probar de forma determinista, y esa fecha es la que se contrasta con la nomina
 * cuando algo no cuadra.
 */
final readonly class UpdateComplianceProfileHandler
{
    /**
     * Clave del candado consultivo del perfil de cumplimiento.
     *
     * Fase y tarea (5.2), como la de la configuracion de instalacion (5.1).
     */
    private const int LOCK_KEY = 5_020_001;

    public function __construct(
        private ComplianceProfileRepository $profiles,
        private InstallationSiteProvider $sites,
        private ProductEventPublisher $events,
        private ComplianceProfileMetrics $metrics,
        private Clock $clock,
        private ConnectionInterface $connection,
        /**
         * Los ajustes operativos de la instalacion, de los que sale si el
         * fichaje de pausa esta activado (RF-AT-12, decision 8 de la ficha 3.5).
         *
         * `Product` implementa este puerto —{@see \App\Modules\Product\
         * Infrastructure\Adapter\DbOperationalSettingsProvider}— y aqui lo
         * consume: el asiento de `audit_log` tiene que decir la verdad sobre si
         * cambiar el umbral de RN-12 mueve alguna alerta, y esa verdad depende
         * de un ajuste del hotel. Sin esto, el asiento de un registro con valor
         * legal afirmaria `affects_incident_detection` sobre una regla que en
         * esta instalacion no abre nada.
         */
        private OperationalSettingsProvider $settings,
    ) {}

    /**
     * `null` si la instalacion no tiene centro o no tiene ningun perfil: el
     * borde lo traduce en `404`, igual que en la lectura.
     *
     * @throws InvalidComplianceProfileValue cuando un valor no cumple lo que su campo declara
     */
    public function handle(UpdateComplianceProfileCommand $command): ?ComplianceProfileSnapshot
    {
        $site = $this->sites->installationSite();

        if ($site === null) {
            return null;
        }

        // Una sola lectura para todo el cambio (regla dura 14): el asiento y la
        // metrica tienen que describir el mismo estado de la instalacion, y
        // preguntarlo dos veces podria dar dos respuestas si alguien activa el
        // fichaje de pausa entre medias.
        $suspension = ComplianceRuleSuspension::forInstallation(
            $this->settings->forSite($site->id)->breakClockingEnabled,
        );

        /** @var list<ComplianceProfileField> $changed */
        $changed = [];

        $profile = $this->connection->transaction(function () use ($command, $site, $suspension, &$changed): ?ComplianceProfileSnapshot {
            $this->connection->statement('SELECT pg_advisory_xact_lock(?)', [self::LOCK_KEY]);

            $current = $this->profiles->forSiteForWrite($site->id);

            if ($current === null) {
                return null;
            }

            // Valida el perfil entero. Se hace antes de mirar que cambia para que
            // un valor imposible sea un `422` aunque venga acompañado de campos
            // que si cambian.
            $updated = $current->with($command->values);

            $changed = $current->fieldsThatChange($command->values);

            if ($changed === []) {
                return $current;
            }

            // Bajo el candado, para que no haya ventana entre comprobar y
            // escribir. El `UNIQUE` de la columna sigue siendo la garantia; esto
            // es lo que hace que su violacion se lea como un `422` con el campo
            // señalado en vez de como una averia.
            if ($updated->name !== $current->name && $this->profiles->nameIsTakenByAnotherProfile($updated->id, $updated->name)) {
                throw InvalidComplianceProfileValue::nameAlreadyUsed($updated->name);
            }

            $this->profiles->save($updated, $command->actorUserId, $this->clock->now());
            $this->publish($changed, $current, $updated, $suspension);

            // Se relee dentro de la misma transaccion en lugar de devolver
            // `$updated`: asi la respuesta lleva la marca de modificacion que
            // acaba de escribirse y es exactamente lo que quedo en la fila, no
            // una reconstruccion que podria diferir el dia que alguien añada una
            // columna derivada. Es el mismo criterio que la tarea 5.1.
            return $this->profiles->forSiteForWrite($site->id) ?? $updated;
        });

        // FUERA de la transaccion, y esto es la mitad del contrato del puerto:
        // cuando se llega aqui las filas estan escritas y sus asientos
        // confirmados. Contar dentro dejaria la serie incrementada por una
        // transaccion que despues se revierte —el contador no se deshace— y
        // ademas alargaria el candado por un `INCRBY` que no tiene por que estar
        // dentro.
        $this->observe($changed, $suspension);

        return $profile;
    }

    /**
     * Un evento por campo cambiado, con el antes y el despues.
     *
     * @param  list<ComplianceProfileField>  $changed
     */
    private function publish(
        array $changed,
        ComplianceProfileSnapshot $current,
        ComplianceProfileSnapshot $updated,
        ComplianceRuleSuspension $suspension,
    ): void {
        $at = $this->clock->now();
        $events = [];

        foreach ($changed as $field) {
            $events[] = new ComplianceThresholdChanged(
                profileId: $updated->id,
                field: $field->value,
                previousValue: $current->valueOf($field),
                newValue: $updated->valueOf($field),
                affectsIncidentDetection: $field->affectsIncidentDetection($suspension),
                affectsComplianceView: $field->affectsComplianceView(),
                detectionSuspended: $field->governsSuspendedRule($suspension),
                affectsRetention: $field->affectsRetention(),
                occurredAt: $at,
            );
        }

        $this->events->publish(...$events);
    }

    /**
     * `compliance_profile_changes_total{effect}` (doc 02 §8.2).
     *
     * **El efecto `compliance_view` es de la tarea 3.4 y no amplia el catalogo**:
     * la serie ya declara la etiqueta `effect` y lo unico que cambia es que tiene
     * un valor mas. Hacia falta porque los dos efectos que habia no saben decir
     * «mueve los avisos de RRHH pero no abre incidencias», que es exactamente lo
     * que hacen `max_weekly_hours` y `week_starts_on` desde RN-17: sin el, un
     * cambio suyo solo contaria en `any` y seria indistinguible de renombrar el
     * convenio. Un cambio puede contar en varios efectos a la vez, que es lo que
     * el docblock del adaptador ya advierte: son consecuencias, no categorias
     * excluyentes.
     *
     * **Fuera de la transaccion** y sin poder romperla: cuando se llega aqui las
     * filas estan escritas y sus asientos confirmados. Un contador incrementado
     * dentro de una transaccion que despues se revierte no se deshace, y
     * convertir un cambio ya guardado en un `500` por no poder contar invitaria a
     * repetirlo. El adaptador ademas se traga cualquier fallo del soporte.
     *
     * @param  list<ComplianceProfileField>  $changed
     */
    private function observe(array $changed, ComplianceRuleSuspension $suspension): void
    {
        $detection = 0;
        $complianceView = 0;
        $retention = 0;

        foreach ($changed as $field) {
            if ($field->affectsIncidentDetection($suspension)) {
                $detection++;
            }

            if ($field->affectsComplianceView()) {
                $complianceView++;
            }

            if ($field->affectsRetention()) {
                $retention++;
            }
        }

        $this->metrics->profileChanged(count($changed), $detection, $complianceView, $retention);
    }
}

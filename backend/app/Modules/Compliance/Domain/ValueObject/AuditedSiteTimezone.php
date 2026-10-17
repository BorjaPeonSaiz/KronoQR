<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

use DateTimeZone;

/**
 * La zona horaria de un centro frente a su auditoria (RN-23, ADR-056, ADR-057
 * §4; bloque 2 de la 2.3.0).
 *
 * ## Que se compara
 *
 * Desde el bloque 2 de la 2.3.0 la base de datos no deja cambiar la zona con
 * registro horario salvo por la funcion `site_correct_timezone`, que la
 * aplicacion puede ejecutar, que **no escribe asiento** (no puede firmar por
 * nadie: el asiento lo deja la orden de consola) y que **apunta cada cambio** en
 * la traza solo-append `site_timezone_changes`. Sin registro horario, el
 * `UPDATE` es legitimo. Se miran tres cosas, y las tres salen como
 * `site_timezone_differs_from_audit`, con el campo que no cuadra:
 *
 * - `timezone`: la zona de `sites` no es la del ultimo asiento `site.created`
 *   o `site.updated` del centro;
 * - `site_timezone_changes`: una fila de la traza no tiene su `site.updated`
 *   firmado (motivo, recuento y cuenta) con el mismo antes y despues. Es la
 *   huella de la funcion llamada fuera de la orden, tambien de un A→B→A que
 *   deja la zona como estaba;
 * - `correction`: un `site.updated` que cambia la zona despues del primer
 *   tramo sin la firma de una correccion: el panel no puede escribirlo (`409`),
 *   asi que lo escribio otro.
 *
 * **Una sola clase y no tres** porque son el mismo hecho —la historia de la
 * zona del centro no cuadra con su auditoria—, se investigan con la misma
 * seccion del runbook y suenan con la misma alerta; el campo dice cual de las
 * tres comprobaciones fallo.
 *
 * ## Lo que no se puede verificar
 *
 * - Un centro **sin ningun asiento `site.*`**: uno creado antes de la tarea
 *   5.5, o cuyo asiento se fue con la particion de su año (ADR-027). No hay con
 *   que comparar la zona.
 * - Una fila de la traza **de un año cuya particion de `audit_log` ya se
 *   purgo** (el ultimo año sellado o antes): su asiento, si lo hubo, ya no
 *   esta.
 *
 * Ninguno de los dos se denuncia: se cuentan como **no verificables**
 * ({@see SiteTimezoneAssessment}) para que la comprobacion no se apague en
 * silencio.
 *
 * Lleva el identificador del centro y el del asiento, **nunca la zona**, en lo
 * que sale hacia el log ({@see WorkRecordDiscrepancy}).
 */
final readonly class AuditedSiteTimezone
{
    /**
     * @param  list<AuditedSiteTimezoneChange>  $auditedChanges  Los `site.updated` del centro, en el orden de la cadena.
     * @param  list<RecordedSiteTimezoneChange>  $recordedChanges  Las filas de `site_timezone_changes` del centro.
     */
    public function __construct(
        public int $siteId,
        /** `sites.timezone`, tal cual. */
        public string $recordedTimezone,
        /** El `id` del ultimo asiento `site.created` o `site.updated` del centro, o `null` si no hay. */
        public ?int $auditEntryId,
        /** `new_value.timezone` de ese asiento, o `null` si no lo trae. */
        public ?string $auditedTimezone,
        public array $auditedChanges = [],
        public array $recordedChanges = [],
    ) {}

    /**
     * @param  array<array-key, mixed>|null  $payload  El del ultimo asiento, o `null` si no hay
     * @param  list<AuditedSiteTimezoneChange>  $auditedChanges
     * @param  list<RecordedSiteTimezoneChange>  $recordedChanges
     */
    public static function fromEntry(
        int $siteId,
        string $recordedTimezone,
        ?int $auditEntryId,
        ?array $payload,
        array $auditedChanges = [],
        array $recordedChanges = [],
    ): self {
        $newValue = $payload['new_value'] ?? null;
        $timezone = \is_array($newValue) ? ($newValue['timezone'] ?? null) : null;

        return new self(
            $siteId,
            $recordedTimezone,
            $auditEntryId,
            \is_string($timezone) ? $timezone : null,
            $auditedChanges,
            $recordedChanges,
        );
    }

    /**
     * La discrepancia de la zona **actual**, si no es la de su ultimo asiento.
     * Un asiento que no dice zona tampoco la justifica; un centro sin asiento
     * no se denuncia (es no verificable, {@see self::assess()}).
     */
    public function discrepancy(): ?WorkRecordDiscrepancy
    {
        if ($this->auditEntryId === null || $this->auditedTimezone === $this->recordedTimezone) {
            return null;
        }

        return $this->discrepancyOf('timezone', $this->auditEntryId);
    }

    /**
     * Las tres comprobaciones y lo que queda sin verificar.
     *
     * @param  list<int>  $sealedAuditYears  Años de `audit_log` purgados con su ancla (ADR-027).
     */
    public function assess(array $sealedAuditYears): SiteTimezoneAssessment
    {
        $current = $this->discrepancy();
        [$unmatched, $unverifiableChanges] = $this->unmatchedChanges($sealedAuditYears);

        return new SiteTimezoneAssessment(
            [
                ...($current instanceof WorkRecordDiscrepancy ? [$current] : []),
                ...$this->unsignedChanges(),
                ...$unmatched,
            ],
            $this->auditEntryId === null,
            $unverifiableChanges,
        );
    }

    /**
     * Los `site.updated` que cambian la zona con registro horario sin la firma
     * de la correccion: el panel no los puede escribir.
     *
     * @return list<WorkRecordDiscrepancy>
     */
    private function unsignedChanges(): array
    {
        $found = [];

        foreach ($this->auditedChanges as $change) {
            if ($change->changesTimezone() && $change->afterWorkRecord && ! $change->signed) {
                $found[] = $this->discrepancyOf('correction', $change->auditEntryId);
            }
        }

        return $found;
    }

    /**
     * Cada fila de la traza, con su asiento firmado. Se empareja por «de -> a»
     * y se cuenta: dos A->B necesitan dos asientos A->B. Los asientos firmados
     * sin fila (correcciones de la 2.2.1, anteriores a la traza) no se
     * denuncian. Las filas de un año cuya auditoria ya se purgo no se pueden
     * cotejar y se cuentan aparte.
     *
     * @param  list<int>  $sealedAuditYears
     * @return array{0: list<WorkRecordDiscrepancy>, 1: int}
     */
    private function unmatchedChanges(array $sealedAuditYears): array
    {
        $lastSealedYear = $sealedAuditYears === [] ? null : max($sealedAuditYears);
        $signed = $this->signedChangeCounts();
        $found = [];
        $unverifiable = 0;

        foreach ($this->recordedChanges as $row) {
            // Las particiones de `audit_log` son por año UTC (ADR-027).
            if ($lastSealedYear !== null && (int) $row->changedAt->setTimezone(new DateTimeZone('UTC'))->format('Y') <= $lastSealedYear) {
                $unverifiable++;
            } elseif (($signed[$row->key()] ?? 0) > 0) {
                $signed[$row->key()]--;
            } else {
                $found[] = $this->discrepancyOf('site_timezone_changes', null);
            }
        }

        return [$found, $unverifiable];
    }

    /**
     * Cuantos asientos firmados hay de cada «de -> a».
     *
     * @return array<string, int>
     */
    private function signedChangeCounts(): array
    {
        $signed = [];

        foreach ($this->auditedChanges as $change) {
            if ($change->signed && $change->changesTimezone()) {
                $signed[$change->key()] = ($signed[$change->key()] ?? 0) + 1;
            }
        }

        return $signed;
    }

    private function discrepancyOf(string $field, ?int $auditEntryId): WorkRecordDiscrepancy
    {
        return new WorkRecordDiscrepancy(
            shiftEntryUuid: null,
            kind: WorkRecordDiscrepancyKind::SiteTimezoneDiffersFromAudit,
            fields: [$field],
            auditEntryId: $auditEntryId,
            siteId: $this->siteId,
        );
    }
}

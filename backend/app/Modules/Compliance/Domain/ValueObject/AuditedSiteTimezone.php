<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * La zona horaria de un centro frente a la del ultimo asiento que la fijo o la
 * corrigio (RN-23, ADR-056, ADR-057 §4; bloque 2 de la 2.3.0).
 *
 * ## Por que la concilia la conciliacion del registro horario
 *
 * La zona decide a que jornada va cada tramo (RN-05) y con ella se presenta y
 * se exporta todo el historico. Desde el bloque 2 de la 2.3.0 la base de datos
 * no deja cambiarla con registro horario salvo por la funcion
 * `site_correct_timezone`, que la aplicacion puede ejecutar y que **no escribe
 * asiento** (no puede firmar por nadie: el asiento lo deja la orden de
 * consola). Y sin registro horario el `UPDATE` es legitimo. En los dos casos,
 * un cambio hecho por fuera del producto deja la zona de `sites` distinta de la
 * del ultimo asiento `site.created` o `site.updated` del centro, que es lo que
 * se mira aqui.
 *
 * ## Sin asiento no hay nada que comparar
 *
 * Un centro sin ningun asiento `site.*` no es una discrepancia: lo es un centro
 * creado antes de la tarea 5.5 o cuyo asiento se fue con la particion de su año
 * (ADR-027), y en los dos casos no hay con que comparar. Lo mismo que hace
 * {@see WorkRecordReconciliation} con un tramo de un año sellado.
 *
 * Lleva el identificador del centro y el del asiento, **nunca la zona** en lo
 * que sale hacia el log: no es un dato personal, pero la regla de las
 * discrepancias es «nombres de campo, no valores» ({@see WorkRecordDiscrepancy}).
 */
final readonly class AuditedSiteTimezone
{
    public function __construct(
        public int $siteId,
        /** `sites.timezone`, tal cual. */
        public string $recordedTimezone,
        /** El `id` del ultimo asiento `site.created` o `site.updated` del centro, o `null` si no hay. */
        public ?int $auditEntryId,
        /** `new_value.timezone` de ese asiento, o `null` si no lo trae. */
        public ?string $auditedTimezone,
    ) {}

    /**
     * @param  array<array-key, mixed>|null  $payload  El del ultimo asiento, o `null` si no hay
     */
    public static function fromEntry(int $siteId, string $recordedTimezone, ?int $auditEntryId, ?array $payload): self
    {
        $newValue = $payload['new_value'] ?? null;
        $timezone = \is_array($newValue) ? ($newValue['timezone'] ?? null) : null;

        return new self($siteId, $recordedTimezone, $auditEntryId, \is_string($timezone) ? $timezone : null);
    }

    /**
     * La discrepancia, si la zona del centro no es la de su ultimo asiento.
     * Un asiento que no dice zona tampoco la justifica.
     */
    public function discrepancy(): ?WorkRecordDiscrepancy
    {
        if ($this->auditEntryId === null || $this->auditedTimezone === $this->recordedTimezone) {
            return null;
        }

        return new WorkRecordDiscrepancy(
            shiftEntryUuid: null,
            kind: WorkRecordDiscrepancyKind::SiteTimezoneDiffersFromAudit,
            fields: ['timezone'],
            auditEntryId: $this->auditEntryId,
            siteId: $this->siteId,
        );
    }
}

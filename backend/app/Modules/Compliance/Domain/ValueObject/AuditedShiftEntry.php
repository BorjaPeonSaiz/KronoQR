<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * Lo que **el ultimo asiento** `shift_entry.*` de un tramo dice que ese tramo
 * tiene que ser (ADR-057 §4, RL-04).
 *
 * `audit_log` es solo-append y esta encadenada por hash: lo que dice no se
 * puede reescribir sin romper la cadena. Por eso es la referencia contra la que
 * se compara `shift_entries`, que si se puede reescribir con la credencial de la
 * aplicacion. Este objeto traduce el asiento a una expectativa sobre la fila.
 *
 * ## Que asientos hay y que afirma cada uno
 *
 * Los escribe `Compliance\Infrastructure\Listener\RecordShiftEntryAudit`, en la
 * misma transaccion que el tramo:
 *
 * | Asiento | Clave del tramo | Afirma |
 * |---|---|---|
 * | `shift_entry.created` de un fichaje | `shift_entry_uuid` | entrada, sin salida, abierto |
 * | `shift_entry.closed` de un fichaje | `shift_entry_uuid` | entrada y salida; `anomalous` si trae anomalias, si no `closed` |
 * | correccion `created`, `modified` o `closed` | `shift_entry_uuid` (la version que produjo) | las marcas de `after`, su `version` y que hay fila en `shift_corrections` |
 * | correccion `modified` o `closed` | `superseded_shift_entry_uuid` (la version anterior) | las marcas de `before`, estado `superseded`, que la sustituye la version nueva y que esta tiene su fila en `shift_corrections` |
 * | correccion `voided` | `shift_entry_uuid` | las marcas de `before`, estado `voided` y su fila en `shift_corrections` |
 *
 * Un asiento de correccion se distingue del de un fichaje por `reason_code`,
 * que solo llevan las correcciones: los dos comparten `shift_entry.created` y
 * `shift_entry.closed`, y en las dos familias `action` significa cosas
 * distintas (`clock_out` frente a `closed`).
 *
 * ## Lo que no se sabe no se compara
 *
 * Cada campo es nulo cuando el asiento no lo dice: el de un fichaje no lleva
 * `version`, y un asiento de una version anterior del producto puede no llevar
 * `anomalies`. Comparar contra un valor supuesto convertiria cada instalacion
 * antigua en una alerta permanente; no compararlo deja ese campo sin cubrir, y
 * eso es lo que se documenta en el runbook.
 */
final readonly class AuditedShiftEntry
{
    /** Las acciones de `audit_log` que hablan de un tramo. */
    public const array ACTIONS = [
        'shift_entry.created',
        'shift_entry.modified',
        'shift_entry.closed',
        'shift_entry.voided',
    ];

    /** Las dos acciones de correccion que sustituyen una version por otra (ADR-026, ADR-035). */
    public const array SUPERSEDING_ACTIONS = ['shift_entry.modified', 'shift_entry.closed'];

    /**
     * @param  list<string>  $expectedStatuses  Estados que la fila puede tener legitimamente. Vacia si el asiento no lo dice.
     */
    private function __construct(
        /** `audit_log.id` del asiento: identifica la prueba sin copiar su contenido. */
        public int $auditEntryId,
        public string $action,
        public ?string $employeeUuid,
        public ?int $siteId,
        public ?string $workDate,
        public ?string $clockedInAt,
        /** Si el asiento dice algo de la salida. Sin esto, «sin salida» y «no se sabe» serian el mismo nulo. */
        public bool $knowsClockOut,
        public ?string $clockedOutAt,
        public array $expectedStatuses,
        public ?int $version,
        /** La version que tiene que sustituir a esta, o `null` si no la sustituye ninguna. */
        public ?string $supersededByUuid,
        /** Accion que tiene que figurar en `shift_corrections`, o `null` si el asiento es de un fichaje. */
        public ?string $requiredCorrectionAction,
        /** Si esa fila de `shift_corrections` cuelga de la version que sustituyo a esta y no de esta. */
        public bool $correctionOnReplacement,
    ) {}

    /**
     * Interpreta el ultimo asiento de un tramo.
     *
     * @param  array<array-key, mixed>  $payload  `audit_log.payload` ya decodificado.
     * @param  bool  $asSuperseded  El tramo aparece en el asiento como `superseded_shift_entry_uuid`.
     */
    public static function fromLatestEntry(int $auditEntryId, string $action, array $payload, bool $asSuperseded): self
    {
        $employeeUuid = self::string($payload, 'employee_uuid');
        $siteId = self::int($payload, 'site_id');
        $workDate = self::string($payload, 'work_date');

        if ($asSuperseded) {
            return self::supersededBy($auditEntryId, $action, $payload, $employeeUuid, $siteId, $workDate);
        }

        if (\array_key_exists('reason_code', $payload)) {
            return self::fromCorrection($auditEntryId, $action, $payload, $employeeUuid, $siteId, $workDate);
        }

        return self::fromClocking($auditEntryId, $action, $payload, $employeeUuid, $siteId, $workDate);
    }

    /**
     * Si el asiento saca al tramo del conjunto vigente: anulado o sustituido
     * (ADR-026). Un tramo asi ya no suma en ninguna parte.
     */
    public function retires(): bool
    {
        return array_intersect($this->expectedStatuses, ['voided', 'superseded']) !== [];
    }

    /**
     * La version anterior de una correccion `modified` o `closed`: sus marcas
     * son las de `before` y su sustituta es el tramo del asiento.
     *
     * @param  array<array-key, mixed>  $payload
     */
    private static function supersededBy(
        int $auditEntryId,
        string $action,
        array $payload,
        ?string $employeeUuid,
        ?int $siteId,
        ?string $workDate,
    ): self {
        $before = self::marks($payload, 'before');
        $replacementVersion = self::int($payload, 'version');

        return new self(
            auditEntryId: $auditEntryId,
            action: $action,
            employeeUuid: $employeeUuid,
            siteId: $siteId,
            workDate: $workDate,
            clockedInAt: $before['in'],
            knowsClockOut: $before['knowsOut'],
            clockedOutAt: $before['out'],
            expectedStatuses: ['superseded'],
            // La correccion estrena la version siguiente (ADR-035): la anterior
            // es una menos, siempre.
            version: $replacementVersion === null ? null : $replacementVersion - 1,
            supersededByUuid: self::string($payload, 'shift_entry_uuid'),
            requiredCorrectionAction: self::string($payload, 'action'),
            correctionOnReplacement: true,
        );
    }

    /**
     * La version que una correccion produjo, o la que anulo.
     *
     * @param  array<array-key, mixed>  $payload
     */
    private static function fromCorrection(
        int $auditEntryId,
        string $action,
        array $payload,
        ?string $employeeUuid,
        ?int $siteId,
        ?string $workDate,
    ): self {
        $correction = self::string($payload, 'action');
        $voided = $correction === 'voided';
        // Una anulacion no tiene `after`: el tramo conserva las marcas que
        // tenia, que son las de `before` (regla dura 5).
        $marks = self::marks($payload, $voided ? 'before' : 'after');

        return new self(
            auditEntryId: $auditEntryId,
            action: $action,
            employeeUuid: $employeeUuid,
            siteId: $siteId,
            workDate: $workDate,
            clockedInAt: $marks['in'],
            knowsClockOut: $marks['knowsOut'],
            clockedOutAt: $marks['out'],
            expectedStatuses: $voided ? ['voided'] : self::statusesOfAVersion($marks, $payload),
            version: self::int($payload, 'version'),
            supersededByUuid: null,
            requiredCorrectionAction: $correction,
            correctionOnReplacement: false,
        );
    }

    /**
     * Un fichaje del quiosco: la entrada que abrio el tramo o la salida que lo
     * cerro.
     *
     * @param  array<array-key, mixed>  $payload
     */
    private static function fromClocking(
        int $auditEntryId,
        string $action,
        array $payload,
        ?string $employeeUuid,
        ?int $siteId,
        ?string $workDate,
    ): self {
        $marks = match ($action) {
            // La entrada afirma que todavia no hay salida.
            'shift_entry.created' => ['in' => self::string($payload, 'clocked_in_at'), 'knowsOut' => true, 'out' => null],
            'shift_entry.closed' => [
                'in' => self::string($payload, 'clocked_in_at'),
                'knowsOut' => \array_key_exists('clocked_out_at', $payload),
                'out' => self::string($payload, 'clocked_out_at'),
            ],
            default => ['in' => self::string($payload, 'clocked_in_at'), 'knowsOut' => false, 'out' => null],
        };

        return new self(
            auditEntryId: $auditEntryId,
            action: $action,
            employeeUuid: $employeeUuid,
            siteId: $siteId,
            workDate: $workDate,
            clockedInAt: $marks['in'],
            knowsClockOut: $marks['knowsOut'],
            clockedOutAt: $marks['out'],
            expectedStatuses: self::statusesOfAVersion($marks, $payload),
            // El fichaje no apunta la version, y no se supone: ver la cabecera.
            version: null,
            supersededByUuid: null,
            requiredCorrectionAction: null,
            correctionOnReplacement: false,
        );
    }

    /**
     * El estado de una version vigente segun sus marcas: abierta sin salida;
     * con salida, `anomalous` si el asiento trae anomalias y `closed` si no
     * (RN-07, RN-08). Un asiento sin la lista admite las dos.
     *
     * @param  array{in: string|null, knowsOut: bool, out: string|null}  $marks
     * @param  array<array-key, mixed>  $payload
     * @return list<string>
     */
    private static function statusesOfAVersion(array $marks, array $payload): array
    {
        if (! $marks['knowsOut']) {
            return [];
        }

        if ($marks['out'] === null) {
            return ['open'];
        }

        $anomalies = $payload['anomalies'] ?? null;

        if (! \is_array($anomalies)) {
            return ['closed', 'anomalous'];
        }

        return $anomalies === [] ? ['closed'] : ['anomalous'];
    }

    /**
     * Las marcas de `before` o `after`.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array{in: string|null, knowsOut: bool, out: string|null}
     */
    private static function marks(array $payload, string $key): array
    {
        $marks = $payload[$key] ?? null;

        if (! \is_array($marks)) {
            return ['in' => null, 'knowsOut' => false, 'out' => null];
        }

        return [
            'in' => self::string($marks, 'clocked_in_at'),
            'knowsOut' => \array_key_exists('clocked_out_at', $marks),
            'out' => self::string($marks, 'clocked_out_at'),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return \is_string($value) ? $value : null;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function int(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return \is_int($value) ? $value : null;
    }
}

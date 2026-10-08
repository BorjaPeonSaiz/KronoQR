<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * Lo que **los asientos** `shift_entry.*` de un tramo dicen que ese tramo tiene
 * que ser (ADR-057 §4, RL-04).
 *
 * `audit_log` es solo-append y esta encadenada por hash: lo que dice no se
 * puede reescribir sin romper la cadena. Por eso es la referencia contra la que
 * se compara `shift_entries`, que si se puede reescribir con la credencial de la
 * aplicacion. Este objeto traduce los asientos a una expectativa sobre la fila.
 *
 * ## Que asientos hay y que afirma cada uno
 *
 * Los escribe `Compliance\Infrastructure\Listener\RecordShiftEntryAudit`, en la
 * misma transaccion que el tramo:
 *
 * | Asiento | Clave del tramo | Afirma |
 * |---|---|---|
 * | `shift_entry.created` de un fichaje | `shift_entry_uuid` | entrada, sin salida, abierto, y el `origin` de la entrada |
 * | `shift_entry.closed` de un fichaje | `shift_entry_uuid` | entrada y salida; `anomalous` si trae anomalias, si no `closed`; el `origin` de la salida |
 * | correccion `created`, `modified` o `closed` | `shift_entry_uuid` (la version que produjo) | las marcas de `after`, su `version`, `manual_admin` en el lado que cambio y su fila en `shift_corrections` con accion, motivo y autor |
 * | correccion `modified` o `closed` | `superseded_shift_entry_uuid` (la version anterior) | las marcas de `before`, estado `superseded`, que la sustituye la version nueva y que esta tiene su fila en `shift_corrections` |
 * | correccion `voided` | `shift_entry_uuid` | las marcas de `before`, estado `voided` y su fila en `shift_corrections` |
 *
 * Un asiento de correccion se distingue del de un fichaje por `reason_code`
 * ({@see AuditTrailEntry::isCorrection()}).
 *
 * ## Tres asientos, no uno
 *
 * Las marcas, el estado y la version los dice **el ultimo** asiento. Pero el
 * origen de la entrada solo lo dice **el primero** que escribio el tramo, y el de
 * la salida, **el ultimo que fijo las marcas** (el ultimo que no es una
 * anulacion). Por eso la fabrica recibe hasta tres; los dos ultimos pueden faltar
 * —en la pasada diaria, el primero de un tramo antiguo cae fuera de la ventana—
 * y entonces el origen de ese lado no se compara.
 *
 * ## Lo que no se sabe no se compara
 *
 * Cada campo es nulo cuando los asientos no lo dicen: el de un fichaje no lleva
 * `version`, un asiento antiguo puede no llevar `anomalies`, y una correccion
 * que no cambio un lado no dice de donde vino ese lado. Comparar contra un valor
 * supuesto convertiria cada instalacion antigua en una alerta permanente; no
 * compararlo deja ese campo sin cubrir, y eso es lo que se documenta en el
 * runbook («Lo que la conciliacion no ve»).
 */
final readonly class AuditedShiftEntry
{
    /** Las acciones de `audit_log` que hablan de un tramo. El SQL de la conciliacion se compone con ellas. */
    public const array ACTIONS = [
        AuditAction::ShiftEntryCreated->value,
        AuditAction::ShiftEntryModified->value,
        AuditAction::ShiftEntryClosed->value,
        AuditAction::ShiftEntryVoided->value,
    ];

    /** Las dos acciones de correccion que sustituyen una version por otra (ADR-026, ADR-035). */
    public const array SUPERSEDING_ACTIONS = [
        AuditAction::ShiftEntryModified->value,
        AuditAction::ShiftEntryClosed->value,
    ];

    /** El origen que una correccion deja en el lado que cambia (`ScanOrigin::MANUAL_ADMIN`). */
    public const string MANUAL_SOURCE = 'manual_admin';

    /**
     * @param  list<string>  $expectedStatuses  Estados que la fila puede tener legitimamente. Vacia si el asiento no lo dice.
     * @param  list<ExpectedCorrection>  $requiredCorrections
     */
    private function __construct(
        /** `audit_log.id` del ultimo asiento: identifica la prueba sin copiar su contenido. */
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
        /** Origen de la entrada, o `null` si los asientos no lo dicen. */
        public ?string $clockInSource,
        public bool $knowsClockOutSource,
        public ?string $clockOutSource,
        public array $requiredCorrections,
    ) {}

    /**
     * Interpreta los asientos de un tramo.
     *
     * @param  AuditTrailEntry  $latest  El ultimo asiento que habla del tramo, por cualquiera de sus dos claves.
     * @param  bool  $asSuperseded  En `$latest` el tramo aparece como `superseded_shift_entry_uuid`.
     * @param  AuditTrailEntry|null  $first  El primero en el que el tramo es `shift_entry_uuid`.
     * @param  AuditTrailEntry|null  $marks  El ultimo en el que el tramo es `shift_entry_uuid` y no es una anulacion.
     */
    public static function fromTrail(
        AuditTrailEntry $latest,
        bool $asSuperseded,
        ?AuditTrailEntry $first = null,
        ?AuditTrailEntry $marks = null,
    ): self {
        $base = match (true) {
            $asSuperseded => self::supersededBy($latest),
            $latest->isCorrection() => self::fromCorrection($latest),
            default => self::fromClocking($latest),
        };

        $outSource = self::clockOutSourceOf($marks);
        $required = $base['required'];

        foreach ([$first, $marks] as $entry) {
            if ($entry instanceof AuditTrailEntry && $entry->id !== $latest->id && $entry->isCorrection()) {
                $required[] = self::expectedCorrection($entry, false);
            }
        }

        return new self(
            auditEntryId: $latest->id,
            action: $latest->action,
            employeeUuid: self::string($latest->payload, 'employee_uuid'),
            siteId: self::int($latest->payload, 'site_id'),
            workDate: self::string($latest->payload, 'work_date'),
            clockedInAt: $base['marks']['in'],
            knowsClockOut: $base['marks']['knowsOut'],
            clockedOutAt: $base['marks']['out'],
            expectedStatuses: $base['statuses'],
            version: $base['version'],
            supersededByUuid: $base['supersededBy'],
            clockInSource: self::clockInSourceOf($first),
            knowsClockOutSource: $outSource['known'],
            clockOutSource: $outSource['value'],
            requiredCorrections: $required,
        );
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
     * @return array{marks: array{in: string|null, knowsOut: bool, out: string|null}, statuses: list<string>, version: int|null, supersededBy: string|null, required: list<ExpectedCorrection>}
     */
    private static function supersededBy(AuditTrailEntry $entry): array
    {
        $replacementVersion = self::int($entry->payload, 'version');

        return [
            'marks' => self::marks($entry->payload, 'before'),
            'statuses' => ['superseded'],
            // La correccion estrena la version siguiente (ADR-035): la anterior
            // es una menos, siempre.
            'version' => $replacementVersion === null ? null : $replacementVersion - 1,
            'supersededBy' => self::string($entry->payload, 'shift_entry_uuid'),
            'required' => [self::expectedCorrection($entry, true)],
        ];
    }

    /**
     * La version que una correccion produjo, o la que anulo.
     *
     * @return array{marks: array{in: string|null, knowsOut: bool, out: string|null}, statuses: list<string>, version: int|null, supersededBy: string|null, required: list<ExpectedCorrection>}
     */
    private static function fromCorrection(AuditTrailEntry $entry): array
    {
        $voided = self::string($entry->payload, 'action') === 'voided';
        // Una anulacion no tiene `after`: el tramo conserva las marcas que
        // tenia, que son las de `before` (regla dura 5).
        $marks = self::marks($entry->payload, $voided ? 'before' : 'after');

        return [
            'marks' => $marks,
            'statuses' => $voided ? ['voided'] : self::statusesOfAVersion($marks, $entry->payload),
            'version' => self::int($entry->payload, 'version'),
            'supersededBy' => null,
            'required' => [self::expectedCorrection($entry, false)],
        ];
    }

    /**
     * Un fichaje del quiosco: la entrada que abrio el tramo o la salida que lo
     * cerro.
     *
     * @return array{marks: array{in: string|null, knowsOut: bool, out: string|null}, statuses: list<string>, version: int|null, supersededBy: string|null, required: list<ExpectedCorrection>}
     */
    private static function fromClocking(AuditTrailEntry $entry): array
    {
        $payload = $entry->payload;
        $marks = match ($entry->action) {
            // La entrada afirma que todavia no hay salida.
            AuditAction::ShiftEntryCreated->value => ['in' => self::string($payload, 'clocked_in_at'), 'knowsOut' => true, 'out' => null],
            AuditAction::ShiftEntryClosed->value => [
                'in' => self::string($payload, 'clocked_in_at'),
                'knowsOut' => \array_key_exists('clocked_out_at', $payload),
                'out' => self::string($payload, 'clocked_out_at'),
            ],
            default => ['in' => self::string($payload, 'clocked_in_at'), 'knowsOut' => false, 'out' => null],
        };

        return [
            'marks' => $marks,
            'statuses' => self::statusesOfAVersion($marks, $payload),
            // El fichaje no apunta la version, y no se supone: ver la cabecera.
            'version' => null,
            'supersededBy' => null,
            'required' => [],
        ];
    }

    /**
     * Origen de la entrada segun el primer asiento del tramo: el `origin` del
     * fichaje que lo abrio, `manual_admin` si lo dio de alta una correccion, y
     * `manual_admin` si lo produjo una correccion que cambio la entrada. Una
     * correccion que no la cambio hereda el de la version anterior, que este
     * asiento no dice.
     */
    private static function clockInSourceOf(?AuditTrailEntry $first): ?string
    {
        if (! $first instanceof AuditTrailEntry) {
            return null;
        }

        if (! $first->isCorrection()) {
            return $first->action === AuditAction::ShiftEntryCreated->value
                ? self::string($first->payload, 'origin')
                : null;
        }

        return match (self::string($first->payload, 'action')) {
            'created' => self::MANUAL_SOURCE,
            'modified', 'closed' => self::sideChanged($first->payload, 'clocked_in_at') ? self::MANUAL_SOURCE : null,
            default => null,
        };
    }

    /**
     * Origen de la salida segun el ultimo asiento que fijo las marcas.
     *
     * @return array{known: bool, value: string|null}
     */
    private static function clockOutSourceOf(?AuditTrailEntry $marks): array
    {
        $unknown = ['known' => false, 'value' => null];

        if (! $marks instanceof AuditTrailEntry) {
            return $unknown;
        }

        if (! $marks->isCorrection()) {
            return match ($marks->action) {
                AuditAction::ShiftEntryCreated->value => ['known' => true, 'value' => null],
                AuditAction::ShiftEntryClosed->value => ['known' => true, 'value' => self::string($marks->payload, 'origin')],
                default => $unknown,
            };
        }

        $after = self::marks($marks->payload, 'after');

        return match (true) {
            ! $after['knowsOut'] => $unknown,
            // Sin salida no hay origen de salida (`ShiftEntry::nextVersion()`).
            $after['out'] === null => ['known' => true, 'value' => null],
            self::string($marks->payload, 'action') === 'created',
            self::sideChanged($marks->payload, 'clocked_out_at') => ['known' => true, 'value' => self::MANUAL_SOURCE],
            default => $unknown,
        };
    }

    /**
     * Si una correccion cambio ese lado, al segundo, como lo decide el registro.
     *
     * @param  array<array-key, mixed>  $payload
     */
    private static function sideChanged(array $payload, string $side): bool
    {
        $before = $payload['before'] ?? null;
        $after = $payload['after'] ?? null;

        if (! \is_array($before) || ! \is_array($after)) {
            return false;
        }

        return WorkRecordInstant::differsToTheSecond(self::string($before, $side), self::string($after, $side));
    }

    private static function expectedCorrection(AuditTrailEntry $entry, bool $onReplacement): ExpectedCorrection
    {
        return new ExpectedCorrection(
            action: self::string($entry->payload, 'action') ?? '',
            reasonCode: self::string($entry->payload, 'reason_code'),
            performedByUserId: self::int($entry->payload, 'performed_by_user_id'),
            onReplacement: $onReplacement,
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

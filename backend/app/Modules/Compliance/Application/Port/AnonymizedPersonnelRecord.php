<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Port;

/**
 * Una ficha recien minimizada (ADR-060, tarea 11.3 A): lo que necesita su
 * asiento `retention.personnel_record_anonymized`. Sin nombre ni codigo: ya no
 * existen.
 */
final readonly class AnonymizedPersonnelRecord
{
    /**
     * Los campos que la minimizacion vacia o sustituye. La misma lista que el
     * `CHECK employees_chk_anonymized_is_minimised`.
     *
     * @var list<string>
     */
    public const array FIELDS = [
        'first_name',
        'last_name',
        'employee_code',
        'email',
        'national_id_hash',
        'photo_path',
        'pin_hash',
        'pin_issued_at',
        'pin_delivered_at',
        'pin_delivered_by_user_id',
        'pin_length',
    ];

    public function __construct(
        public string $employeeUuid,
        /** Fecha de cese, `YYYY-MM-DD`. */
        public string $terminatedOn,
        public int $contractsDeleted,
        public int $absencesDeleted,
        public int $tokensDeleted,
        /** La foto que habia, para borrarla despues del `COMMIT`. Nunca va al asiento. */
        public ?string $photoPath,
    ) {}
}

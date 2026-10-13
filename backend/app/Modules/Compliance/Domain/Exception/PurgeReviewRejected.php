<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\Exception;

/**
 * No se puede dar por revisado ese asiento de purga (RL-04, RS-07, ADR-057
 * enmienda del 10-10-2026 §6).
 *
 * Mismo criterio que las excepciones del perfil de cumplimiento: `getMessage()`
 * es tecnico y en ingles —va al log y a la traza— y lo que lee una persona sale
 * de {@see self::$translationKey} y {@see self::$parameters}, en
 * `lang/{es,en}/work-record-review.php`. Ningun parametro es texto libre: el
 * motivo de la revision no viaja nunca en una excepcion (regla dura 21).
 */
final class PurgeReviewRejected extends ComplianceDomainException
{
    /**
     * @param  array<string, int>  $parameters
     */
    private function __construct(
        public readonly string $translationKey,
        public readonly array $parameters,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function notAWorkRecordPurge(int $id): self
    {
        return new self(
            'work-record-review.not_a_purge',
            ['id' => $id],
            'Audit entry '.$id.' is not a work-record purge (retention.purge_executed).',
        );
    }

    public static function alreadyReviewed(int $id, int $reviewId): self
    {
        return new self(
            'work-record-review.already_reviewed',
            ['id' => $id, 'review' => $reviewId],
            'Audit entry '.$id.' was already reviewed by entry '.$reviewId.'.',
        );
    }

    public static function purgeIsAdmissible(int $id): self
    {
        return new self(
            'work-record-review.admissible',
            ['id' => $id],
            'Audit entry '.$id.' is an admissible purge: there is nothing to review.',
        );
    }

    public static function reasonTooShort(int $min): self
    {
        return new self(
            'work-record-review.reason_too_short',
            ['min' => $min],
            'The review reason must have at least '.$min.' characters.',
        );
    }

    public static function reasonTooLong(int $max): self
    {
        return new self(
            'work-record-review.reason_too_long',
            ['max' => $max],
            'The review reason accepts at most '.$max.' characters.',
        );
    }

    public static function responsibleNotActive(int $userId): self
    {
        return new self(
            'work-record-review.responsible_inactive',
            ['id' => $userId],
            'Account '.$userId.' is not an active management account.',
        );
    }
}

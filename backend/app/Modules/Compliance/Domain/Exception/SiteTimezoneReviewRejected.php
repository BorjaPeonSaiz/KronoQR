<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\Exception;

use App\Modules\Shared\Domain\Exception\BusinessRejection;

/**
 * La revision de una discrepancia de la zona del centro no se puede escribir
 * asi (RN-23, ADR-056; bloque 2 de la 2.3.0).
 *
 * Mismo criterio que {@see PurgeReviewRejected}: `getMessage()` es tecnico y en
 * ingles —va al log—; lo que lee quien teclea la orden sale de
 * {@see self::$translationKey} y {@see self::$parameters}.
 */
final class SiteTimezoneReviewRejected extends ComplianceDomainException implements BusinessRejection
{
    /** La clave del rechazo por cuenta: la consola la distingue para salir con 2. */
    public const string RESPONSIBLE_NOT_ACTIVE = 'work-record-review.responsible_inactive';

    /**
     * @param  array<string, int|string>  $parameters
     */
    private function __construct(
        public readonly string $translationKey,
        public readonly array $parameters,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function notPending(string $target): self
    {
        return new self(
            'work-record-review.site_change_not_pending',
            ['target' => $target],
            'Site time zone discrepancy '.$target.' is not a pending discrepancy of the reconciliation.',
        );
    }

    public static function alreadyReviewed(string $target): self
    {
        return new self(
            'work-record-review.site_change_already_reviewed',
            ['target' => $target],
            'Site time zone discrepancy '.$target.' was already reviewed.',
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
            self::RESPONSIBLE_NOT_ACTIVE,
            ['id' => $userId],
            'Account '.$userId.' is not an active management account allowed to sign this review.',
        );
    }
}

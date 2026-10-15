<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Exception;

/**
 * La correccion de la zona horaria del centro por consola no se puede firmar
 * asi (RN-23, ADR-056 §3): el motivo no explica nada, se pasa de largo, o quien
 * responde no es una cuenta de gestion activa.
 *
 * Solo la ve quien ejecuta `workforce:correct-site-timezone` en el servidor.
 * `getMessage()` es tecnico y en ingles —va al log—; lo que lee la persona sale
 * de {@see self::$translationKey} y {@see self::$parameters}.
 */
final class InvalidSiteTimezoneCorrection extends WorkforceDomainException
{
    /**
     * @param  array<string, int>  $parameters  sustituciones del mensaje traducido
     */
    private function __construct(
        public readonly string $translationKey,
        public readonly array $parameters,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function reasonTooShort(int $min): self
    {
        return new self(
            'site.correct_timezone.reason_too_short',
            ['min' => $min],
            'The site time zone correction reason must have at least '.$min.' characters.',
        );
    }

    public static function reasonTooLong(int $max): self
    {
        return new self(
            'site.correct_timezone.reason_too_long',
            ['max' => $max],
            'The site time zone correction reason accepts at most '.$max.' characters.',
        );
    }

    public static function responsibleNotActive(int $userId): self
    {
        return new self(
            'site.correct_timezone.responsible_inactive',
            ['id' => $userId],
            'Account '.$userId.' is not an active management account.',
        );
    }
}

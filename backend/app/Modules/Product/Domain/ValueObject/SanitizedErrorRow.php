<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

/**
 * Las columnas de texto de un grupo de `error_events`, ya saneadas por
 * {@see ErrorColumnSanitizer::row()} (ADR-048). Es lo que se guarda y lo que
 * entra en la huella.
 */
final readonly class SanitizedErrorRow
{
    /**
     * @param  array<string, scalar>  $context
     */
    public function __construct(
        public string $message,
        public array $context,
        public ?string $code,
        public ?string $exceptionClass,
        public ?string $file,
        public string $appVersion,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Exception;

/**
 * Lo que se pide como concesion de soporte no puede serlo (RF-PD-11).
 *
 * ## Por que se comprueba aqui si el `FormRequest` ya lo comprueba
 *
 * Porque **la consola no pasa por ningun `FormRequest`**. `support:grant` lo
 * ejecuta quien tiene acceso al servidor, que es justo la situacion en la que
 * mas prisa hay y menos ganas de escribir un motivo. Con la comprobacion solo en
 * el borde HTTP, una concesion sin motivo o de trescientas horas entraria por
 * consola sin que nada la parase.
 *
 * ## Mensaje tecnico en ingles, texto de usuario por clave
 *
 * Misma pauta que {@see InvalidLicenseKey} y que `InvalidSettingValue`:
 * `Domain/` no sabe en que idioma se va a leer.
 */
final class InvalidSupportGrant extends ProductDomainException
{
    /**
     * @param  array<string, string|int>  $parameters
     */
    private function __construct(
        string $message,
        public readonly string $translationKey,
        public readonly array $parameters,
    ) {
        parent::__construct($message);
    }

    public static function reason(int $length): self
    {
        return new self(
            \sprintf('A support grant needs a reason of 3 to 200 characters, got %d.', $length),
            'support.errors.reason_length',
            ['length' => $length],
        );
    }

    public static function duration(int $hours, int $maximum): self
    {
        return new self(
            \sprintf('A support grant lasts from 1 to %d hours, got %d.', $maximum, $hours),
            'support.errors.duration',
            ['hours' => $hours, 'maximum' => $maximum],
        );
    }
}

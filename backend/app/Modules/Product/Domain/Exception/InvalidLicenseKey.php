<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Exception;

/**
 * La carga util de una clave de licencia no cumple lo que exige RF-PD-04.
 *
 * ## Cuando se lanza y cuando NO
 *
 * Se lanza al **activar** una clave: quien la pega en el panel o en la consola
 * tiene que enterarse de que le han dado una clave incompleta, y con que campo
 * falla. Es un `422` traducido.
 *
 * **No se lanza al leer la licencia guardada.** Ahi la lectura es tolerante y el
 * resultado es el estado `unverifiable`, con el sistema entero funcionando
 * (regla dura 15): una fila corrupta no puede convertir el panel en un `500` ni
 * acercarse al camino de fichaje. Es la misma politica de la tarea 5.1 —lectura
 * tolerante, escritura estricta— aplicada aqui.
 *
 * ## Mensaje tecnico en ingles, texto de usuario por clave
 *
 * `Domain/` no sabe en que idioma se va a leer. Lleva mensaje tecnico en ingles
 * mas `translationKey` y `parameters`, que el borde resuelve con
 * `ProblemDetails::translated()` en el idioma negociado (misma pauta que
 * `InvalidSettingValue`, tarea 5.1).
 */
final class InvalidLicenseKey extends ProductDomainException
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

    public static function missingField(string $field): self
    {
        return new self(
            \sprintf('The license payload has no "%s" field.', $field),
            'license.errors.missing_field',
            ['field' => $field],
        );
    }

    public static function fieldNotText(string $field): self
    {
        return new self(
            \sprintf('The license field "%s" must be a non-empty string.', $field),
            'license.errors.field_not_text',
            ['field' => $field],
        );
    }

    public static function fieldNotInteger(string $field): self
    {
        return new self(
            \sprintf('The license field "%s" must be an integer.', $field),
            'license.errors.field_not_integer',
            ['field' => $field],
        );
    }

    public static function limitNotPositive(string $field, int $value): self
    {
        return new self(
            \sprintf('The license field "%s" must be greater than zero, got %d.', $field, $value),
            'license.errors.limit_not_positive',
            ['field' => $field, 'value' => $value],
        );
    }

    public static function fieldNotADate(string $field): self
    {
        return new self(
            \sprintf('The license field "%s" must be an ISO-8601 instant in UTC.', $field),
            'license.errors.field_not_a_date',
            ['field' => $field],
        );
    }

    public static function validityInverted(string $from, string $until): self
    {
        return new self(
            \sprintf('The license validity ends (%s) before it starts (%s).', $until, $from),
            'license.errors.validity_inverted',
            ['from' => $from, 'until' => $until],
        );
    }

    public static function featuresNotAList(): self
    {
        return new self(
            'The license field "features" must be a list of strings.',
            'license.errors.features_not_a_list',
            [],
        );
    }
}

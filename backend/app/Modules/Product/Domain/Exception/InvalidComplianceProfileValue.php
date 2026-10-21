<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Exception;

use App\Modules\Product\Domain\ValueObject\ComplianceProfileField;
use App\Modules\Shared\Domain\Exception\BusinessRejection;
use App\Modules\Shared\Domain\ValueObject\LegalRetentionFloor;

/**
 * El valor no cumple lo que el campo del perfil de cumplimiento declara
 * (RF-PD-07, ADR-017).
 *
 * Mismo criterio que {@see InvalidSettingValue}: el mensaje de `getMessage()` es
 * tecnico y en ingles —va al log y a la traza—, y lo que ve una persona sale de
 * {@see self::$translationKey} y {@see self::$parameters}, que el borde HTTP
 * resuelve en el idioma negociado. Sin eso, un panel puesto en ingles recibiria
 * un `422` en castellano y el dominio tendria literales de interfaz dentro.
 *
 * **Aqui no hay la mitad tolerante que si tiene la configuracion.** Una fila de
 * `installation_settings` corrupta se descarta y rige el valor de serie, porque
 * el fichaje no puede pararse por eso. Un perfil de cumplimiento corrupto no
 * tiene valor de serie al que caer —la regla dura 14 lo prohibe— asi que la
 * lectura falla y lo dice: un umbral legal inventado en PHP es peor que una
 * pantalla que avisa.
 */
final class InvalidComplianceProfileValue extends ProductDomainException implements BusinessRejection
{
    /**
     * @param  array<string, string|int>  $parameters  sustituciones del mensaje traducido
     */
    private function __construct(
        public readonly string $translationKey,
        public readonly array $parameters,
        public readonly ComplianceProfileField $field,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function outOfRange(ComplianceProfileField $field, int $value, int $minimum, int $maximum): self
    {
        return self::of(
            $field,
            'out_of_range',
            ['minimum' => $minimum, 'maximum' => $maximum, 'value' => $value],
            'accepts '.$minimum.' to '.$maximum.', got '.$value,
        );
    }

    public static function notAnInteger(ComplianceProfileField $field, string $received): self
    {
        return self::of($field, 'not_integer', ['received' => $received], 'expects an integer, got '.$received);
    }

    public static function notText(ComplianceProfileField $field, string $received): self
    {
        return self::of($field, 'not_text', ['received' => $received], 'expects a string, got '.$received);
    }

    public static function notEmpty(ComplianceProfileField $field): self
    {
        return self::of($field, 'not_empty', [], 'does not accept an empty value');
    }

    public static function tooLong(ComplianceProfileField $field, int $length, int $maximumLength): self
    {
        return self::of(
            $field,
            'too_long',
            ['maximum' => $maximumLength, 'length' => $length],
            'accepts up to '.$maximumLength.' characters, got '.$length,
        );
    }

    public static function notADateList(ComplianceProfileField $field, string $received): self
    {
        return self::of($field, 'not_date_list', ['received' => $received], 'expects a list of ISO dates, got '.$received);
    }

    public static function duplicated(ComplianceProfileField $field): self
    {
        return self::of($field, 'duplicated', [], 'does not accept repeated values');
    }

    /**
     * Ya hay otro perfil con ese nombre.
     *
     * `compliance_profiles.name` es unico. Sin esto, renombrar el perfil a uno ya
     * existente sale como `500` —una violacion de restriccion sin traducir— y
     * quien lo intenta no sabe que el problema es el nombre. Hoy solo es
     * alcanzable con un segundo perfil creado a mano, pero un `500` que se puede
     * provocar desde un formulario no es un error del servidor: es un error del
     * cliente sin contar.
     */
    public static function nameAlreadyUsed(string $name): self
    {
        return new self(
            'compliance-profile.errors.name_taken',
            ['name' => $name],
            ComplianceProfileField::Name,
            'Compliance profile name "'.$name.'" is already in use.',
        );
    }

    /**
     * La invariante **entre dos campos**: no se puede trabajar mas en un dia que
     * en una semana.
     *
     * No la puede comprobar ningun campo por separado, porque un `PATCH` que solo
     * cambie la jornada diaria se compara contra una semanal que no viaja en la
     * peticion. Se comprueba sobre el perfil ya resuelto, antes de escribir nada,
     * que es lo que impide que el orden de los campos deje un perfil imposible.
     */
    public static function weeklyBelowDaily(int $weekly, int $daily): self
    {
        return new self(
            'compliance-profile.errors.weekly_below_daily',
            ['weekly' => $weekly, 'daily' => $daily],
            ComplianceProfileField::MaxWeeklyHours,
            'Weekly working hours ('.$weekly.') cannot be lower than daily working hours ('.$daily.').',
        );
    }

    /**
     * `retention_years` por debajo del suelo legal de la jurisdiccion del perfil
     * (RL-02, RF-PD-07, ADR-057 enmienda del 10-10-2026): 4 años en España
     * (art. 34.9 ET).
     *
     * Su propia clave y no `out_of_range`: el rango del campo (1 a 50) es una
     * cota estructural, y esto es una obligacion legal que depende del pais. El
     * mensaje dice el suelo, el valor y la jurisdiccion, porque «admite de 1 a
     * 50» seria mentira para un perfil español.
     */
    public static function belowLegalRetentionFloor(int $value, LegalRetentionFloor $floor): self
    {
        return new self(
            'compliance-profile.errors.below_legal_floor',
            [
                'field' => ComplianceProfileField::RetentionYears->value,
                'minimum' => $floor->years,
                'value' => $value,
                'jurisdiction' => $floor->jurisdiction->code,
            ],
            ComplianceProfileField::RetentionYears,
            'Compliance profile field "retention_years" cannot be lower than the legal floor of '
            .$floor->jurisdiction->code.' ('.$floor->years.' years), got '.$value.'.',
        );
    }

    /**
     * @param  array<string, string|int>  $parameters
     */
    private static function of(ComplianceProfileField $field, string $reason, array $parameters, string $detail): self
    {
        return new self(
            'compliance-profile.errors.'.$reason,
            ['field' => $field->value, ...$parameters],
            $field,
            'Compliance profile field "'.$field->value.'" '.$detail.'.',
        );
    }
}

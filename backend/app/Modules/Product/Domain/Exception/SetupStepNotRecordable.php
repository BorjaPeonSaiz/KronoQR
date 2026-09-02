<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Exception;

use App\Modules\Product\Domain\ValueObject\SetupStep;
use App\Modules\Product\Domain\ValueObject\SetupStepState;

/**
 * Se intenta marcar a mano un paso que no admite esa marca (RF-PD-03).
 *
 * Dos casos, y los dos son `422`:
 *
 * 1. **El paso es derivado** (`administrator`, `site`). Su estado se lee del
 *    dato —una cuenta con segundo factor confirmado, un centro creado— y
 *    permitir declararlo a mano seria permitir que el asistente afirme que hay
 *    administrador cuando no lo hay. La forma de completarlos es hacerlos.
 * 2. **El paso no es omitible.** El perfil de convenio no lo es porque RL-21
 *    obliga a contrastar los umbrales con el convenio aplicable: darlo por
 *    aparcado seria dejar el calculo de horas con unos umbrales que nadie miro.
 *
 * `422` y no `403`: no es una cuestion de permisos —el administrador tiene todos
 * los que hacen falta—, es que la peticion no cabe en el modelo.
 */
final class SetupStepNotRecordable extends ProductDomainException
{
    public const string DERIVED_TRANSLATION_KEY = 'setup.step_is_derived';

    public const string NOT_SKIPPABLE_TRANSLATION_KEY = 'setup.step_is_not_skippable';

    public readonly string $translationKey;

    /** @var array<string, string|int> */
    public readonly array $parameters;

    private function __construct(string $translationKey, SetupStep $step, string $message)
    {
        $this->translationKey = $translationKey;
        $this->parameters = ['step' => $step->value];

        parent::__construct($message);
    }

    public static function becauseItIsDerived(SetupStep $step): self
    {
        return new self(
            self::DERIVED_TRANSLATION_KEY,
            $step,
            'Setup step "'.$step->value.'" is derived from the data and cannot be recorded by hand.',
        );
    }

    public static function becauseItCannotBeSkipped(SetupStep $step): self
    {
        return new self(
            self::NOT_SKIPPABLE_TRANSLATION_KEY,
            $step,
            'Setup step "'.$step->value.'" cannot be '.SetupStepState::SKIPPED->value.'.',
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Exception;

use App\Modules\Product\Domain\ValueObject\SetupStep;

/**
 * No se puede cerrar el asistente todavia, o ya estaba cerrado (RF-PD-03).
 *
 * **`409` y no `422`.** La peticion es valida —no lleva cuerpo— y lo que no
 * encaja es el estado del sistema, que es exactamente la distincion que el
 * contrato hace entre los dos codigos: ante un `409` no sirve corregir el
 * formulario, hay que releer el recurso.
 *
 * **El `detail` nombra los pasos que faltan.** Un «no se puede cerrar» sin decir
 * que falta obliga a adivinar, y quien pone en marcha la instalacion no tiene la
 * consola del servidor delante para averiguarlo.
 */
final class SetupNotCompletable extends ProductDomainException
{
    public const string PENDING_TRANSLATION_KEY = 'setup.steps_still_pending';

    public const string ALREADY_TRANSLATION_KEY = 'setup.already_completed';

    public readonly string $translationKey;

    /** @var array<string, string|int> */
    public readonly array $parameters;

    private function __construct(string $translationKey, string $steps, string $message)
    {
        $this->translationKey = $translationKey;
        $this->parameters = ['steps' => $steps];

        parent::__construct($message);
    }

    /**
     * @param  list<SetupStep>  $pending
     */
    public static function withPendingSteps(array $pending): self
    {
        $steps = implode(', ', array_map(static fn (SetupStep $step): string => $step->value, $pending));

        return new self(
            self::PENDING_TRANSLATION_KEY,
            $steps,
            'The start-up wizard still has unresolved steps: '.$steps.'.',
        );
    }

    public static function becauseItAlreadyIs(): self
    {
        return new self(
            self::ALREADY_TRANSLATION_KEY,
            '',
            'The start-up wizard is already closed and does not reopen.',
        );
    }
}

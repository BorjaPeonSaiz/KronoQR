<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

use DateTimeImmutable;

/**
 * El estado completo del asistente de puesta en marcha (**RF-PD-03**).
 *
 * ## Se construye siempre con los ocho pasos
 *
 * Nunca con «los que tienen fila». Un asistente que solo enumerase lo ya tocado
 * no podria pintar la lista de lo que queda, que es justo lo que hace falta para
 * poder abandonarlo y retomarlo sin perderse.
 *
 * ## `available` es un calculo, no un campo suelto
 *
 * El asistente esta abierto mientras no se haya cerrado, y punto. **No se cierra
 * solo al resolver el ultimo paso**: si lo hiciera, el panel saltaria a la
 * pantalla de acceso justo antes de enseñar el resumen final, que es la pantalla
 * que dice que tarjetas quedan por imprimir. Cerrarlo es un acto
 * (`POST /api/v1/setup/complete`).
 */
final readonly class SetupState
{
    /**
     * @param  array<string, SetupStepState>  $states  Indexado por el valor de {@see SetupStep}.
     */
    private function __construct(
        private array $states,
        public ?DateTimeImmutable $completedAt,
    ) {}

    /**
     * @param  array<string, SetupStepState>  $recorded  Marcas guardadas, por paso.
     */
    public static function of(
        array $recorded,
        bool $hasAdministrator,
        bool $hasSite,
        ?DateTimeImmutable $completedAt,
    ): self {
        $states = [];

        foreach (SetupStep::cases() as $step) {
            $states[$step->value] = match (true) {
                // Los dos derivados ignoran cualquier marca guardada, incluso si
                // alguien consiguiera escribirla: el dato manda sobre la marca.
                $step === SetupStep::ADMINISTRATOR => self::from($hasAdministrator),
                $step === SetupStep::SITE => self::from($hasSite),
                default => $recorded[$step->value] ?? SetupStepState::PENDING,
            };
        }

        return new self($states, $completedAt);
    }

    public function isAvailable(): bool
    {
        return ! $this->completedAt instanceof DateTimeImmutable;
    }

    public function stateOf(SetupStep $step): SetupStepState
    {
        return $this->states[$step->value] ?? SetupStepState::PENDING;
    }

    /**
     * Los pasos que impiden cerrar: obligatorios sin completar, y omitibles que
     * nadie ha resuelto ni en un sentido ni en el otro.
     *
     * @return list<SetupStep>
     */
    public function unresolvedSteps(): array
    {
        $pending = [];

        foreach (SetupStep::cases() as $step) {
            $state = $this->stateOf($step);

            $resolved = $step->isRequired()
                ? $state === SetupStepState::COMPLETED
                : $state->isResolved();

            if (! $resolved) {
                $pending[] = $step;
            }
        }

        return $pending;
    }

    /**
     * Los pasos que se cerraron **omitiendolos**: licencia y quiosco, en la
     * practica.
     *
     * Es lo unico del asistente que no se puede reconstruir despues, y por eso
     * viaja en el asiento de `setup.completed` (RL-04): `setup_progress` es una
     * tabla normal —editable— mientras que `audit_log` es solo-append y
     * encadenado por hash. Saber que una instalacion se cerro sin licencia y sin
     * ningun quiosco vinculado explica media conversacion de soporte de la
     * primera semana.
     *
     * @return list<SetupStep>
     */
    public function skippedSteps(): array
    {
        $skipped = [];

        foreach (SetupStep::cases() as $step) {
            if ($this->stateOf($step) === SetupStepState::SKIPPED) {
                $skipped[] = $step;
            }
        }

        return $skipped;
    }

    private static function from(bool $done): SetupStepState
    {
        return $done ? SetupStepState::COMPLETED : SetupStepState::PENDING;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands\Quality\Support;

use InvalidArgumentException;

/**
 * El orden REAL de ejecucion de las fases del plan: 0 -> 1 -> 2 -> 5 -> 3 -> 4
 * (Anexo A del doc 01).
 *
 * Existe esta clase, en vez de un `fase <= CURRENT_PHASE`, porque la comparacion
 * numerica es incorrecta y ademas falla del lado peligroso: con CURRENT_PHASE=5
 * daria por exigibles los requisitos de las fases 3 y 4 —que todavia no se han
 * ejecutado— y `--check` se pondria rojo por trabajo que nadie ha empezado. Un
 * bloqueo que salta cuando no toca se acaba desactivando, y con el se va el que
 * si sirve.
 *
 * Una fase desconocida es un error, nunca «fuera de alcance»: un `fase: 7` mal
 * escrito en docs/requisitos.yaml dejaria de bloquear en silencio.
 */
final readonly class PhaseOrder
{
    /** @var array<int, int> Fase -> posicion en el orden de ejecucion. */
    private array $rank;

    /**
     * @param  list<int>  $order
     */
    public function __construct(private array $order)
    {
        if ($order === []) {
            throw new InvalidArgumentException('El orden de ejecucion de fases esta vacio.');
        }

        if (count(array_unique($order)) !== count($order)) {
            throw new InvalidArgumentException('El orden de ejecucion repite alguna fase: '.implode(', ', $order).'.');
        }

        $this->rank = array_flip($order);
    }

    /**
     * @return list<int>
     */
    public function all(): array
    {
        return $this->order;
    }

    /**
     * Posicion de la fase en el orden de ejecucion, no su numero.
     */
    public function rank(int $phase): int
    {
        if (! array_key_exists($phase, $this->rank)) {
            throw new InvalidArgumentException(
                'Fase desconocida: '.$phase.'. El orden de ejecucion declarado es '.implode(' -> ', $this->order).'.'
            );
        }

        return $this->rank[$phase];
    }

    /**
     * ¿La fase indicada ya se ha ejecutado, estando en curso `$currentPhase`?
     */
    public function isExecutedBy(int $phase, int $currentPhase): bool
    {
        return $this->rank($phase) <= $this->rank($currentPhase);
    }

    public function describe(): string
    {
        return implode(' -> ', $this->order);
    }
}

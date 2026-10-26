<?php

declare(strict_types=1);

namespace Tests\Support\Workforce;

use App\Modules\Workforce\Application\Port\PinCandidateSource;

/**
 * Una fuente de candidatos a PIN que devuelve, en orden, los que se le dan, y
 * anota cuantos le pidieron y con que maximo. Repite el ultimo cuando se agotan.
 *
 * Es lo que permite probar que el generador descarta un PIN excluido y vuelve a
 * intentarlo: con el azar de verdad, un generador que no excluyera nada pasaria
 * casi siempre.
 */
final class ScriptedPinCandidates implements PinCandidateSource
{
    public int $calls = 0;

    /** @var list<int> */
    public array $maximums = [];

    /**
     * @param  non-empty-list<int>  $picks
     */
    public function __construct(private readonly array $picks) {}

    public function pick(int $maximum): int
    {
        $this->maximums[] = $maximum;

        return $this->picks[min($this->calls++, \count($this->picks) - 1)];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Persistence;

use App\Modules\Product\Application\Port\SetupProgressRepository;
use App\Modules\Product\Domain\ValueObject\SetupStep;
use App\Modules\Product\Domain\ValueObject\SetupStepState;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * `setup_progress` sobre el constructor de consultas.
 *
 * **Sin modelo Eloquent y sin factoria.** Es una tabla de nueve filas como mucho
 * que se lee entera de una vez y se escribe fila a fila; un modelo solo añadiria
 * `->delete()` y la tentacion de usarlo, y aqui borrar una marca es reabrir un
 * paso que alguien ya resolvio.
 *
 * **`upsert` y no «mira si existe y luego escribe».** Entre la consulta y la
 * insercion cabe otra pestaña del panel haciendo lo mismo, y la clave primaria
 * es la que garantiza que quede una sola fila por paso. Es el mismo criterio con
 * el que el alta de empleado se apoya en el `UNIQUE` en lugar de en un `SELECT`
 * previo.
 *
 * **El UUID del actor se traduce a `users.id` aqui.** Hacia arriba viaja el
 * identificador publico —lo unico que `Application` conoce de una persona— y la
 * columna necesita la clave interna. Si la cuenta no existe, la fila se escribe
 * **igual** con `NULL`: el hecho de que el paso se resolvio no puede perderse
 * por no poder atribuirlo.
 */
final readonly class DatabaseSetupProgressRepository implements SetupProgressRepository
{
    private const string TABLE = 'setup_progress';

    public function __construct(private ConnectionInterface $connection) {}

    public function recorded(): array
    {
        $rows = $this->connection->table(self::TABLE)
            ->where('step', '<>', SetupStep::COMPLETION_KEY)
            ->get(['step', 'state']);

        $recorded = [];

        foreach ($rows as $row) {
            $step = SetupStep::tryFrom((string) $row->step);
            $state = SetupStepState::tryFrom((string) $row->state);

            // Una fila que este binario no sabe interpretar se ignora, no se
            // convierte en un error: dejaria el panel sin poder pintar el
            // asistente por un valor sobrante de otra version. El `CHECK` de la
            // migracion hace que no pueda llegar aqui por la via normal.
            if ($step instanceof SetupStep && $state instanceof SetupStepState) {
                $recorded[$step->value] = $state;
            }
        }

        return $recorded;
    }

    public function record(
        SetupStep $step,
        SetupStepState $state,
        DateTimeImmutable $at,
        ?string $actorUuid,
    ): void {
        $this->write($step->value, $state->value, $at, $actorUuid);
    }

    public function complete(DateTimeImmutable $at, ?string $actorUuid): void
    {
        $this->write(
            SetupStep::COMPLETION_KEY,
            SetupStepState::COMPLETED->value,
            $at,
            $actorUuid,
        );
    }

    public function completedAt(): ?DateTimeImmutable
    {
        $recordedAt = $this->connection->table(self::TABLE)
            ->where('step', SetupStep::COMPLETION_KEY)
            ->value('recorded_at');

        return \is_string($recordedAt) ? new DateTimeImmutable($recordedAt) : null;
    }

    private function write(string $step, string $state, DateTimeImmutable $at, ?string $actorUuid): void
    {
        $this->connection->table(self::TABLE)->upsert(
            [[
                'step' => $step,
                'state' => $state,
                // Formato explicito y en UTC (regla dura 3): el driver formatea
                // un `DateTimeImmutable` con la zona del proceso, y aunque
                // `APP_TIMEZONE` sea siempre UTC, depender de eso es depender de
                // una variable de entorno para una invariante del esquema.
                'recorded_at' => $at->format('Y-m-d H:i:s.uP'),
                'recorded_by_user_id' => $this->userIdOf($actorUuid),
            ]],
            ['step'],
            ['state', 'recorded_at', 'recorded_by_user_id'],
        );
    }

    private function userIdOf(?string $actorUuid): ?int
    {
        if ($actorUuid === null) {
            return null;
        }

        $id = $this->connection->table('users')->where('uuid', $actorUuid)->value('id');

        return \is_int($id) ? $id : null;
    }
}

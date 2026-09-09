<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Persistence;

use App\Modules\Compliance\Application\Port\ErrorHistoryArchive;
use App\Modules\Compliance\Domain\ValueObject\RetentionScope;
use App\Modules\Compliance\Domain\ValueObject\RetentionTally;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * El historico de errores a 90 dias (RF-PD-15, RL-11).
 *
 * ## La tabla y la columna estan fijas, y tienen que estarlo
 *
 * `error_events`.`last_seen_at`: la ultima vez que se vio ese error y no la
 * primera, porque lo que se conserva 90 dias es un grupo de errores **vivo**
 * -uno que sigue ocurriendo cada dia no vence porque su primera aparicion sea
 * antigua-.
 *
 * Estuvieron un tiempo en `config/compliance.php`, para que la tarea que creara
 * la tabla pudiera ajustarlas sin tocar este fichero. **Ya no**: las mismas
 * filas las purgan dos comandos distintos -`compliance:apply-retention` por
 * este ciclo y `product:errors:prune` por el repositorio de errores de
 * `Product`, que lleva la columna escrita en su propio SQL- y una variable de
 * entorno que apuntara a otra tabla o a otra columna dejaria a los dos purgando
 * cosas distintas sin que nada lo dijera. Con dos constantes, el desalineamiento
 * exige un cambio de codigo que se revisa.
 *
 * ## Que el almacen no este instalado se dice, no se supone
 *
 * `error_events` la crea la migracion de la tarea 5.12, asi que en una
 * instalacion con las migraciones aplicadas la tabla esta siempre. La rama
 * «no instalado» -{@see RetentionTally::unavailable()}- solo es alcanzable **sin
 * migraciones**: una base a medio montar, o un informe pedido antes de
 * `migrate`. Se informa asi y no con «0 filas» porque este
 * informe se archiva, y un cero afirmaria que el ciclo corto de RL-11 corrio
 * sobre una tabla que no existe.
 */
final readonly class DatabaseErrorHistoryArchive implements ErrorHistoryArchive
{
    /** La tabla del historico de errores (RF-PD-15). */
    private const string TABLE = 'error_events';

    /** La columna por la que envejece un grupo: su ultima ocurrencia. */
    private const string COLUMN = 'last_seen_at';

    public function __construct(private ConnectionInterface $connection) {}

    public function scope(): RetentionScope
    {
        return RetentionScope::ErrorHistory;
    }

    public function inspect(DateTimeImmutable $cutoff): RetentionTally
    {
        if (! $this->isInstalled()) {
            return RetentionTally::unavailable(RetentionScope::ErrorHistory, self::TABLE);
        }

        /** @var object{row_count: int|string, oldest: string|null, newest: string|null}|null $row */
        $row = $this->connection->selectOne(
            'SELECT count(*) AS row_count, min('.self::COLUMN.')::date::text AS oldest, '
            .'max('.self::COLUMN.')::date::text AS newest FROM '.self::TABLE.' WHERE '.self::COLUMN.' < ?',
            [$cutoff->format(DateTimeImmutable::ATOM)],
        );

        return new RetentionTally(
            scope: RetentionScope::ErrorHistory,
            dataset: self::TABLE,
            rows: (int) ($row->row_count ?? 0),
            oldest: $row->oldest ?? null,
            newest: $row->newest ?? null,
        );
    }

    public function purge(DateTimeImmutable $cutoff, int $batchSize): RetentionTally
    {
        $pending = $this->inspect($cutoff);

        if (! $pending->available || $pending->isEmpty()) {
            return $pending;
        }

        $limit = max(1, $batchSize);
        $deleted = 0;

        // En su propio ciclo y en transacciones cortas: son datos tecnicos sin
        // valor probatorio y no tienen por que compartir transaccion con el
        // registro de jornada, que si lo tiene.
        do {
            $affected = $this->connection->affectingStatement(
                'DELETE FROM '.self::TABLE.' WHERE id IN ('
                .'SELECT id FROM '.self::TABLE.' WHERE '.self::COLUMN.' < ? ORDER BY id LIMIT '.$limit.')',
                [$cutoff->format(DateTimeImmutable::ATOM)],
            );

            $deleted += $affected;
        } while ($affected > 0);

        return new RetentionTally(
            scope: RetentionScope::ErrorHistory,
            dataset: self::TABLE,
            rows: $deleted,
            oldest: $pending->oldest,
            newest: $pending->newest,
        );
    }

    /**
     * Si la tabla existe.
     *
     * Solo puede faltar sin migraciones aplicadas (ver la cabecera): se
     * pregunta igualmente porque el informe de retencion se genera tambien en
     * una base a medio montar y ahi tiene que decir la verdad.
     */
    private function isInstalled(): bool
    {
        /** @var object{present: string|null}|null $row */
        $row = $this->connection->selectOne('SELECT to_regclass(?)::text AS present', ['public.'.self::TABLE]);

        return ($row->present ?? null) !== null;
    }
}

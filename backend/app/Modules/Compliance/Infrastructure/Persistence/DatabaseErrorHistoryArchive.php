<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Persistence;

use App\Modules\Compliance\Application\Port\ErrorHistoryArchive;
use App\Modules\Compliance\Domain\ValueObject\RetentionScope;
use App\Modules\Compliance\Domain\ValueObject\RetentionTally;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/**
 * El historico de errores a 90 dias (RF-PD-15, RL-11).
 *
 * ## La tabla puede no existir todavia, y eso se dice
 *
 * `error_events` la crea la **tarea 5.12**. Hasta entonces este adaptador no
 * falla ni miente: informa de que el almacen **no esta instalado**. Un informe de
 * retencion que dijera «0 filas» estaria afirmando que el ciclo corto de RL-11
 * corre sobre una tabla que no existe, y ese informe se archiva.
 *
 * El ciclo se escribe aqui y no en la 5.12 porque la politica de retencion por
 * tipo de dato se decide en esta tarea, con el diseno delante. La 5.12 crea la
 * tabla y se la encuentra ya purgandose.
 *
 * ## La columna del corte es configuracion, no codigo
 *
 * `last_seen_at` -la ultima vez que se vio ese error, no la primera-, porque lo
 * que se conserva 90 dias es un grupo de errores **vivo**: uno que sigue
 * ocurriendo cada dia no vence porque su primera aparicion sea antigua. Va en
 * `config/compliance.php` para que la 5.12 pueda ajustarlo sin tocar este
 * fichero si la tabla nace con otro nombre de columna.
 *
 * Tabla y columna se validan como identificadores simples antes de concatenarse:
 * salen de la configuracion y no de una peticion, pero un identificador
 * concatenado sin validar es la unica forma de que una consulta de aqui se
 * convierta en otra cosa.
 */
final readonly class DatabaseErrorHistoryArchive implements ErrorHistoryArchive
{
    public function __construct(private ConnectionInterface $connection) {}

    public function scope(): RetentionScope
    {
        return RetentionScope::ErrorHistory;
    }

    public function inspect(DateTimeImmutable $cutoff): RetentionTally
    {
        $table = $this->table();

        if (! $this->isInstalled($table)) {
            return RetentionTally::unavailable(RetentionScope::ErrorHistory, $table);
        }

        $column = $this->column();

        /** @var object{row_count: int|string, oldest: string|null, newest: string|null}|null $row */
        $row = $this->connection->selectOne(
            'SELECT count(*) AS row_count, min('.$column.')::date::text AS oldest, '
            .'max('.$column.')::date::text AS newest FROM '.$table.' WHERE '.$column.' < ?',
            [$cutoff->format(DateTimeImmutable::ATOM)],
        );

        return new RetentionTally(
            scope: RetentionScope::ErrorHistory,
            dataset: $table,
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

        $table = $this->table();
        $column = $this->column();
        $limit = max(1, $batchSize);
        $deleted = 0;

        // En su propio ciclo y en transacciones cortas: son datos tecnicos sin
        // valor probatorio y no tienen por que compartir transaccion con el
        // registro de jornada, que si lo tiene.
        do {
            $affected = $this->connection->affectingStatement(
                'DELETE FROM '.$table.' WHERE id IN ('
                .'SELECT id FROM '.$table.' WHERE '.$column.' < ? ORDER BY id LIMIT '.$limit.')',
                [$cutoff->format(DateTimeImmutable::ATOM)],
            );

            $deleted += $affected;
        } while ($affected > 0);

        return new RetentionTally(
            scope: RetentionScope::ErrorHistory,
            dataset: $table,
            rows: $deleted,
            oldest: $pending->oldest,
            newest: $pending->newest,
        );
    }

    private function isInstalled(string $table): bool
    {
        /** @var object{present: string|null}|null $row */
        $row = $this->connection->selectOne('SELECT to_regclass(?)::text AS present', ['public.'.$table]);

        return ($row->present ?? null) !== null;
    }

    private function table(): string
    {
        return $this->identifier(Config::string('compliance.retention.error_history.table', 'error_events'));
    }

    private function column(): string
    {
        return $this->identifier(Config::string('compliance.retention.error_history.column', 'last_seen_at'));
    }

    private function identifier(string $value): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $value) !== 1) {
            throw new InvalidArgumentException(
                'El identificador «'.$value.'» de compliance.retention.error_history no es un nombre simple.'
            );
        }

        return $value;
    }
}

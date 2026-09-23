<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Persistence;

use App\Modules\Reporting\Application\Port\WeeklySummaryDeliveries;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * `weekly_summary_deliveries` sobre PostgreSQL (RF-PR-05, decisiones 6 y 13 de
 * la ficha 3.12).
 *
 * ## La colision del `UNIQUE` se atrapa aqui, y se traduce a «ya esta enviado»
 *
 * `wasSent()` es la comprobacion barata; el control de concurrencia es el
 * `UNIQUE (manager_user_id, week_start)`, porque entre el `SELECT` y el `INSERT`
 * cabe otra pasada. Cuando esa carrera se pierde, la respuesta correcta **no es
 * un error**: significa que el resumen de esa semana ya salio por la otra
 * pasada, que es exactamente lo que se queria. Se devuelve `false` y quien llama
 * lo cuenta como omitido.
 *
 * Se atrapa la excepcion **acotada** de Laravel —`UniqueConstraintViolation`,
 * que el driver de PostgreSQL produce para el `23505`— y no `QueryException` a
 * secas: una tabla que no existe, una columna renombrada o una conexion caida
 * tienen que seguir tumbando la pasada en voz alta.
 *
 * ## `week_start` es una fecha civil, no un instante
 *
 * El lunes de la semana en el calendario del centro. `DATE` y no `TIMESTAMPTZ`
 * (la regla dura 3 habla de instantes): «la semana del 14 de septiembre» no
 * tiene hora ni zona, y guardarla como instante la habria movido de semana en
 * cada conversion.
 */
final readonly class DatabaseWeeklySummaryDeliveries implements WeeklySummaryDeliveries
{
    public function __construct(private ConnectionInterface $connection) {}

    public function wasSent(int $managerUserId, string $weekStart): bool
    {
        return $this->connection->table('weekly_summary_deliveries')
            ->where('manager_user_id', $managerUserId)
            ->where('week_start', $weekStart)
            ->exists();
    }

    public function claim(
        int $managerUserId,
        string $weekStart,
        DateTimeImmutable $sentAt,
        int $employeeCount,
        int $rowCount,
    ): bool {
        try {
            $this->connection->table('weekly_summary_deliveries')->insert([
                'manager_user_id' => $managerUserId,
                'week_start' => $weekStart,
                'sent_at' => $sentAt->format('Y-m-d H:i:s.uP'),
                'employee_count' => $employeeCount,
                'row_count' => $rowCount,
                'created_at' => $sentAt->format('Y-m-d H:i:s.uP'),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Otra pasada se llevo la semana entre el `wasSent()` y esto. Ver el
            // docblock: no es una averia, es la carrera resolviendose bien.
            return false;
        }

        return true;
    }

    public function release(int $managerUserId, string $weekStart): void
    {
        $this->connection->table('weekly_summary_deliveries')
            ->where('manager_user_id', $managerUserId)
            ->where('week_start', $weekStart)
            ->delete();
    }
}

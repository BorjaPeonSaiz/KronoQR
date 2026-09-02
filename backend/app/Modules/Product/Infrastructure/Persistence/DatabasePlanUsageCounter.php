<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Persistence;

use App\Modules\Product\Application\Port\PlanUsageCounter;
use App\Modules\Product\Domain\ValueObject\PlanLimit;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Cuenta plantilla y quioscos para compararlos con el plan (**ADR-028**).
 *
 * ## Que se cuenta, y por que asi
 *
 * - **`max_employees` → personas con `status = 'active'`.** Quien esta de baja o
 *   despedido no ocupa plaza del plan, aunque su registro se conserve los cuatro
 *   años de RL-02. Contar tambien a los terminados convertiria a cualquier hotel
 *   de temporada en un exceso permanente al tercer verano, con su banner y sus
 *   asientos, sin que hubiera crecido nada.
 * - **`max_devices` → dispositivos con `status = 'active'`.** Un quiosco
 *   averiado y revocado libera su plaza en el acto, que es exactamente lo que
 *   hace falta el dia que hay que sustituirlo. Es lo contrario de lo que ADR-028
 *   describe como el escenario a evitar.
 *
 * ## Consultas directas, sin modelo
 *
 * `SELECT count(*)` sobre dos tablas de otros modulos. Es lectura y solo
 * lectura: `Product` no puede importar `Workforce` ni `Identity` (doc 02 §1.6) y
 * aqui no lo hace —no aparece ni una clase de esos modulos—, solo se consulta el
 * esquema, que es compartido. La alternativa —pedirles la cifra por un puerto—
 * obligaria a esos modulos a exponer un metodo que existe solo para el conteo
 * comercial, y a que alguien pudiera llamarlo desde el alta.
 *
 * ## No puede fallar hacia arriba
 *
 * Devuelve `0` y deja un aviso si la consulta falla. Un contador comercial roto
 * no puede impedir un alta ni tumbar la pantalla de licencia (ADR-028: el
 * conteo es un observador).
 */
final readonly class DatabasePlanUsageCounter implements PlanUsageCounter
{
    public function __construct(
        private ConnectionInterface $connection,
        private LoggerInterface $logger,
    ) {}

    public function count(PlanLimit $limit): int
    {
        [$table, $column, $value] = match ($limit) {
            PlanLimit::Employees => ['employees', 'status', 'active'],
            PlanLimit::Devices => ['devices', 'status', 'active'],
        };

        try {
            return (int) $this->connection->table($table)->where($column, $value)->count();
        } catch (Throwable $exception) {
            $this->logger->warning('product.plan_usage_uncountable', [
                'limit' => $limit->value,
                'reason' => $exception::class,
            ]);

            return 0;
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Telemetry;

use App\Modules\Product\Application\Port\TelemetryFacts;
use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * Los tres datos del informe que solo estan en PostgreSQL (**RF-PD-12**).
 *
 * ## Dos `count(*)` y un `SHOW`, y nada mas
 *
 * `departments` e `incidents` se cuentan enteras: sale un numero, sin `WHERE`
 * sobre nada personal, sin nombres y sin identificadores. La plantilla y los
 * quioscos activos **no se cuentan aqui**: los cuenta `PlanUsageCounter`, que ya
 * existe para ADR-028 y ya esta probado. Duplicar esas dos consultas seria
 * abrir la puerta a que un dia dijeran cosas distintas.
 *
 * ## La version del motor va recortada, y ese es el motivo de que exista
 *
 * `SELECT version()` devuelve *«PostgreSQL 17.2 (Debian 17.2-1.pgdg120+1) on
 * x86_64-pc-linux-gnu, compiled by gcc … »*: el compilador y **rutas de
 * compilacion del servidor**. Las rutas del servidor no salen de la instalacion
 * (ficha 5.10 punto 8). `SHOW server_version` da algo mas corto y aun asi lleva
 * el paquete de la distribucion entre parentesis, asi que se recorta al numero:
 * `17.2`.
 *
 * ## Consultas directas, sin modelo, y sin importar otros modulos
 *
 * Mismo criterio que `DatabasePlanUsageCounter`: es lectura y solo lectura sobre
 * el esquema, que es compartido. `Product` no puede importar `Workforce` ni
 * `Compliance` (doc 02 §1.6), y aqui no lo hace.
 *
 * ## Nunca lanza
 *
 * Devuelve `0` o `null`. La telemetria es accesoria y no puede ser la causa de
 * que un comando termine mal.
 */
final readonly class DatabaseTelemetryFacts implements TelemetryFacts
{
    public function __construct(private ConnectionInterface $connection) {}

    public function databaseVersion(): ?string
    {
        try {
            /** @var object{server_version: string}|null $row */
            $row = $this->connection->selectOne('SHOW server_version');
        } catch (Throwable) {
            return null;
        }

        if ($row === null) {
            return null;
        }

        // `17.2 (Debian 17.2-1.pgdg120+1)` -> `17.2`. Sin la distribucion, que es
        // del servidor del cliente y no hace falta para nada.
        return preg_match('/^\d+(\.\d+)*/', (string) $row->server_version, $matches) === 1
            ? $matches[0]
            : null;
    }

    public function departments(): int
    {
        return $this->count('departments');
    }

    public function openIncidents(): ?int
    {
        try {
            return (int) $this->connection->table('incidents')->where('status', 'open')->count();
        } catch (Throwable) {
            return null;
        }
    }

    private function count(string $table): int
    {
        try {
            return (int) $this->connection->table($table)->count();
        } catch (Throwable) {
            return 0;
        }
    }
}

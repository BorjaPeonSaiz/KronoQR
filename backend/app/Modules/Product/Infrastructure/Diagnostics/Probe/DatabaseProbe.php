<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics\Probe;

use App\Modules\Product\Application\Port\DoctorProbe;
use App\Modules\Product\Domain\ValueObject\DoctorFinding;
use App\Modules\Product\Infrastructure\Diagnostics\ServiceInspector;

/**
 * Sondas `database.*` de `product:doctor` (RF-PD-13).
 *
 * ## Cinco comprobaciones, y la tercera es la que mas importa
 *
 * `database.audit_log_privileges` verifica que el usuario de la aplicacion **no
 * tiene `UPDATE` ni `DELETE` sobre `audit_log`** (regla dura 6). Es la
 * comprobacion mas facil de perder de todo el producto: una restauracion hecha
 * con el usuario equivocado, un `GRANT ALL` puesto para salir de un apuro un
 * viernes. Si esos permisos existen, la cadena de hash deja de ser una garantia
 * legal, y nadie se entera hasta que una inspeccion lo pregunta — cuando ya no
 * tiene arreglo.
 *
 * `database.work_record_privileges` es su hermana para el registro horario y el
 * perfil de cumplimiento (ADR-057): avisa si el mismo usuario ha recuperado
 * privilegios que las migraciones de ADR-057 le retiraron.
 *
 * ## Si la base de datos no responde, se dice una vez y se para
 *
 * Y no cinco. Las otras cuatro comprobaciones necesitan la misma conexion:
 * repetir «no se pudo conectar» cinco veces con cinco identificadores
 * distintos convierte un problema en cinco y esconde el unico que hay.
 */
final readonly class DatabaseProbe implements DoctorProbe
{
    public function __construct(private ServiceInspector $services) {}

    public function family(): string
    {
        return 'database';
    }

    public function run(): array
    {
        $database = $this->services->database();

        if ($database['reachable'] !== true) {
            return [DoctorFinding::failure(
                'database.connection',
                details: $database,
            )];
        }

        /** @var list<string> $pending */
        $pending = is_array($database['pending_migrations'] ?? null) ? $database['pending_migrations'] : [];

        $version = is_string($database['server_version'] ?? null) ? $database['server_version'] : '?';
        $applied = is_int($database['applied_migrations'] ?? null) ? $database['applied_migrations'] : 0;

        return [
            DoctorFinding::ok(
                'database.connection',
                ['server_version' => $version, 'applied_migrations' => $applied],
                // La version del servidor de bases de datos, recortada: la
                // cadena completa de Postgres son ochenta caracteres con el
                // compilador y la arquitectura dentro, y el informe lo lee una
                // persona.
                ['server_version' => explode(' (', $version)[0], 'applied_migrations' => $applied],
            ),
            $this->migrations($pending),
            $this->privileges(),
            ...$this->workRecordPrivileges(),
            $this->chain(),
        ];
    }

    /**
     * @param  list<string>  $pending
     */
    private function migrations(array $pending): DoctorFinding
    {
        if ($pending === []) {
            return DoctorFinding::ok('database.migrations_pending', ['pending' => 0]);
        }

        // `failure` y no `warning`: con migraciones sin aplicar, el codigo y el
        // esquema no coinciden y cualquier cosa puede fallar de forma rara.
        return DoctorFinding::failure(
            'database.migrations_pending',
            params: ['count' => \count($pending)],
            details: ['pending' => $pending],
        );
    }

    private function privileges(): DoctorFinding
    {
        $privileges = $this->services->auditLogPrivileges();

        if ($privileges['reachable'] !== true) {
            return DoctorFinding::warning('database.audit_log_privileges', 'unknown', details: $privileges);
        }

        if ($privileges['can_update'] === true || $privileges['can_delete'] === true) {
            return DoctorFinding::failure(
                'database.audit_log_privileges',
                params: ['user' => is_string($privileges['database_user'] ?? null) ? $privileges['database_user'] : '?'],
                details: $privileges,
            );
        }

        return DoctorFinding::ok('database.audit_log_privileges', $privileges);
    }

    /**
     * `database.work_record_privileges` (ADR-057 §1 a §3 y §7, RL-04, RS-07).
     *
     * Con la migracion de las guardas aplicada (ADR-057 §2 y §3) mira tambien: los
     * *triggers* de `shift_entries` e `incidents` (que existan y que ninguno
     * este desactivado), la funcion de purga `SECURITY DEFINER` con
     * `search_path`, y que la aplicacion no tenga `DELETE` sobre el registro.
     *
     * **Aviso y no fallo**: el fichaje y el registro siguen funcionando, y
     * `update.sh` lo repara en la siguiente actualizacion. Pero quien ejecute
     * codigo en la aplicacion podria, mientras tanto, reescribir fichajes o
     * rebajar el suelo legal de conservacion, y eso tiene que verlo alguien.
     *
     * Sin las migraciones que retiran esos privilegios no hay nada que vigilar
     * y la comprobacion no aparece: `database.migrations_pending` ya lo dice.
     *
     * @return list<DoctorFinding>
     */
    private function workRecordPrivileges(): array
    {
        $privileges = $this->services->workRecordPrivileges();

        if ($privileges['reachable'] !== true) {
            return [DoctorFinding::warning('database.work_record_privileges', 'unknown', details: $privileges)];
        }

        if ($privileges['expected'] !== true) {
            return [];
        }

        $excess = self::strings($privileges['excess'] ?? null);
        $guardIssues = self::strings($privileges['guard_issues'] ?? null);
        $user = is_string($privileges['database_user'] ?? null) ? $privileges['database_user'] : '?';

        // Las guardas del registro (ADR-057 §2 y §3): un *trigger* que falta o
        // esta desactivado, o una funcion de purga que ya no es `SECURITY
        // DEFINER` con su `search_path`. Se dice antes que lo que sobra y en el
        // mismo aviso, porque las dos cosas se arreglan igual: con `update.sh`
        // y preguntando quien tiene el rol de migracion.
        if ($guardIssues !== []) {
            return [DoctorFinding::warning(
                'database.work_record_privileges',
                'guards',
                params: ['user' => $user, 'issues' => implode(', ', [...$guardIssues, ...$excess])],
                details: $privileges,
            )];
        }

        if ($excess === []) {
            return [DoctorFinding::ok('database.work_record_privileges', $privileges)];
        }

        return [DoctorFinding::warning(
            'database.work_record_privileges',
            'excess',
            params: ['user' => $user, 'excess' => implode(', ', $excess)],
            details: $privileges,
        )];
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }

    private function chain(): DoctorFinding
    {
        $anchor = $this->services->auditChainAnchor();

        return match ($anchor['status'] ?? null) {
            'verified' => DoctorFinding::ok('database.audit_chain', $anchor),
            // Sin ningun año sellado no hay nada que verificar, y eso es lo
            // normal en una instalacion de menos de un año. No es un aviso.
            'no_anchor' => DoctorFinding::ok('database.audit_chain', $anchor),
            'mismatch' => DoctorFinding::failure('database.audit_chain', details: $anchor),
            default => DoctorFinding::warning('database.audit_chain', 'unknown', details: $anchor),
        };
    }
}

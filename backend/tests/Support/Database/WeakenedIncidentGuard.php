<?php

declare(strict_types=1);

namespace Tests\Support\Database;

use Illuminate\Database\ConnectionInterface;
use LogicException;

/**
 * `incidents_guard_transition` reescrita con el rol de migracion, para las
 * pruebas de la huella que vigilan `product:doctor` y
 * `infra/scripts/lib/work-record-privileges.sh` (RN-19, enmienda del
 * 27-10-2026 de ADR-057 §2).
 *
 * Los debilitamientos siguen nombrando `rejected_pin_scan`, que es lo unico
 * que miraba la comprobacion anterior: un cuerpo vacio con el nombre en un
 * comentario y la reapertura sin exigir que no la resolviera una persona.
 */
final class WeakenedIncidentGuard
{
    /**
     * @return array<string, array{string}>
     */
    public static function weakenings(): array
    {
        return [
            'cuerpo vacio con el nombre en un comentario' => ['empty'],
            'reapertura tambien de lo que resolvio una persona' => ['any_author'],
        ];
    }

    /** El cuerpo (`prosrc`) de la funcion en la base migrada. */
    public static function body(ConnectionInterface $connection): string
    {
        /** @var object{prosrc: string} $row */
        $row = $connection->selectOne(
            "SELECT prosrc FROM pg_catalog.pg_proc WHERE oid = 'public.incidents_guard_transition()'::regprocedure",
        );

        return $row->prosrc;
    }

    /** `$body` con el debilitamiento `$weakening`; falla si no cambia nada. */
    public static function weaken(string $body, string $weakening): string
    {
        $weakened = match ($weakening) {
            'empty' => "BEGIN RETURN NEW; END -- rejected_pin_scan\n",
            'any_author' => str_replace("AND OLD.resolved_by_user_id IS NULL\n", "\n", $body),
            default => throw new LogicException('Debilitamiento desconocido: '.$weakening),
        };

        if ($weakened === $body || ! str_contains($weakened, 'rejected_pin_scan')) {
            throw new LogicException('El debilitamiento '.$weakening.' no cambia la funcion o deja de nombrar el tipo');
        }

        return $weakened;
    }

    /** `CREATE OR REPLACE` de la funcion con el cuerpo `$body`. */
    public static function replaceStatement(string $body): string
    {
        return 'CREATE OR REPLACE FUNCTION public.incidents_guard_transition() RETURNS trigger LANGUAGE plpgsql '
            .'SET search_path = pg_catalog, pg_temp AS $kq$'.$body.'$kq$';
    }
}

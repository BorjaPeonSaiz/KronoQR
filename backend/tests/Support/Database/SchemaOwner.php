<?php

declare(strict_types=1);

namespace Tests\Support\Database;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * La sesion del rol propietario del esquema, para preparar en una prueba lo que
 * el rol de la aplicacion **ya no puede escribir** (ADR-057 §1 y §7, bloque 6
 * de la 2.2.1): reasignar el perfil de cumplimiento del centro, cambiar su
 * jurisdiccion, crear otro perfil, reescribir la entrada de un tramo o una
 * correccion.
 *
 * Es **otra sesion de PostgreSQL**: no ve lo que la transaccion envolvente de
 * `RefreshDatabase` no ha confirmado, y lo que escribe se confirma solo. Por eso
 * quien la usa para preparar datos que despues lee la aplicacion va con
 * {@see CommittedDatabase}, que vacia y restaura con este mismo rol. Para
 * comprobar una restriccion sin dejar nada, se abre y se deshace una
 * transaccion en ella, como ya hacen `RetentionTest` y `AuditLogTest`.
 *
 * Ningun codigo de `app/` la usa (ADR-042, `RuntimeDatabaseCredentialsTest`).
 */
final class SchemaOwner
{
    public static function connection(): ConnectionInterface
    {
        return DB::connection(config()->string('database.migrations.connection'));
    }
}

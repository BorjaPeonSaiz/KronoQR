<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Support\Facades\DB;

/**
 * El centro de trabajo de la instalacion de desarrollo.
 *
 * Uno solo (ADR-040): el indice `sites_single_row_uidx` no admite mas, y el
 * producto se vende como una licencia por hotel. El nombre es ficticio y no
 * viene de ningun cliente (regla dura 13).
 */
final class SiteSeeder extends DevelopmentSeeder
{
    private const string NAME = 'Hotel Marina';

    private const string TIMEZONE = 'Europe/Madrid';

    public function run(): void
    {
        // El perfil de cumplimiento por defecto lo crea la migracion de
        // `compliance_profiles` (tarea 1.3): es dato de producto, no de
        // desarrollo. El centro nace SIN perfil asignado y cae en el de
        // `is_default`, igual que el que crea el asistente: la semilla corre
        // con el rol de la aplicacion, que desde ADR-057 §7 solo puede escribir
        // `name` y `timezone` en `sites` (ni `compliance_profile_id` ni
        // `settings`; `created_at` lo pone su valor por omision).
        //
        // Idempotente: re-ejecutar la semilla no duplica el centro ni pisa los
        // ajustes que alguien haya tocado a mano en su entorno. Si ya hay un
        // centro con otro nombre, se conserva: es EL centro, y renombrarlo desde
        // una semilla seria pisar un dato del entorno.
        if (DB::table('sites')->exists()) {
            return;
        }

        DB::table('sites')->insert([
            'name' => self::NAME,
            'timezone' => self::TIMEZONE,
        ]);
    }
}

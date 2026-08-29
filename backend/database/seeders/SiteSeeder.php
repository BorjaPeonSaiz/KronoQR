<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * El centro de trabajo de la instalacion de desarrollo.
 *
 * Uno solo (ADR-040): el indice `sites_single_row_uidx` no admite mas, y el
 * producto se vende como una licencia por hotel. El nombre es ficticio y no
 * viene de ningun cliente (regla dura 13).
 */
final class SiteSeeder extends Seeder
{
    private const string NAME = 'Hotel Marina';

    private const string TIMEZONE = 'Europe/Madrid';

    public function run(): void
    {
        // El perfil de cumplimiento por defecto lo crea la migracion de
        // `compliance_profiles` (tarea 1.3): es dato de producto, no de
        // desarrollo. Aqui solo se asigna, y si faltara el centro nace sin
        // perfil y cae en el `is_default`, que es lo que el esquema permite.
        $profile = DB::table('compliance_profiles')->select('id')->where('is_default', true)->first();

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
            'compliance_profile_id' => $profile?->id,
            'settings' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}

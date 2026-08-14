<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Los tres centros de la semilla de desarrollo (doc 02 §10.2).
 *
 * Cada centro nace con su zona horaria EXPLICITA porque es de lo que depende
 * RN-05: la jornada es la fecha civil, en la zona del centro, del inicio del
 * tramo que la abre. Un centro sin zona horaria correcta atribuye los turnos
 * nocturnos al dia equivocado, y eso no se ve hasta que alguien revisa una
 * nomina.
 *
 * Las tres zonas no son decorativas: Atlantic/Canary tiene una hora menos que
 * Europe/Madrid siendo el mismo pais, y Europe/Lisbon cambia de hora en el
 * mismo instante UTC pero a otra hora local. Es el minimo para que las pruebas
 * de la Fase 1 no den por bueno un calculo que solo funciona en Madrid.
 *
 * Datos ficticios: nada especifico de un cliente entra en el repositorio
 * (ADR-017, regla dura 13).
 */
final class SiteSeeder extends Seeder
{
    /**
     * @var list<array{name: string, timezone: string}>
     */
    private const array SITES = [
        ['name' => 'Hotel Marina', 'timezone' => 'Europe/Madrid'],
        ['name' => 'Hotel Atlantico', 'timezone' => 'Atlantic/Canary'],
        ['name' => 'Hotel Ribeira', 'timezone' => 'Europe/Lisbon'],
    ];

    public function run(): void
    {
        $now = now();

        foreach (self::SITES as $site) {
            // Idempotente: re-ejecutar la semilla no duplica centros ni pisa
            // los ajustes que alguien haya tocado a mano en su entorno.
            DB::table('sites')->updateOrInsert(
                ['name' => $site['name']],
                [
                    'timezone' => $site['timezone'],
                    'settings' => json_encode([], JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                ],
            );
        }
    }
}

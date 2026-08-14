<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Departamentos base de cada centro (doc 01 §2: Recepcion, Pisos, Cocina,
 * Sala, Mantenimiento y SPA).
 *
 * Son los que el dominio necesita para que existan responsables distintos con
 * ambito distinto (RF-ID-03) y para que los informes por departamento del
 * doc 01 §8 tengan sobre que agregar.
 *
 * El responsable (manager_user_id) se asigna en la tarea 1.3, que es la que
 * crea los usuarios.
 */
final class DepartmentSeeder extends Seeder
{
    /**
     * @var list<string>
     */
    private const array DEPARTMENTS = [
        'Recepcion',
        'Pisos',
        'Cocina',
        'Sala',
        'Mantenimiento',
        'SPA',
    ];

    public function run(): void
    {
        /** @var list<object{id: int}> $sites */
        $sites = DB::table('sites')->select('id')->orderBy('id')->get()->all();

        foreach ($sites as $site) {
            foreach (self::DEPARTMENTS as $name) {
                DB::table('departments')->updateOrInsert(
                    ['site_id' => $site->id, 'name' => $name],
                    [],
                );
            }
        }
    }
}

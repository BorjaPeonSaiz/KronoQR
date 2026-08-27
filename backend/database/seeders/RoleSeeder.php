<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Quien tiene cada rol en la semilla de **desarrollo** (RF-ID-02).
 *
 * **El catalogo de roles no se crea aqui**: lo siembra su migracion, porque es
 * dato de producto y en la instalacion de un cliente no se ejecuta ningun
 * seeder. Este fichero solo hace la parte que si es de desarrollo —repartir esos
 * roles entre las cuentas de `UserSeeder`— para que la suite y el entorno local
 * tengan un `admin`, un `rrhh`, un `auditor` y varios responsables con los que
 * probar la autorizacion negativa.
 *
 * El reparto se hace por correo porque es lo unico estable de la semilla: los
 * identificadores cambian entre ejecuciones y los UUID son aleatorios.
 */
final class RoleSeeder extends Seeder
{
    /**
     * @var array<string, string>
     */
    private const array ROLE_BY_EMAIL = [
        'admin@kronoqr.test' => 'admin',
        'rrhh@kronoqr.test' => 'rrhh',
        'auditor@kronoqr.test' => 'auditor',
    ];

    public function run(): void
    {
        foreach (self::ROLE_BY_EMAIL as $email => $role) {
            $this->assign($email, $role);
        }

        $this->assignDepartmentManagers();
    }

    /**
     * Los responsables de departamento que creo `UserSeeder`. Su ambito real
     * —ver solo su departamento— es RF-ID-03 y llega en la tarea 2.1; el rol se
     * asigna ya para que exista sobre que probar que **hoy no le abre nada**.
     */
    private function assignDepartmentManagers(): void
    {
        /** @var list<object{email: string}> $managers */
        $managers = DB::table('users')
            ->select('email')
            ->whereNotIn('email', array_keys(self::ROLE_BY_EMAIL))
            ->get()
            ->all();

        foreach ($managers as $manager) {
            $this->assign($manager->email, 'responsable_departamento');
        }
    }

    private function assign(string $email, string $role): void
    {
        /** @var object{id: int}|null $user */
        $user = DB::table('users')->select('id')->where('email', $email)->first();

        /** @var object{id: int}|null $roleRow */
        $roleRow = DB::table('roles')->select('id')->where('name', $role)->where('guard_name', 'web')->first();

        if ($user === null || $roleRow === null) {
            return;
        }

        // `insertOrIgnore`: repetir la semilla no puede romper por la clave
        // primaria compuesta del pivote.
        DB::table('model_has_roles')->insertOrIgnore([
            'role_id' => $roleRow->id,
            'model_type' => 'App\Modules\Identity\Infrastructure\Persistence\User',
            'model_id' => $user->id,
        ]);
    }
}

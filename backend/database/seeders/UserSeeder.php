<?php

declare(strict_types=1);

namespace Database\Seeders;

use DateTimeInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Usuarios de **gestion** de la semilla de desarrollo (RF-ID-01).
 *
 * No son empleados: el empleado entra a su portal con codigo y PIN y puede no
 * tener correo (regla dura 12). Aqui estan las personas que emiten tarjetas,
 * corrigen jornadas y firman exportaciones.
 *
 * Los **roles** no se asignan aqui: el catalogo de RF-ID-02 y su reparto de
 * permisos son de la tarea 1.6, que es la que sabe que permisos existen. Estas
 * filas son las que necesitan `departments.manager_user_id` y
 * `credentials.delivered_by_user_id` para no nacer vacias.
 *
 * Todas comparten contrasena de desarrollo, y se hashea **una sola vez**: 600
 * llamadas a bcrypt tardan mas que todo el resto de la semilla junta.
 */
final class UserSeeder extends Seeder
{
    /**
     * Contrasena de la semilla. Es un valor de desarrollo y no sale de aqui:
     * la instalacion de un cliente crea su primer administrador en el asistente
     * de puesta en marcha (RF-PD-03).
     */
    private const string DEVELOPMENT_PASSWORD = 'kronoqr-dev-only';

    /**
     * @var list<array{name: string, email: string}>
     */
    private const array MANAGEMENT_USERS = [
        ['name' => 'Admin Sistema', 'email' => 'admin@kronoqr.test'],
        ['name' => 'Direccion RRHH', 'email' => 'rrhh@kronoqr.test'],
        ['name' => 'Auditoria Interna', 'email' => 'auditor@kronoqr.test'],
    ];

    public function run(): void
    {
        $password = Hash::make(self::DEVELOPMENT_PASSWORD);
        $now = now();

        foreach (self::MANAGEMENT_USERS as $user) {
            // `insertOrIgnore` y no `updateOrInsert`: el UUID publico de una
            // cuenta no puede cambiar porque alguien repita la semilla, y su
            // contrasena tampoco tiene por que volver a la de serie.
            DB::table('users')->insertOrIgnore([
                'uuid' => Str::uuid7()->toString(),
                'name' => $user['name'],
                'email' => $user['email'],
                'password' => $password,
                'locale' => 'es',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->assignDepartmentManagers($password, $now);
    }

    /**
     * Un responsable por departamento, que es lo que RF-ID-03 necesita para
     * tener ambitos distintos sobre los que probar la autorizacion negativa.
     *
     * El correo se deriva del centro y del departamento para que la semilla sea
     * reproducible: repetirla no crea usuarios nuevos.
     */
    private function assignDepartmentManagers(string $password, DateTimeInterface $now): void
    {
        /** @var list<object{id: int, name: string, site_id: int, site_name: string}> $departments */
        $departments = DB::table('departments')
            ->join('sites', 'sites.id', '=', 'departments.site_id')
            ->select([
                'departments.id',
                'departments.name',
                'departments.site_id',
                'sites.name as site_name',
            ])
            ->orderBy('departments.id')
            ->get()
            ->all();

        foreach ($departments as $department) {
            $email = Str::slug($department->site_name.'-'.$department->name).'@kronoqr.test';

            DB::table('users')->insertOrIgnore([
                'uuid' => Str::uuid7()->toString(),
                'name' => 'Responsable '.$department->name.' · '.$department->site_name,
                'email' => $email,
                'password' => $password,
                'locale' => 'es',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            /** @var object{id: int}|null $manager */
            $manager = DB::table('users')->select('id')->where('email', $email)->first();

            if ($manager !== null) {
                DB::table('departments')
                    ->where('id', $department->id)
                    ->update(['manager_user_id' => $manager->id]);
            }
        }
    }
}

<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Los seis roles de RF-ID-02 y su reparto de ambitos del doc 02 §7.3.
 *
 * **Por que en una migracion y no en un seeder.** Un seeder no se ejecuta en la
 * instalacion de un cliente, y sin roles el RBAC no tiene con que trabajar: la
 * primera cuenta de administrador no podria recibir ningun rol y el panel se
 * quedaria sin puerta de entrada. Es el mismo criterio que ya siguen
 * `compliance_profiles` y `installation_settings` (tarea 1.3): esto es **dato de
 * producto**, no dato de desarrollo. Lo que si es de desarrollo —quien tiene
 * cada rol— vive en `RoleSeeder`.
 *
 * **El catalogo es identico para todos los clientes** (regla dura 13, ADR-017).
 * Lo que cambia de una instalacion a otra es a quien se le asigna cada rol.
 *
 * **Por que los permisos son exactamente los ambitos de token.** Porque asi hay
 * un solo reparto y no dos: al abrir sesion, las *abilities* del token son los
 * permisos del rol. Con dos listas —una para el token y otra para el RBAC—
 * bastaria olvidarse de una para que un rol pudiera hacer por API lo que el
 * panel le niega.
 *
 * **Las cadenas estan escritas literalmente y no importadas del enum
 * `TokenAbility`.** Una migracion describe el estado del esquema en el momento
 * en que se escribio; si leyera una clase de la aplicacion, editar esa clase
 * cambiaria lo que hace una migracion ya ejecutada en el servidor de un cliente.
 * Que las dos copias digan lo mismo lo verifica una prueba de integracion, no la
 * buena fe.
 *
 * **La lectura de la tabla del §7.3.** Sus filas de gestion son acumulativas
 * —cada una dice «+ ...» sobre la anterior—, asi que `admin` es el superconjunto
 * de responsable, RRHH y auditor mas lo suyo. Es ademas lo que exige el Anexo B
 * del doc 01, donde `admin` entra en «rrhh+» y en «manager+».
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    private const string GUARD = 'web';

    /**
     * Rol -> ambitos (doc 02 §7.3).
     *
     * @var array<string, list<string>>
     */
    private const array ROLE_ABILITIES = [
        // Responsable de departamento. Su ALCANCE por departamento es RF-ID-03 y
        // llega en la tarea 2.1: hoy tiene ambitos pero ninguna ruta se los
        // acepta, y es deliberado —darle acceso ahora seria darle la plantilla
        // entera, que es justo lo que ese requisito viene a impedir—.
        'responsable_departamento' => [
            'attendance:read',
            'attendance:correct',
            'incidents:*',
        ],
        'rrhh' => [
            'attendance:read',
            'attendance:correct',
            'incidents:*',
            'employees:*',
            'credentials:*',
            'reports:*',
        ],
        'auditor' => [
            'attendance:read',
            'audit:read',
            'reports:legal',
        ],
        'admin' => [
            'attendance:read',
            'attendance:correct',
            'incidents:*',
            'employees:*',
            'credentials:*',
            'reports:*',
            'reports:legal',
            'audit:read',
            'settings:*',
            'license:*',
            'support:*',
            'diagnostics:*',
        ],
        // No son cuentas de panel: el empleado entra a su portal con codigo y
        // PIN (ADR-015) y el quiosco con token de dispositivo (RF-ID-04). Sus
        // roles existen porque RF-ID-02 los enumera y porque sus ambitos tienen
        // que estar declarados en un solo sitio.
        'empleado' => [
            'self:read',
        ],
        'kiosk' => [
            'scan:write',
            'roster:read',
            'heartbeat:write',
        ],
    ];

    public function up(): void
    {
        $this->limitLockWait();

        $now = now();

        foreach ($this->allAbilities() as $ability) {
            // `insertOrIgnore`: la migracion tiene que poder repetirse sobre una
            // instalacion que ya tenga parte del catalogo sin romper.
            DB::table('permissions')->insertOrIgnore([
                'name' => $ability,
                'guard_name' => self::GUARD,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (self::ROLE_ABILITIES as $role => $abilities) {
            DB::table('roles')->insertOrIgnore([
                'name' => $role,
                'guard_name' => self::GUARD,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $roleId = $this->idOf('roles', $role);

            foreach ($abilities as $ability) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $this->idOf('permissions', $ability),
                ]);
            }
        }
    }

    public function down(): void
    {
        $this->limitLockWait();

        // Se borran los pivotes primero y solo las filas que esta migracion
        // creo. Las asignaciones a personas (`model_has_roles`) caen por la
        // clave foranea en cascada de la tabla de roles, que es lo correcto: un
        // rol que deja de existir no puede quedar asignado a nadie.
        $roles = array_keys(self::ROLE_ABILITIES);
        $abilities = $this->allAbilities();

        DB::table('role_has_permissions')
            ->whereIn('role_id', DB::table('roles')->select('id')->whereIn('name', $roles))
            ->delete();

        DB::table('roles')->whereIn('name', $roles)->where('guard_name', self::GUARD)->delete();
        DB::table('permissions')->whereIn('name', $abilities)->where('guard_name', self::GUARD)->delete();
    }

    /**
     * @return list<string>
     */
    private function allAbilities(): array
    {
        $abilities = [];

        foreach (self::ROLE_ABILITIES as $roleAbilities) {
            foreach ($roleAbilities as $ability) {
                $abilities[$ability] = true;
            }
        }

        return array_keys($abilities);
    }

    private function idOf(string $table, string $name): int
    {
        /** @var object{id: int}|null $row */
        $row = DB::table($table)
            ->select('id')
            ->where('name', $name)
            ->where('guard_name', self::GUARD)
            ->first();

        if ($row === null) {
            throw new RuntimeException('No se ha podido sembrar el catalogo de RF-ID-02: falta «'.$name.'» en '.$table.'.');
        }

        return $row->id;
    }
};

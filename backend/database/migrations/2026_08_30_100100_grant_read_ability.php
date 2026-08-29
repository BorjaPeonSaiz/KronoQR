<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El ambito `employees:read` y su reparto (**RF-ID-03**, tarea 2.1).
 *
 * ## Por que aparece un ambito nuevo
 *
 * El Anexo B del doc 01 dice que `GET /employees` es `[rol: manager+]`, y
 * «manager+» incluye al `responsable_departamento`. La tabla del doc 02 §7.3, en
 * cambio, no le da ningun ambito de plantilla: solo `attendance:*` e
 * `incidents:*`. Con esa lista, RF-ID-03 —«un responsable solo accede a los
 * empleados de su departamento»— es inaplicable, porque un responsable no accede
 * a **ningun** empleado.
 *
 * Manda el doc 01 (orden de autoridad de `CLAUDE.md`). Se resuelve partiendo la
 * familia en dos y no dandole `employees:*`:
 *
 * - `employees:read` — ver la plantilla y las fichas, con el alcance del rol.
 * - `employees:*` — dar de alta, modificar, dar de baja y provisionar el PIN.
 *
 * Con un unico ambito de familia, darle a un responsable lo primero era darle
 * tambien lo segundo, y la unica defensa quedaba en la policy. Son dos controles
 * (doc 02 §7.3, regla dura 18) y ahora los dos dicen lo mismo.
 *
 * `admin` y `rrhh` reciben tambien `employees:read` **ademas** de `employees:*`:
 * las rutas de lectura pasan a exigir el ambito estrecho, y sin esta fila una
 * cuenta de RRHH dejaria de poder listar la plantilla en el mismo despliegue en el
 * que la ruta cambia.
 *
 * ## Expand puro
 *
 * Solo inserta: una fila en `permissions` y tres en `role_has_permissions`.
 * Ninguna se retira, ni siquiera `employees:*` de RRHH, porque la fase *contract*
 * de un permiso que deja de usarse va en una version posterior (doc 02 §10.4). No
 * hay `ALTER TABLE` y por tanto no hay bloqueo que medir.
 *
 * **`down()` verificado**: borra los pivotes y la fila de `permissions` que esta
 * migracion creo, y ninguna otra. Revertirla deja a los responsables sin acceso a
 * la plantilla, que es exactamente el estado anterior.
 *
 * **Las cadenas van escritas literalmente y no importadas del enum
 * `TokenAbility`**, por lo mismo que en la migracion del catalogo: una migracion
 * describe el esquema en el momento en que se escribio, y si leyera una clase de
 * la aplicacion, editar esa clase cambiaria lo que hace una migracion ya ejecutada
 * en el servidor de un cliente.
 *
 * ## El nombre del fichero es corto a proposito
 *
 * Se llamo primero `..._grant_employee_read_scope_to_department_manager.php`, y
 * con ese nombre **PHPStan pasaba de 0 errores a 13**: Larastan deducia las
 * propiedades de los modelos Eloquent de las migraciones y dejaba de reconocer las
 * de `PersonalAccessToken` —`name`, `abilities`, `expires_at`, `created_at`— en
 * ficheros que esta rama ni siquiera toca. Reproducido con la cache borrada y
 * aislado cambiando **solo el nombre**: el contenido es identico. No se ha
 * encontrado la causa dentro de Larastan; queda anotado para que nadie lo
 * renombre «para que se lea mejor» y pierda media tarde averiguando por que la CI
 * se pone roja en pruebas que no ha tocado.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    private const string GUARD = 'web';

    private const string ABILITY = 'employees:read';

    /** @var list<string> */
    private const array ROLES = ['admin', 'rrhh', 'responsable_departamento'];

    public function up(): void
    {
        $this->limitLockWait();

        $now = now();

        // `insertOrIgnore`: la migracion tiene que poder repetirse sobre una
        // instalacion que ya tenga parte del catalogo sin romper.
        DB::table('permissions')->insertOrIgnore([
            'name' => self::ABILITY,
            'guard_name' => self::GUARD,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $permissionId = $this->idOf('permissions', self::ABILITY);

        foreach (self::ROLES as $role) {
            DB::table('role_has_permissions')->insertOrIgnore([
                'role_id' => $this->idOf('roles', $role),
                'permission_id' => $permissionId,
            ]);
        }
    }

    public function down(): void
    {
        $this->limitLockWait();

        DB::table('role_has_permissions')
            ->whereIn('permission_id', DB::table('permissions')
                ->select('id')
                ->where('name', self::ABILITY)
                ->where('guard_name', self::GUARD))
            ->delete();

        DB::table('permissions')
            ->where('name', self::ABILITY)
            ->where('guard_name', self::GUARD)
            ->delete();
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
            throw new RuntimeException('Falta «'.$name.'» en '.$table.': el catalogo de RF-ID-02 no esta sembrado.');
        }

        return $row->id;
    }
};

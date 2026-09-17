<?php

declare(strict_types=1);

/*
 * Cierre de la pasada de carga: apaga todo lo que la prueba dejo VIVO.
 *
 * Lo ejecuta el `trap` de `run.sh`, tambien cuando la pasada se interrumpe con
 * Ctrl-C o falla a mitad — que es justo cuando mas importa, porque es el caso en
 * el que nadie se acuerda de limpiar.
 *
 *   php artisan tinker --execute="include '/tmp/k6/cleanup-after-load.php';"
 *
 * QUE SE APAGA Y QUE SE QUEDA.
 *
 * Se apaga todo lo que es una CREDENCIAL: las tarjetas emitidas para la pasada,
 * los tokens de los quioscos sinteticos y la sesion del responsable, ademas de
 * desactivar su cuenta. Son secretos vivos con los que se puede fichar y leer
 * datos de personas, y dejarlos encendidos en un entorno de pruebas al que
 * alguien restauro una copia es exactamente como se filtra un entorno.
 *
 * Se queda todo lo que es HISTORIA: los empleados sinteticos, sus fichajes y el
 * historico importado. Borrarlos obligaria a `DELETE` sobre `scan_events` y
 * `shift_entries`, que es la operacion que la regla dura 5 prohibe en el
 * producto; y ademas la pasada siguiente los reutiliza sin volver a crearlos.
 * Se limpian recreando el entorno de pruebas, no desde aqui.
 *
 * NO CAMBIA EL VEREDICTO. Un cierre que falla se avisa y se sigue: el resultado
 * de la prueba es lo que midio la prueba.
 */

use App\Modules\Identity\Application\Command\RevokeDeviceTokenCommand;
use App\Modules\Identity\Application\UseCase\RevokeDeviceToken;
use App\Modules\Identity\Infrastructure\Persistence\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/support.php';

k6_assert_test_database();

$say = static function (string $line): void {
    echo $line."\n";
};

$stamp = gmdate('Y-m-d H:i:sP');
$siteId = DB::table('sites')->orderBy('id')->value('id');

if ($siteId === null) {
    $say('[cierre] No hay centro: nada que apagar.');

    return;
}

$departmentId = k6_department_id((int) $siteId);

if ($departmentId === null) {
    $say('[cierre] No existe el departamento «'.K6_DEPARTMENT_NAME.'»: nada que apagar.');

    return;
}

// --- Tarjetas ----------------------------------------------------------------

$employeeIds = k6_load_employees($departmentId)->pluck('id');
$revokedCards = 0;

$employeeIds->chunk(2_000)->each(static function ($ids) use ($stamp, &$revokedCards): void {
    $revokedCards += DB::table('credentials')
        ->whereIn('employee_id', $ids->all())
        ->whereNull('revoked_at')
        ->update(['revoked_at' => $stamp, 'revoked_reason' => 'k6: cierre de la prueba de carga']);
});

$say('[cierre] Tarjetas revocadas: '.$revokedCards.'.');

// --- Quioscos ----------------------------------------------------------------

// Por el caso de uso del producto y no con un `DELETE`: revocar un token de
// dispositivo tiene efectos que esta herramienta no tiene por que conocer.
$deviceUuids = DB::table('devices')
    ->where('site_id', $siteId)
    ->where(static function ($query): void {
        $query->where('name', 'like', K6_DEVICE_PREFIX.'%')->orWhere('name', K6_HISTORY_DEVICE);
    })
    ->pluck('uuid');

$revokedDevices = 0;

foreach ($deviceUuids as $uuid) {
    try {
        // `deactivate: false`: lo que sobra es el SECRETO, no la fila. El
        // quiosco sintetico se reutiliza en la pasada siguiente y darlo de baja
        // aqui obligaria a volver a darlo de alta alli por un token caducado.
        $revoked = app(RevokeDeviceToken::class)->handle(new RevokeDeviceTokenCommand(
            deviceUuid: (string) $uuid,
            reason: 'k6: cierre de la prueba de carga',
            deactivate: false,
        ));

        $revokedDevices += $revoked ? 1 : 0;
    } catch (Throwable $exception) {
        $say('[cierre] [aviso] No se pudo revocar el token del quiosco '.$uuid.': '.$exception->getMessage());
    }
}

$say('[cierre] Quioscos con el token revocado: '.$revokedDevices.' de '.$deviceUuids->count().'.');

// --- Cuenta de gestion -------------------------------------------------------

$manager = User::query()->where('email', K6_MANAGER_EMAIL)->first();

if ($manager instanceof User) {
    $tokens = Sanctum::personalAccessTokenModel()::query()
        ->where('tokenable_type', $manager->getMorphClass())
        ->where('tokenable_id', $manager->getKey())
        ->delete();

    // Desactivada y no borrada: `departments.manager_user_id` le apunta y el
    // `audit_log` de la pasada la cita. Una cuenta inactiva no entra por
    // ninguna puerta (`EloquentUserAccounts` exige `is_active`).
    $manager->is_active = false;
    $manager->save();

    $say('[cierre] Responsable de la carga: '.$tokens.' sesiones borradas y cuenta desactivada.');
}

// --- Artefactos del contenedor -----------------------------------------------

foreach (glob(K6_WORK_DIR.'/*') ?: [] as $leftover) {
    if (is_file($leftover)) {
        unlink($leftover);
    }
}

$say('[cierre] '.K6_WORK_DIR.' vaciado. Los empleados sinteticos y su historico se conservan a proposito.');

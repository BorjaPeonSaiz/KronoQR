<?php

declare(strict_types=1);

/*
 * Aprovisionamiento de la prueba de carga del pico del cambio de turno
 * (RNF-P-02, RNF-P-06, RQ-08).
 *
 * Se ejecuta DENTRO del contenedor `app`, contra la base de un entorno de
 * pruebas, via tinker; lo invoca `load-tests/k6/run.sh`, que copia este fichero
 * con `docker compose cp` porque la pila de entrega NO tiene bind mount del
 * repositorio:
 *
 *   php artisan tinker --execute="include '/tmp/k6/provision-fixtures.php';"
 *
 * POR QUE HACE FALTA. ADR-034 acuña el secreto de la credencial al imprimir y
 * solo guarda su hash, asi que los payloads QR de las credenciales sembradas son
 * irrecuperables (y sus tokens de siembra ni siquiera tienen la forma de 22
 * caracteres que exige QrPayload). La unica via para tener trafico de fichaje
 * realista es emitir credenciales nuevas cuyo token en claro se conozca en el
 * momento de crearlas — exactamente lo que hace una impresion real.
 *
 * A QUIEN TOCA Y A QUIEN NO. Solo a los empleados que ha creado el propio
 * script: los del departamento «Carga k6» con codigo `K6…`. El criterio vive en
 * `support.php` y las dos condiciones se exigen a la vez, porque el prefijo por
 * si solo no distingue una fila sintetica de una persona real de una base
 * restaurada —los codigos de empleado son opacos y aleatorios por diseno—. Si
 * aparece algun `K6…` fuera de ese departamento, el script **se planta**: no
 * revoca, no reemite y no ficha por nadie.
 *
 * QUE DEJA, en `/tmp/k6/k6-fixtures.json` (permisos 0600: lleva tarjetas
 * firmadas y sesiones vivas):
 *
 *   - `payloads` / `employee_uuids`  una tarjeta viva por empleado de la carga,
 *                           alineadas indice a indice;
 *   - `device_tokens`       un token por quiosco sintetico;
 *   - `unknown_payloads`    bien formados y firmados por el servidor con un
 *                           secreto que NO esta en la base: `rejected_unknown`;
 *   - `revoked_payloads`    credenciales de empleados RESERVADOS, que no entran
 *                           en ninguna rebanada: `rejected_revoked`;
 *   - `management_token`    sesion de un `responsable_departamento` emitida por
 *                           el propio servidor con las abilities de su rol;
 *   - `geometry`            el reparto de tarjetas por instancia, que calcula
 *                           `run.sh` y del que leen k6 y la verificacion: un
 *                           reparto calculado dos veces se desincroniza una vez;
 *   - `rejection_floor_ms`, `debounce_seconds`  los umbrales REALES de esta
 *                           instalacion, para que el veredicto no los suponga;
 *   - `projection_divergence_before`  el contador acumulado antes de la carga.
 *
 * TODO LO FIRMA Y LO EMITE EL SERVIDOR con su propia configuracion, para que k6
 * no reimplemente ni el HMAC ni el reparto de ambitos: si la firma, el formato o
 * los permisos del rol cambian, esta herramienta se rompe aqui y no da falsos
 * rechazos ni falsos 403 en la medida.
 *
 * Escribe con el constructor de consultas, como `tests/Support/*`: es una
 * herramienta de entorno de pruebas, no un camino del producto.
 */

use App\Modules\Identity\Application\Command\IssueDeviceTokenCommand;
use App\Modules\Identity\Application\Port\AccessTokenIssuer;
use App\Modules\Identity\Application\Port\UserAccounts;
use App\Modules\Identity\Application\UseCase\IssueDeviceToken;
use App\Modules\Identity\Domain\ValueObject\AuthenticatedUser;
use App\Modules\Identity\Domain\ValueObject\CredentialSecret;
use App\Modules\Identity\Domain\ValueObject\QrSigningKey;
use App\Modules\Identity\Infrastructure\Persistence\User;
use App\Modules\Product\Application\UseCase\GetLicenseStatusHandler;
use App\Modules\Product\Domain\ValueObject\PlanLimit;
use App\Modules\Shared\Application\Port\OperationalSettingsProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/support.php';

k6_assert_test_database();

$say = static function (string $line): void {
    echo $line."\n";
};

// --- Configuracion de la pasada ---------------------------------------------

$intEnv = static function (string $name, int $fallback): int {
    $raw = getenv($name);

    return $raw === false || trim($raw) === '' ? $fallback : max(0, (int) $raw);
};

$employeeTarget = max(1, $intEnv('K6_EMPLOYEES', 5_560));
$deviceTarget = max(1, $intEnv('K6_DEVICES', 80));
$rejectTarget = max(1, $intEnv('K6_REJECT_PAYLOADS', 200));
$historyDays = $intEnv('K6_HISTORY_DAYS', 365);
$historyEmployees = $intEnv('K6_HISTORY_EMPLOYEES', 200);

// El reparto de tarjetas lo calcula `run.sh` y viaja hasta k6 por este fichero:
// aqui solo se transcribe. Es deliberado que no se recalcule.
$geometry = [
    'instances' => max(1, $intEnv('K6_INSTANCES', 10)),
    'scan_cards' => max(1, $intEnv('K6_SCAN_CARDS', 396)),
    'resend_cards' => max(1, $intEnv('K6_RESEND_CARDS', 60)),
    'batch_cards' => max(2, $intEnv('K6_BATCH_CARDS', 100)),
];
$geometry['cards_per_instance'] = $geometry['scan_cards'] + $geometry['resend_cards'] + $geometry['batch_cards'];

$outputPath = (static function (): string {
    $raw = getenv('K6_FIXTURES_PATH');

    return $raw === false || trim($raw) === '' ? K6_WORK_DIR.'/k6-fixtures.json' : trim($raw);
})();

$keyId = Config::string('identity.credentials.signing_keys.current.id', '');
$keySecret = Config::string('identity.credentials.signing_keys.current.secret', '');

if ($keyId === '' || $keySecret === '') {
    throw new RuntimeException(
        'QR_SIGNING_KEY_CURRENT no esta configurada: sin clave, el servidor no puede '
        .'verificar ningun escaneo. Genera 32 bytes en base64, ponla en .env y recrea el contenedor.'
    );
}

$key = QrSigningKey::fromBase64($keyId, $keySecret);
$stamp = gmdate('Y-m-d H:i:sP');

// --- La licencia avisa, nunca bloquea (ADR-019, regla dura 15) ---------------

$license = app(GetLicenseStatusHandler::class)->handle();

if ($license->license === null) {
    $say('[aviso] La instalacion no tiene licencia verificada ('.$license->state->value.'). '
        .'La prueba sigue: la licencia jamas bloquea el fichaje (ADR-019).');
} else {
    foreach ([[PlanLimit::Employees, $employeeTarget], [PlanLimit::Devices, $deviceTarget]] as [$limit, $needed]) {
        $contracted = $license->license->limits->contractedFor($limit);

        if ($contracted > 0 && $needed > $contracted) {
            $say('[aviso] La carga pide '.$needed.' '.$limit->value.' y el plan contrata '.$contracted.'. '
                .'Se aprovisiona igual: superar el plan no detiene nada (ADR-019).');
        }
    }
}

// --- Centro y departamento de la carga --------------------------------------

$siteId = DB::table('sites')->orderBy('id')->value('id');

if ($siteId === null) {
    throw new RuntimeException('La instalacion no tiene ningun centro: completa la puesta en marcha antes.');
}

$siteId = (int) $siteId;
$departmentId = k6_department_id($siteId);

// ANTES de crear nada: si hay codigos de la carga fuera de su departamento, se
// para. Puede que sean personas reales.
k6_assert_no_foreign_codes($departmentId);

if ($departmentId === null) {
    $departmentId = (int) DB::table('departments')->insertGetId([
        'site_id' => $siteId,
        'name' => K6_DEPARTMENT_NAME,
    ]);
}

// --- Empleados sinteticos ----------------------------------------------------

// Los de las tarjetas de la carga MAS los reservados para el escenario de
// rechazo, que no entran en ninguna rebanada: si un empleado con tarjeta
// revocada estuviera ademas en la rebanada de `scan`, el mismo empleado
// recibiria fichajes validos y rechazos a la vez y ni el anti-rebote ni el
// cuadre posterior significarian nada.
$totalNeeded = $employeeTarget + $rejectTarget;
$existing = k6_load_employees($departmentId);
$toCreate = max(0, $totalNeeded - $existing->count());

// El numero mas alto ya usado, y no el recuento: una pasada interrumpida deja
// huecos, y numerar sobre el recuento chocaria con el UNIQUE de `employee_code`.
$highest = (int) (DB::table('employees')
    ->where('department_id', $departmentId)
    ->where('employee_code', 'like', K6_EMPLOYEE_CODE_PREFIX.'%')
    ->selectRaw("coalesce(max(nullif(regexp_replace(employee_code::text, '^K6', ''), '')::int), 0) as highest")
    ->value('highest') ?? 0);

$say('Empleados de la carga: '.$existing->count().' ya existen, faltan '.$toCreate.' para llegar a '.$totalNeeded.'.');

$rows = [];
$created = 0;

$flushEmployees = static function (array &$buffer) use (&$created): void {
    if ($buffer !== []) {
        DB::table('employees')->insert($buffer);
        $created += count($buffer);
        $buffer = [];
    }
};

for ($i = 0; $i < $toCreate; $i++) {
    $number = str_pad((string) ($highest + $i + 1), 4, '0', STR_PAD_LEFT);

    $rows[] = [
        'uuid' => Str::uuid7()->toString(),
        'site_id' => $siteId,
        'department_id' => $departmentId,
        // Nombre sintetico y sin ninguna PII (regla dura 21). El apellido lleva
        // el numero para que el nombre completo sea «Carga k6 0001», que es lo
        // que `run.sh` busca despues en los logs del servidor.
        'first_name' => 'Carga',
        'last_name' => 'k6 '.$number,
        'employee_code' => K6_EMPLOYEE_CODE_PREFIX.$number,
        'email' => null,
        'status' => 'active',
        'hired_at' => '2020-01-01',
        'locale' => 'es',
        'created_at' => $stamp,
        'updated_at' => $stamp,
    ];

    if (count($rows) === 1_000) {
        $flushEmployees($rows);
    }
}

$flushEmployees($rows);

$everyone = k6_load_employees($departmentId, $totalNeeded);

if ($everyone->count() < $totalNeeded) {
    throw new RuntimeException(
        'Se esperaban '.$totalNeeded.' empleados de carga y hay '.$everyone->count().'.'
    );
}

$carriers = $everyone->take($employeeTarget)->values();
$revokedOwners = $everyone->slice($employeeTarget, $rejectTarget)->values();

$say('Empleados creados en esta pasada: '.$created.'. '
    .$carriers->count().' con tarjeta viva y '.$revokedOwners->count().' reservados para el rechazo.');

// --- Credenciales vivas cuyo secreto se conoce -------------------------------

// El indice parcial `one_active_credential_per_key_and_employee` no admite dos
// tarjetas vivas firmadas con la MISMA clave: las anteriores se revocan, que es
// ademas lo que haria una reimpresion real (revocar -> reemitir -> imprimir,
// ADR-034). Solo las de ESTOS empleados, que son los que creo el script.
$everyone->pluck('id')->chunk(2_000)->each(static function ($ids) use ($stamp): void {
    DB::table('credentials')
        ->whereIn('employee_id', $ids->all())
        ->whereNull('revoked_at')
        ->update(['revoked_at' => $stamp, 'revoked_reason' => 'k6: reemision para la prueba de carga']);
});

$payloads = [];
$credentialRows = [];

$flushCredentials = static function (array &$buffer): void {
    if ($buffer !== []) {
        DB::table('credentials')->insert($buffer);
        $buffer = [];
    }
};

$issueCard = static function (object $owner, bool $revoked) use ($key, $keyId, $stamp): array {
    $secret = CredentialSecret::fromBytes(random_bytes(CredentialSecret::ENTROPY_BYTES));

    $row = [
        'uuid' => Str::uuid7()->toString(),
        'employee_id' => $owner->id,
        'key_id' => $keyId,
        'secret_hash' => $secret->hash(),
        'issued_at' => $stamp,
        'printed_at' => $stamp,
    ];

    if ($revoked) {
        $row['revoked_at'] = $stamp;
        $row['revoked_reason'] = 'k6: tarjeta revocada para el escenario de rechazo (RS-03)';
    }

    return [$row, $key->sign($secret->value)->toString()];
};

foreach ($carriers as $owner) {
    [$row, $payload] = $issueCard($owner, false);

    $credentialRows[] = $row;
    $payloads[] = $payload;

    if (count($credentialRows) === 1_000) {
        $flushCredentials($credentialRows);
    }
}

$flushCredentials($credentialRows);

$say('Credenciales vivas emitidas: '.count($payloads).'.');

// --- Payloads de rechazo: desconocido y revocado -----------------------------

// DESCONOCIDO: bien formado y firmado por el servidor con un secreto que nunca
// se inserta. Es el unico modo de ejercitar `rejected_unknown` sin depender de
// que un secreto aleatorio no exista por casualidad.
$unknownPayloads = [];

for ($i = 0; $i < $rejectTarget; $i++) {
    $secret = CredentialSecret::fromBytes(random_bytes(CredentialSecret::ENTROPY_BYTES));
    $unknownPayloads[] = $key->sign($secret->value)->toString();
}

// REVOCADO: credenciales de verdad, emitidas y revocadas en el acto, colgadas de
// los empleados RESERVADOS.
$revokedPayloads = [];
$revokedRows = [];

foreach ($revokedOwners as $owner) {
    [$row, $payload] = $issueCard($owner, true);

    $revokedRows[] = $row;
    $revokedPayloads[] = $payload;

    if (count($revokedRows) === 1_000) {
        $flushCredentials($revokedRows);
    }
}

$flushCredentials($revokedRows);

$say('Payloads de rechazo: '.count($unknownPayloads).' desconocidos y '.count($revokedPayloads).' revocados.');

// --- Quioscos sinteticos -----------------------------------------------------

$deviceUuidOf = static function (int $siteId, string $name, string $stamp): string {
    $uuid = DB::table('devices')->where('site_id', $siteId)->where('name', $name)->value('uuid');

    if ($uuid !== null) {
        return (string) $uuid;
    }

    $uuid = Str::uuid7()->toString();

    DB::table('devices')->insert([
        'uuid' => $uuid,
        'site_id' => $siteId,
        'name' => $name,
        'status' => 'active',
        'pending_queue_size' => 0,
        'created_at' => $stamp,
        'updated_at' => $stamp,
    ]);

    return $uuid;
};

$deviceTokens = [];

for ($i = 0; $i < $deviceTarget; $i++) {
    $uuid = $deviceUuidOf($siteId, K6_DEVICE_PREFIX.$i, $stamp);
    $issued = app(IssueDeviceToken::class)->handle(new IssueDeviceTokenCommand($uuid));

    if ($issued === null) {
        throw new RuntimeException('No se pudo emitir el token del dispositivo '.$uuid);
    }

    $deviceTokens[] = $issued->plainTextToken;
}

$say('Quioscos con token vivo: '.count($deviceTokens).'.');

// --- Historico, para que el fichaje lea de una tabla con volumen -------------

// `lastAcceptedScanOf()` y la ventana anti-rebote resuelven las dos por
// `(employee_id, occurred_at DESC)` con un LIMIT 1. Sobre una tabla vacia ese
// plan es indistinguible de un recorrido completo, asi que la medida no diria
// nada del producto instalado. Estas filas son un historico IMPORTADO
// (`origin = import`, `shift_entry_id` nulo) y todas anteriores a ayer, para que
// no entren en el rango de la reconciliacion posterior.
$historyDeviceUuid = $deviceUuidOf($siteId, K6_HISTORY_DEVICE, $stamp);
$historyDeviceId = (int) DB::table('devices')->where('uuid', $historyDeviceUuid)->value('id');

$alreadyImported = (int) DB::table('scan_events')->where('device_id', $historyDeviceId)->count();
$historyOwners = $carriers->take($historyEmployees);
$historyRows = 0;

if ($alreadyImported > 0) {
    $say('Historico: ya hay '.$alreadyImported.' escaneos importados; no se duplica.');
} else {
    $buffer = [];

    $flushHistory = static function (array &$buffer) use (&$historyRows): void {
        if ($buffer !== []) {
            DB::table('scan_events')->insert($buffer);
            $historyRows += count($buffer);
            $buffer = [];
        }
    };

    foreach ($historyOwners as $owner) {
        for ($day = $historyDays; $day >= 2; $day--) {
            $date = gmdate('Y-m-d', time() - $day * 86_400);

            foreach ([['clock_in', '06:00:00', 0], ['clock_out', '14:00:00', 480]] as [$result, $time, $minutes]) {
                $at = $date.' '.$time.'+00:00';

                $buffer[] = [
                    'scan_id' => Str::uuid7()->toString(),
                    'device_id' => $historyDeviceId,
                    'employee_id' => $owner->id,
                    'occurred_at' => $at,
                    'recorded_at' => $at,
                    'origin' => 'import',
                    'intent' => 'auto',
                    'result' => $result,
                    'shift_entry_id' => null,
                    // El CHECK `scan_events_chk_worked_minutes` exige acumulado
                    // en todo desenlace que no sea uno de los tres rechazos.
                    'worked_minutes' => $minutes,
                    'payload_fingerprint' => null,
                    'client_meta' => json_encode(['k6' => 'history'], JSON_THROW_ON_ERROR),
                    'clock_skew_seconds' => null,
                    'flagged_for_review' => false,
                ];

                // 4.000 filas y no 5.000: el protocolo de PostgreSQL admite
                // 65.535 parametros por sentencia y estas filas tienen catorce
                // columnas. Con bloques de 5.000 la sentencia pide 70.000 y
                // falla con «number of parameters must be between 0 and 65535».
                if (count($buffer) === 4_000) {
                    $flushHistory($buffer);
                }
            }
        }
    }

    $flushHistory($buffer);

    $say('Historico importado: '.$historyRows.' escaneos para '.$historyOwners->count().' empleados.');

    // Sin estadisticas frescas el planificador cree que la tabla sigue vacia y
    // elige un recorrido completo: `verify-after-load.php` exigiria un plan que
    // no se ha podido calcular. No es obligatorio —autovacuum llega solo— pero
    // esperar a que llegue haria que el resultado dependiera del momento.
    try {
        DB::statement('ANALYZE scan_events');
        $say('Estadisticas de scan_events actualizadas.');
    } catch (Throwable $exception) {
        $say('[aviso] No se pudo ejecutar ANALYZE scan_events ('.$exception->getMessage().'). '
            .'El plan de las consultas calientes puede salir por recorrido completo hasta que pase autovacuum.');
    }
}

// --- Token del responsable del departamento de la carga ----------------------

// El token lo emite el SERVIDOR con las abilities del rol (`AccessTokenIssuer`,
// el mismo que usa `POST /api/v1/auth/login`): componer la lista a mano aqui
// daria un token que no se parece al que usa el panel, y la medida del endpoint
// de cumplimiento dejaria de decir nada sobre el producto.
$manager = User::query()->where('email', K6_MANAGER_EMAIL)->first();

if (! $manager instanceof User) {
    $manager = User::query()->create([
        'uuid' => Str::uuid7()->toString(),
        'name' => 'Responsable carga k6',
        'email' => K6_MANAGER_EMAIL,
        'password' => Str::random(40),
        'locale' => 'es',
        'is_active' => true,
    ]);
}

$manager->is_active = true;
$manager->save();

// `responsable_departamento` no lleva segundo factor obligatorio
// (`identity.two_factor.required_roles`), asi que su sesion vale desde el primer
// momento y el escenario de panel no necesita ningun TOTP.
$manager->syncRoles(['responsable_departamento']);

// RF-ID-03: el alcance sale de `departments.manager_user_id`, no del token.
DB::table('departments')->where('id', $departmentId)->update(['manager_user_id' => $manager->getKey()]);

$account = app(UserAccounts::class)->findByUuid($manager->uuid);

if (! $account instanceof AuthenticatedUser) {
    throw new RuntimeException('No se pudo leer la cuenta del responsable de la carga.');
}

$managementToken = app(AccessTokenIssuer::class)->issueFor($account, 'Carga k6')->plainTextToken;

$say('Responsable de la carga: alcance el departamento «'.K6_DEPARTMENT_NAME.'», '
    .count($account->abilities).' ambitos.');

// --- Los umbrales REALES de esta instalacion ---------------------------------

// El veredicto no puede suponerlos. El suelo de rechazo de RS-03 y la ventana de
// RF-AT-06 son configuracion (regla dura 13/14) y un cliente los cambia sin
// tocar el repositorio: si el agregado llevara 25 y 60 escritos a mano, en esa
// instalacion estaria juzgando otra cosa.
$rejectionFloorMs = Config::integer('security.rejection_floor_ms', 25);
$debounceSeconds = app(OperationalSettingsProvider::class)->forSite($siteId)->debounceSeconds;

$say('Umbrales de la instalacion: suelo de rechazo '.$rejectionFloorMs.' ms, '
    .'anti-rebote '.$debounceSeconds.' s.');

// --- El contador de divergencias ANTES de la carga ---------------------------

// `projection_divergence_total` es un contador ACUMULADO que sobrevive a los
// reinicios a proposito (una divergencia borrada es una divergencia que nadie
// investigo), asi que exigirle un cero absoluto despues de la carga solo
// funcionaria en una instalacion que jamas haya tenido ninguna. Lo que si se
// puede exigir —y es lo que dice RN-06 sobre ESTA pasada— es que no suba.
$divergenceBefore = k6_textfile_metric(k6_projection_prom_file(), 'projection_divergence_total');

$say('Divergencias acumuladas antes de la carga: '
    .($divergenceBefore === null ? 'sin fichero .prom todavia' : (string) $divergenceBefore).'.');

// --- El fichero de fixtures --------------------------------------------------

if (! is_dir(dirname($outputPath))) {
    mkdir(dirname($outputPath), 0o755, true);
}

// 0600 antes de escribir nada: el fichero lleva tarjetas firmadas y sesiones
// vivas, y entre `file_put_contents` y un `chmod` posterior hay una ventana en
// la que cualquiera del contenedor puede leerlo.
touch($outputPath);
chmod($outputPath, 0o600);

file_put_contents($outputPath, json_encode([
    'generated_at' => $stamp,
    'site_id' => $siteId,
    'department_id' => $departmentId,
    'geometry' => $geometry,
    'payloads' => $payloads,
    // Identificadores publicos y alineados con `payloads`, nunca nombres
    // (regla dura 21). Es lo que permite a la verificacion saber a quien
    // corresponde cada rebanada sin repetir la aritmetica del guion.
    'employee_uuids' => $carriers->pluck('uuid')->all(),
    'device_tokens' => $deviceTokens,
    'unknown_payloads' => $unknownPayloads,
    'revoked_payloads' => $revokedPayloads,
    'management_token' => $managementToken,
    'rejection_floor_ms' => $rejectionFloorMs,
    'debounce_seconds' => $debounceSeconds,
    'projection_divergence_before' => $divergenceBefore,
    'history' => [
        'device_uuid' => $historyDeviceUuid,
        'employees' => $historyOwners->count(),
        'days' => $historyDays,
        'rows' => $historyRows > 0 ? $historyRows : $alreadyImported,
    ],
], JSON_THROW_ON_ERROR));

$say('Fixtures en '.$outputPath.': '.count($payloads).' tarjetas, '.count($deviceTokens).' quioscos, '
    .'rebanada de '.$geometry['cards_per_instance'].' tarjetas por instancia.');

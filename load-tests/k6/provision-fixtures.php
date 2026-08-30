<?php

declare(strict_types=1);

/*
 * Aprovisionamiento de la prueba de carga de fichaje (RNF-P-02, RNF-P-06).
 *
 * Se ejecuta DENTRO del contenedor `app`, contra la base de DESARROLLO, via
 * tinker (lo invoca `load-tests/k6/run.sh`; el repositorio esta montado de solo
 * lectura en /var/www/repo):
 *
 *   php artisan tinker --execute="include '/var/www/repo/load-tests/k6/provision-fixtures.php';"
 *
 * Por que hace falta: ADR-034 acuña el secreto de la credencial al imprimir y
 * solo guarda su hash, asi que los payloads QR de las credenciales sembradas
 * son irrecuperables (y sus tokens de siembra ni siquiera tienen la forma de 22
 * caracteres que exige QrPayload). La unica via para tener trafico de fichaje
 * realista es emitir credenciales nuevas cuyo token en claro se conozca en el
 * momento de crearlas — exactamente lo que hace una impresion real.
 *
 * Lo que deja: `backend/storage/framework/k6-fixtures.json` (montaje RW del
 * backend, ignorado por git) con los payloads firmados y los tokens de
 * dispositivo. Los firma el SERVIDOR con su propia clave de configuracion, para
 * que k6 no reimplemente el HMAC: si la firma o el formato cambian, esta
 * herramienta se rompe aqui y no da falsos rechazos en la medida.
 *
 * Escribe con el constructor de consultas, como tests/Support/Identity: es una
 * herramienta de entorno de desarrollo, no un camino del producto.
 */

use App\Modules\Identity\Application\Command\IssueDeviceTokenCommand;
use App\Modules\Identity\Application\UseCase\IssueDeviceToken;
use App\Modules\Identity\Domain\ValueObject\CredentialSecret;
use App\Modules\Identity\Domain\ValueObject\QrSigningKey;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

if (app()->isProduction()) {
    throw new RuntimeException('Este script crea credenciales de prueba: jamas contra produccion.');
}

$keyId = Config::string('identity.credentials.signing_keys.current.id', '');
$keySecret = Config::string('identity.credentials.signing_keys.current.secret', '');

if ($keyId === '' || $keySecret === '') {
    throw new RuntimeException(
        'QR_SIGNING_KEY_CURRENT no esta configurada: sin clave, el servidor no puede '
        .'verificar ningun escaneo. Genera 32 bytes en base64, ponla en .env y recrea el contenedor.'
    );
}

$key = QrSigningKey::fromBase64($keyId, $keySecret);

$employeeTarget = max(1, (int) (getenv('K6_EMPLOYEES') ?: 600));
$deviceTarget = max(1, (int) (getenv('K6_DEVICES') ?: 30));
$stamp = gmdate('Y-m-d H:i:sP');

$employees = DB::table('employees')
    ->where('status', 'active')
    ->orderBy('id')
    ->limit($employeeTarget)
    ->get(['id', 'uuid']);

if ($employees->isEmpty()) {
    throw new RuntimeException('No hay empleados activos: ejecuta make seed antes de la prueba de carga.');
}

// El indice parcial one_active_credential_per_key_and_employee no admite dos
// tarjetas vivas firmadas con la MISMA clave, y estas lo estarian:
// las credenciales previas de estos empleados se revocan, que es ademas lo que
// haria una reimpresion real (revocar -> reemitir -> imprimir, ADR-034).
DB::table('credentials')
    ->whereIn('employee_id', $employees->pluck('id'))
    ->whereNull('revoked_at')
    ->update(['revoked_at' => $stamp, 'revoked_reason' => 'k6: reemision para la prueba de carga']);

$payloads = [];

foreach ($employees as $employee) {
    $secret = CredentialSecret::fromBytes(random_bytes(CredentialSecret::ENTROPY_BYTES));

    DB::table('credentials')->insert([
        'uuid' => Str::uuid7()->toString(),
        'employee_id' => $employee->id,
        'key_id' => $keyId,
        'secret_hash' => $secret->hash(),
        'issued_at' => $stamp,
        'printed_at' => $stamp,
    ]);

    $payloads[] = $key->sign($secret->value)->toString();
}

$siteId = DB::table('sites')->orderBy('id')->value('id');
$deviceTokens = [];

for ($i = 0; $i < $deviceTarget; $i++) {
    // Idempotente: (site_id, name) es unico y este script se ejecuta antes de
    // cada pasada. Si el dispositivo de una pasada anterior existe, se reusa y
    // solo se emite un token nuevo.
    $uuid = DB::table('devices')
        ->where('site_id', $siteId)
        ->where('name', 'k6-load-'.$i)
        ->value('uuid');

    if ($uuid === null) {
        $uuid = Str::uuid7()->toString();

        DB::table('devices')->insert([
            'uuid' => $uuid,
            'site_id' => $siteId,
            'name' => 'k6-load-'.$i,
            'status' => 'active',
            'pending_queue_size' => 0,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ]);
    }

    $issued = app(IssueDeviceToken::class)->handle(new IssueDeviceTokenCommand($uuid));

    if ($issued === null) {
        throw new RuntimeException('No se pudo emitir el token del dispositivo '.$uuid);
    }

    $deviceTokens[] = $issued->plainTextToken;
}

$out = storage_path('framework/k6-fixtures.json');

file_put_contents($out, json_encode([
    'generated_at' => $stamp,
    'payloads' => $payloads,
    'device_tokens' => $deviceTokens,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

echo 'Fixtures: '.count($payloads).' credenciales y '.count($deviceTokens)." dispositivos en {$out}\n";

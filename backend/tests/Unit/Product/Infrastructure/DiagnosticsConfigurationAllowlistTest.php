<?php

declare(strict_types=1);

use App\Modules\Product\Infrastructure\Diagnostics\DiagnosticsConfigurationAllowlist;

/*
 * El filtro de secretos del paquete de diagnostico (RF-PD-09, RS-08, ADR-020,
 * paso 5 de la ficha 5.9).
 *
 * ## Contra el `.env.example` ENTERO, y no contra una lista escrita a mano
 *
 * Es la unica forma de que esta prueba siga sirviendo dentro de dos años. Una
 * lista de claves prohibidas escrita aqui se quedaria atras el dia que alguien
 * añada `SOMETHING_API_TOKEN` al fichero de plantilla, y ese dia el paquete
 * empezaria a llevarlo sin que nada fallara.
 *
 * Lo que se afirma es lo contrario y es mucho mas fuerte: **de todas las claves
 * que existen, solo pasan las que la lista nombra**. Una clave nueva no entra
 * hasta que alguien la añada a la lista mirandola, y entonces esta prueba lo
 * enseñara en el diff.
 *
 * La ficha nombra ademas algunas por su nombre —`LICENSE_KEY`,
 * `QR_SIGNING_KEY_*`, `BACKUP_ENCRYPTION_KEY`, las credenciales de base de
 * datos—; van en su propio caso, porque el dia que alguien las moviera de sitio
 * en el `.env.example` la prueba de arriba seguiria en verde y esta no.
 */

/**
 * Todas las claves del `.env.example`, que es la lista completa de lo que puede
 * existir en el entorno de una instalacion.
 *
 * @return list<string>
 */
function clavesDelEnvExample(): array
{
    // SIN `base_path()`: esta suite es unitaria y no arranca la aplicacion (doc
    // 02 §9.5, presupuesto de duracion de `make test-unit`). Las dos rutas
    // cubren los dos arboles reales: el de la CI, donde `backend/` cuelga de la
    // raiz del repositorio, y el contenedor de desarrollo, que monta la raiz de
    // solo lectura en `/var/www/repo`.
    $candidates = [\dirname(__DIR__, 5).'/.env.example', '/var/www/repo/.env.example'];

    $contents = false;

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            $contents = file_get_contents($candidate);

            break;
        }
    }

    expect($contents)->toBeString('No se encuentra el .env.example en '.implode(' ni en ', $candidates)
        .': esta prueba no puede afirmar nada sin el.');

    preg_match_all('/^([A-Z][A-Z0-9_]*)=/m', (string) $contents, $matches);

    /** @var list<string> $keys */
    $keys = array_values(array_unique($matches[1]));

    return $keys;
}

it('lee el .env.example y encuentra una cantidad razonable de claves', function (): void {
    // La red de seguridad de las dos pruebas siguientes: si el fichero dejara de
    // encontrarse o cambiara de forma, `apply([])` no devolveria nada y las dos
    // pasarian sin comprobar nada.
    expect(clavesDelEnvExample())->toHaveCount(count(clavesDelEnvExample()))
        ->and(\count(clavesDelEnvExample()))->toBeGreaterThan(100);
})->group('RF-PD-09', 'RS-08');

it('no deja salir ninguna clave del .env.example que no este en la lista de permitidos', function (): void {
    $environment = [];

    foreach (clavesDelEnvExample() as $key) {
        // Un valor reconocible y unico por clave: si alguno apareciera en la
        // salida, se sabe exactamente cual se ha colado.
        $environment[$key] = 'VALOR-SECRETO-DE-'.$key;
    }

    $filtered = DiagnosticsConfigurationAllowlist::apply($environment);
    $serialized = json_encode($filtered, JSON_THROW_ON_ERROR);

    $leaked = [];

    foreach (clavesDelEnvExample() as $key) {
        if (DiagnosticsConfigurationAllowlist::allows($key)) {
            continue;
        }

        if (array_key_exists($key, $filtered) || str_contains((string) $serialized, 'VALOR-SECRETO-DE-'.$key)) {
            $leaked[] = $key;
        }
    }

    expect($leaked)->toBe([], 'Estas claves del .env.example salen en el paquete de diagnostico sin estar en la '
        .'lista de permitidos: '.implode(', ', $leaked));
})->group('RF-PD-09', 'RS-08');

it('deja fuera, una a una, las claves que la ficha nombra como secretas', function (string $key): void {
    // Ni siquiera «redactadas»: no aparecen. Con `LICENSE_KEY: "***"` el paquete
    // confirmaria que hay una licencia instalada; con la clave ausente, no dice
    // nada.
    expect(DiagnosticsConfigurationAllowlist::allows($key))->toBeFalse()
        ->and(DiagnosticsConfigurationAllowlist::apply([$key => 'secreto']))->toBe([]);
})->with([
    'LICENSE_KEY',
    'QR_SIGNING_KEY_CURRENT',
    'QR_SIGNING_KEY_PREVIOUS',
    'BACKUP_ENCRYPTION_KEY',
    'BACKUP_DB_PASSWORD',
    'BACKUP_DB_USERNAME',
    'REVERB_APP_SECRET',
    'REVERB_APP_KEY',
    'REVERB_APP_ID',
    'DB_PASSWORD',
    'DB_USERNAME',
    'DB_HOST',
    'DB_MIGRATION_PASSWORD',
    'DB_MAINTENANCE_USERNAME',
    'REDIS_PASSWORD',
    'REDIS_HOST',
    'MAIL_HOST',
    'MAIL_USERNAME',
    'MAIL_PASSWORD',
    'MAIL_FROM_ADDRESS',
    'MAIL_FROM_NAME',
    'APP_KEY',
    // La URL lleva el nombre del hotel y su topologia de red interna.
    'APP_URL',
    'APP_NAME',
    'IDENTITY_PIN_SEALING_SECRET_KEY',
    'GRAFANA_ADMIN_USER',
    'GRAFANA_ADMIN_PASSWORD',
    'KIOSK_VLAN_CIDR',
    'PORTAL_INTERNAL_CIDR',
    'METRICS_ALLOW_CIDR',
    'LOKI_URL',
    'OTEL_EXPORTER_OTLP_ENDPOINT',
    'BACKUP_PATH',
    'TLS_CERT_FILE',
    'TLS_KEY_FILE',
    'LICENSE_PUBLIC_KEY',
])->group('RF-PD-09', 'RS-08');

it('ninguna clave PERMITIDA tiene forma de secreto', function (): void {
    // GUARDA CONTRA EL FUTURO, y contra el error mas facil de cometer aqui:
    // ampliar la lista con un prefijo generoso —`IDENTITY_`, `BACKUP_`— para que
    // entre un umbral nuevo, y colar de paso la clave de sellado del PIN o la de
    // cifrado de las copias.
    //
    // No sustituye a la prueba de arriba, que es la que manda: esa afirma que de
    // TODAS las claves del `.env.example` solo pasan las nombradas. Esta afirma
    // lo contrario desde el otro lado: que ninguna de las que pasan **parece** un
    // secreto. Si alguna vez una clave legitima tuviera esta forma, hay que
    // añadirla aqui a mano y justificarlo, que es exactamente la friccion que se
    // busca.
    $shapeOfASecret = '/(KEY|SECRET|PASSWORD|TOKEN|CREDENTIAL|DSN|CIDR|_URL|ENDPOINT)$|_(KEY|SECRET|PASSWORD|TOKEN)_/';

    // Las cuatro excepciones justificadas, una a una. Son MAGNITUDES cuyo nombre
    // menciona el material al que se refieren: un plazo, un plazo, una longitud
    // minima y otra longitud. Ninguna lleva un valor secreto —son enteros que el
    // `.env.example` publica con su valor por defecto— y las cuatro hacen falta
    // para diagnosticar «me echa del panel cada dos horas» o «no me deja poner
    // mi contraseña».
    //
    // La lista es explicita a proposito: una clave `IDENTITY_..._TOKEN_...`
    // NUEVA volveria a fallar aqui, que es lo que se quiere.
    $justified = [
        // Cuantas horas dura la sesion de gestion, no la sesion.
        'IDENTITY_SESSION_TOKEN_HOURS',
        // Longitud minima exigida a la contraseña, no la contraseña.
        'IDENTITY_PASSWORD_MIN_LENGTH',
        // Cuantos caracteres tiene el secreto TOTP que se genera, no el secreto.
        'IDENTITY_2FA_SECRET_LENGTH',
        // Cuantos dias vive el token de un quiosco, no el token.
        'IDENTITY_DEVICE_TOKEN_DAYS',
    ];

    $suspicious = array_values(array_filter(
        clavesDelEnvExample(),
        static fn (string $key): bool => DiagnosticsConfigurationAllowlist::allows($key)
            && preg_match($shapeOfASecret, $key) === 1
            && ! in_array($key, $justified, true),
    ));

    expect($suspicious)->toBe([], 'Estas claves estan en la lista de permitidos del paquete de diagnostico y '
        .'tienen forma de secreto: '.implode(', ', $suspicious).'. O son un secreto y hay que sacarlas de la '
        .'lista, o no lo son y hay que decir aqui por que.');
})->group('RF-PD-09', 'RS-08');
it('las excepciones de forma siguen siendo magnitudes y no secretos', function (string $key): void {
    // Si alguien quitara una de estas de la lista de permitidos, la excepcion de
    // arriba se quedaria protegiendo una clave que ya no existe y volveria a
    // pasar sin comprobar nada.
    expect(DiagnosticsConfigurationAllowlist::allows($key))->toBeTrue();

    // Y su valor es un numero: es lo que las hace inocuas. Un secreto no cabe en
    // un entero.
    expect(DiagnosticsConfigurationAllowlist::apply([$key => '42']))->toBe([$key => '42']);
})->with([
    'IDENTITY_SESSION_TOKEN_HOURS',
    'IDENTITY_PASSWORD_MIN_LENGTH',
    'IDENTITY_2FA_SECRET_LENGTH',
    'IDENTITY_DEVICE_TOKEN_DAYS',
])->group('RF-PD-09', 'RS-08');
it('si deja salir los umbrales operativos, que es para lo que existe la seccion', function (string $key): void {
    // La otra mitad: una lista de permitidos vacia pasaria la prueba de arriba y
    // dejaria el paquete sin nada con lo que diagnosticar.
    expect(DiagnosticsConfigurationAllowlist::allows($key))->toBeTrue();
})->with([
    'APP_ENV',
    'APP_DEBUG',
    'APP_TIMEZONE',
    'APP_LOCALE',
    'APP_SUPPORTED_LOCALES',
    'ATTENDANCE_MAX_SHIFT_HOURS',
    'ATTENDANCE_DEBOUNCE_SECONDS',
    'COMPLIANCE_PROFILE',
    'KIOSK_BATCH_MAX_SIZE',
    'KIOSK_SCAN_RATE_PER_DEVICE',
    'KIOSK_RATE_PER_IP',
    'PRODUCT_DIAGNOSTICS_MAX_BYTES',
    'IDENTITY_LOGIN_MAX_ATTEMPTS',
    'IDENTITY_SESSION_TOKEN_HOURS',
    'BACKUP_RETENTION_DAYS',
    'BACKUP_DAILY_AT',
    'BACKUP_WEEKLY_ON',
    'QUEUE_CONNECTION',
    'LOG_LEVEL',
    'MAIL_MAILER',
    'MAIL_PORT',
    'TELEMETRY_ENABLED',
    'TLS_ALLOW_SELF_SIGNED',
    'BRANDING_LOGO_ROOT',
])->group('RF-PD-09');

it('ordena la salida para que dos paquetes se puedan comparar con diff', function (): void {
    $filtered = DiagnosticsConfigurationAllowlist::apply([
        'QUEUE_CONNECTION' => 'redis',
        'APP_ENV' => 'production',
        'LOG_LEVEL' => 'info',
    ]);

    expect(array_keys($filtered))->toBe(['APP_ENV', 'LOG_LEVEL', 'QUEUE_CONNECTION']);
})->group('RF-PD-09');

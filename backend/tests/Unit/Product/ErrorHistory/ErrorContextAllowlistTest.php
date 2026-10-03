<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\ErrorContextAllowlist;

/*
 * `context` es una lista de PERMITIDOS, no de exclusiones (RF-PD-15, RL-19,
 * regla dura 21, decision 5 de la ficha 5.12).
 *
 * Quien rellena el contexto es un cliente. El dia que alguien anada
 * `context: { employee_name: … }` en el panel «para depurar», con exclusiones
 * ese nombre empezaria a viajar hacia el fabricante dentro del paquete de
 * diagnostico y nadie se enteraria.
 */

it('descarta cualquier clave que no este declarada', function (): void {
    $limpio = ErrorContextAllowlist::apply([
        'route' => '/api/v1/scan',
        'employee_name' => 'Ana Ruiz',
        'email' => 'ana.ruiz@hotel.es',
        'national_id' => '12345678Z',
        'token' => 'secreto',
    ]);

    expect($limpio)->toBe(['route' => '/api/v1/scan']);
})->group('RF-PD-15', 'RL-19');

it('descarta las estructuras anidadas', function (): void {
    // Un valor que es a su vez un mapa no entra: la lista no puede afirmar nada
    // sobre sus claves, y permitir uno colaria el objeto entero que llevara
    // dentro. Es exactamente como se filtra un `client_meta`.
    expect(ErrorContextAllowlist::apply([
        'reason' => ['nombre' => 'Ana Ruiz'],
        'cause' => ['a', 'b'],
        'http_status' => 500,
    ]))->toBe(['http_status' => 500]);
})->group('RF-PD-15', 'RL-19');

it('sanea tambien los valores permitidos', function (): void {
    // `reason` es texto libre escrito por quien programo el cliente: puede
    // llevar cualquier cosa dentro, exactamente igual que el mensaje.
    $limpio = ErrorContextAllowlist::apply(['reason' => 'fallo al avisar a ana.ruiz@hotel.es']);

    expect($limpio['reason'] ?? '')->toContain('[email]')
        ->and($limpio['reason'] ?? '')->not->toContain('ana.ruiz@hotel.es');
})->group('RF-PD-15', 'RL-19');

it('no guarda un codigo de empleado que llegue por el contexto', function (string $valor, string $prohibido): void {
    /*
     * PR12: por `POST /client-errors` y por el latido del quiosco entra lo que
     * el cliente quiera en `reason` o `cause`, y de ahi sale hacia el
     * fabricante dentro del paquete de diagnostico. El codigo de empleado es un
     * identificador directo y la mitad de la credencial del portal.
     */
    $limpio = ErrorContextAllowlist::apply(['reason' => $valor]);

    expect((string) ($limpio['reason'] ?? ''))->not->toContain($prohibido);
})->with([
    'codigo canonico dentro de un texto' => ['tarjeta E7K2M9QX4B no reconocida', 'E7K2M9QX4B'],
    'codigo con su etiqueta' => ['employee_code=739104', '739104'],
    // Un codigo heredado, importado de otro sistema, sin la forma canonica: en
    // un texto seria indistinguible de cualquier numero, pero un valor de
    // contexto que es ENTERO una palabra asi solo puede ser un identificador.
    'codigo heredado como valor suelto' => ['AB12C3', 'AB12C3'],
    'codigo heredado largo como valor suelto' => ['HTL2019X0042', 'HTL2019X0042'],
    'dni con puntos' => ['documento 12.345.678-Z', '345.678'],
    'iban' => ['ES91 2100 0418 4502 0005 1332', '2100 0418'],
])->group('RF-PD-15', 'RL-19');

it('no confunde con un codigo los valores tecnicos que mandan los clientes', function (string $valor): void {
    // Los valores reales de `scope`, `outcome`, `cause`, `method` o
    // `error_type`: palabras, en minusculas o sin cifras. Salen tal cual.
    expect(ErrorContextAllowlist::apply(['reason' => $valor]))->toBe(['reason' => $valor]);
})->with([
    'palabra' => ['timeout'],
    'metodo' => ['POST'],
    'tipo de error' => ['TypeError'],
    'estado como texto' => ['500'],
    'desenlace' => ['adopted'],
    'con guion bajo' => ['NETWORK_ERROR'],
])->group('RF-PD-15');

it('no admite las tres identidades por el contexto: tienen columna propia', function (): void {
    // Si estuvieran en la lista habria dos sitios donde puede vivir un
    // `employee_uuid`, y solo uno de los dos tiene el tipo `uuid` detras.
    expect(ErrorContextAllowlist::allows('trace_id'))->toBeFalse()
        ->and(ErrorContextAllowlist::allows('employee_uuid'))->toBeFalse()
        ->and(ErrorContextAllowlist::allows('device_id'))->toBeFalse();
})->group('RF-PD-15');

it('conserva los escalares tal cual y respeta el orden declarado', function (): void {
    $limpio = ErrorContextAllowlist::apply([
        'reason' => 'timeout',
        'http_status' => 500,
        'method' => 'POST',
        'attempts' => 3,
        'durable' => false,
    ]);

    expect(array_keys($limpio))->toBe(['method', 'attempts', 'reason', 'http_status', 'durable'])
        ->and($limpio['http_status'])->toBe(500)
        ->and($limpio['attempts'])->toBe(3)
        ->and($limpio['durable'])->toBeFalse();
})->group('RF-PD-15');

it('devuelve un mapa vacio cuando no hay nada permitido', function (): void {
    // Y no dieciseis claves con `null`: el contrato declara `context` con
    // valores escalares y sin nulos.
    expect(ErrorContextAllowlist::apply(['lo_que_sea' => 'x']))->toBe([]);
})->group('RF-PD-15');

it('declara exactamente las claves que el servidor y los clientes emiten', function (): void {
    /*
     * La lista literal, valor a valor: anadir o quitar una clave tiene que ser
     * un cambio visible que alguien revise, no un efecto colateral.
     *
     * Que estas sean **las de verdad** —y no las cuatro inventadas de la primera
     * version, que dejaban el contexto vacio en todos los errores de cliente— lo
     * comprueba `ClientErrorContextKeysTest` contra los ficheros de los propios
     * clientes. Aqui solo se fija la lista.
     */
    expect(ErrorContextAllowlist::keys())->toBe([
        // Servidor (`Product\Infrastructure\Capture`).
        'route', 'method',
        'job', 'queue', 'attempts',
        'command',
        // Comunes a los tres clientes.
        'cause', 'reason', 'error_type', 'http_status',
        // Panel y portal (`packages/web-kit/src/clientErrors.ts`).
        'component', 'hook', 'source', 'line',
        // Quiosco (`frontend-kiosk/src`).
        'scope', 'audio_state', 'silence_ms', 'skew_seconds', 'durable', 'kind', 'problem_type', 'outcome',
        'entries', 'items', 'missing', 'purged',
    ]);
})->group('RF-PD-15');

it('no guarda `message` en el contexto: asciende a la columna', function (): void {
    /*
     * Los tres reporters mandan el texto del error con esa clave. El servidor lo
     * eleva a `error_events.message` —es lo que hace que la fila del panel diga
     * algo, y lo que hace que dos errores distintos tengan huellas distintas— y
     * lo retira del contexto: guardarlo dos veces lo dejaria con dos longitudes
     * maximas distintas (1000 y 200) y duplicado en el paquete de diagnostico.
     */
    expect(ErrorContextAllowlist::allows(ErrorContextAllowlist::MESSAGE_KEY))->toBeFalse()
        ->and(ErrorContextAllowlist::apply(['message' => 'TypeError: x', 'component' => 'Vista']))
        ->toBe(['component' => 'Vista'])
        // Y la extraccion, que es lo que usan las dos puertas de entrada.
        ->and(ErrorContextAllowlist::messageIn(['message' => 'TypeError: x']))->toBe('TypeError: x')
        ->and(ErrorContextAllowlist::messageIn(['message' => '   ']))->toBeNull()
        ->and(ErrorContextAllowlist::messageIn(['component' => 'Vista']))->toBeNull();
})->group('RF-PD-15');

it('descarta las claves enteras del contexto', function (): void {
    // Un cliente que mande una lista en lugar de un mapa no cuela sus valores:
    // ninguna clave declarada es numerica.
    expect(ErrorContextAllowlist::apply([0 => 'Ana Ruiz', 1 => 'ana.ruiz@hotel.es', 'route' => '/api/v1/scan']))
        ->toBe(['route' => '/api/v1/scan']);
})->group('RF-PD-15', 'RL-19');

it('no guarda una clave cuyo valor se queda en nada tras el saneado', function (): void {
    // Solo espacios, o bytes que no se pueden sanear (falla cerrado): el
    // saneador devuelve su texto de relleno y la clave no aporta nada. Un valor
    // que conserva algo despues del saneado si entra.
    expect(ErrorContextAllowlist::apply([
        'reason' => '   ',
        'cause' => "fallo \xC3\x28 de ana.ruiz@hotel.es",
        'scope' => 'kiosk',
    ]))->toBe(['scope' => 'kiosk']);
})->group('RF-PD-15', 'RL-19');

/*
 * ---------------------------------------------------------------------------
 * ADR-048 (bloque 19): los valores de texto de cada clave admitida pasan por la
 * lista blanca por palabra, y `source` se reduce a `pathname:linea`.
 * ---------------------------------------------------------------------------
 */

it('no guarda un nombre bajo ninguna clave de texto admitida', function (string $clave): void {
    $limpio = ErrorContextAllowlist::apply([$clave => 'fallo de Rosa Ficticiana', 'scope' => 'vue']);
    $volcado = (string) json_encode($limpio, JSON_UNESCAPED_UNICODE);

    expect($volcado)->not->toContain('Rosa')
        ->and($volcado)->not->toContain('Ficticiana')
        ->and($limpio['scope'])->toBe('vue');
})->with([
    'route', 'method', 'job', 'queue', 'command', 'cause', 'reason', 'error_type', 'component', 'hook', 'source',
    'scope', 'kind', 'problem_type', 'outcome', 'audio_state',
])->group('RF-PD-15', 'RL-19');

it('no guarda un nombre en una clave numerica que llega como texto', function (): void {
    // `line` y `http_status` se esperan enteros; si llegan como texto, son
    // texto y pasan por la misma lista blanca.
    expect(ErrorContextAllowlist::apply(['line' => 'Rosa Ficticiana', 'http_status' => 'Ficticiana 500']))
        ->toBe(['http_status' => '… 500', 'line' => '…']);
})->group('RF-PD-15', 'RL-19');

it('reduce source a pathname:linea quitando origen, consulta y fragmento', function (string $source, string $esperado): void {
    expect(ErrorContextAllowlist::apply(['source' => $source]))->toBe(['source' => $esperado]);
})->with([
    'url completa del quiosco' => ['https://kiosk.hotel-ejemplo.es/assets/index.js', '/assets/index.js'],
    'con puerto' => ['http://192.168.1.10:5173/src/main.ts', '/src/main.ts'],
    'con consulta' => ['https://kiosk.hotel-ejemplo.es/assets/index.js?t=x7k2m9&u=ficticiana', '/assets/index.js'],
    'con fragmento' => ['https://kiosk.hotel-ejemplo.es/assets/index.js#Ficticiana', '/assets/index.js'],
    'con linea' => ['https://kiosk.hotel-ejemplo.es/assets/index.js?v=3:42', '/assets/index.js:42'],
    'con linea y columna' => ['https://kiosk.hotel-ejemplo.es/assets/index.js:42:7', '/assets/index.js:42:7'],
    'blob' => ['blob:https://kiosk.hotel-ejemplo.es/0199a1f0-0000-7000-8000-000000000000', '/0199a1f0-0000-7000-8000-000000000000'],
    'ya normalizado por web-kit' => ['/assets/index.js:42', '/assets/index.js:42'],
    'hash de vite' => ['/assets/index-Bx3k9Lq.js:42', '/assets/index-[n].js:42'],
])->group('RF-PD-15', 'RL-19');

it('conserva entero todo valor que emiten hoy los clientes', function (string $clave, string $valor): void {
    // La muestra de §1.4 del diseño. Que el vocabulario cubra TODOS los valores
    // de los clientes lo comprueba `ErrorVocabularyCoverageTest` sobre los
    // ficheros de los propios clientes.
    expect(ErrorContextAllowlist::apply([$clave => $valor]))->toBe([$clave => $valor]);
})->with([
    ['scope', 'promise'],
    ['cause', 'partial_acknowledgement'],
    ['cause', 'client_errors_rejected'],
    ['reason', 'no_media_devices'],
    ['reason', 'warmup_failed'],
    ['error_type', 'NotAllowedError'],
    ['error_type', 'QuotaExceededError'],
    ['audio_state', 'interrupted'],
    ['kind', 'pin'],
    ['outcome', 'already_current'],
    ['problem_type', 'urn:kronoqr:problem:scan-held-back'],
    ['component', '(anonimo)'],
    ['hook', 'https://vuejs.org/error-reference/#runtime-1'],
    ['hook', 'watcher callback'],
    ['route', '/api/v1/kiosk/heartbeat'],
    ['command', 'compliance:apply-retention'],
])->group('RF-PD-15');

it('es idempotente', function (): void {
    $una = ErrorContextAllowlist::apply([
        'reason' => 'fallo de Rosa Ficticiana con 45678912K',
        'source' => 'https://kiosk.hotel-ejemplo.es/assets/index-Bx3k9Lq.js?t=1:42',
        'line' => 42,
        'durable' => false,
    ]);

    expect(ErrorContextAllowlist::apply($una))->toBe($una);
})->group('RF-PD-15', 'RL-19');

it('normaliza source aunque llegue con espacios alrededor', function (): void {
    expect(ErrorContextAllowlist::apply(['source' => '  https://kiosk.hotel-ejemplo.es/assets/index.js:42 ']))
        ->toBe(['source' => '/assets/index.js:42']);
})->group('RF-PD-15', 'RL-19');

it('sigue sustituyendo un codigo heredado corto que llega solo como valor', function (): void {
    // Cuatro caracteres, el minimo de `EmployeeCode`, con las letras sueltas
    // que la lista blanca deja pasar.
    expect(ErrorContextAllowlist::apply(['reason' => 'A1B2']))->toBe(['reason' => '[code]']);
})->group('RF-PD-15', 'RL-19');

it('sanea como texto un numero que llega bajo una clave que no es de recuento', function (string $clave, int|float $valor, string $esperado): void {
    // Un telefono o un DNI sin letra mandados como numero no se guardan tal
    // cual: solo las claves de recuento admiten un numero sin inspeccionar.
    expect(ErrorContextAllowlist::apply([$clave => $valor]))->toBe([$clave => $esperado]);
})->with([
    'telefono bajo reason' => ['reason', 698765432, '[phone]'],
    'dni sin letra como decimal bajo cause' => ['cause', 45678912.0, '[n]'],
    'telefono bajo scope' => ['scope', 612345678, '[phone]'],
    'estado corto bajo reason' => ['reason', 500, '500'],
])->group('RF-PD-15', 'RL-19');

it('conserva los numeros bajo las claves de recuento y los booleanos bajo cualquiera', function (): void {
    expect(ErrorContextAllowlist::apply([
        'attempts' => 3, 'http_status' => 500, 'line' => 698765432, 'silence_ms' => 1500.5,
        'skew_seconds' => -30, 'entries' => 7, 'items' => 1, 'missing' => 0, 'purged' => 2, 'durable' => false,
    ]))->toBe([
        'attempts' => 3, 'http_status' => 500, 'line' => 698765432, 'silence_ms' => 1500.5,
        'skew_seconds' => -30, 'durable' => false, 'entries' => 7, 'items' => 1, 'missing' => 0, 'purged' => 2,
    ]);
})->group('RF-PD-15');

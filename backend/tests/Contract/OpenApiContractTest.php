<?php

declare(strict_types=1);

use Spectator\RequestFactory;
use Spectator\Spectator;
use Tests\Contract\Support\Contract;

/*
 * El contrato de la API, comprobado (doc 02 §9.2, RQ-06, ADR-013).
 *
 * QUE SE PUEDE PROBAR HOY Y QUE NO. `routes/api_v1.php` esta vacio a proposito:
 * el contrato se escribe ANTES que el codigo, asi que todavia no hay respuesta
 * que validar contra el esquema. Eso llega con el endpoint, en la tarea 1.7, y
 * sera una prueba distinta: peticion real -> `assertValidResponse()`.
 *
 * Lo que si se puede probar hoy, y no es poco:
 *
 *   1. Que Spectator carga el fichero. Si no lo carga, la prueba de la 1.7 no
 *      fallaria: se saltaria la validacion sin decir nada, que es peor.
 *   2. Que el contrato sigue diciendo lo que las reglas duras exigen que diga.
 *      Un contrato es un documento, y un documento se erosiona: alguien añade un
 *      campo «solo para depurar», alguien afloja un patron para que le pase un
 *      caso. Estas pruebas son las que convierten las decisiones de este fichero
 *      en algo que se rompe al tocarlo, en vez de en un comentario.
 *
 * La regla dura 17 —los rechazos de escaneo son indistinguibles— es la que mas
 * facil se rompe escribiendo un contrato, y por eso tiene tres pruebas propias:
 * el patron ausente en `qr_payload`, la respuesta unica de rechazo y la
 * imposibilidad de alojar la causa en ella.
 */

it('lo carga Spectator como documento OpenAPI 3.1', function (): void {
    $specification = app(RequestFactory::class)->using('openapi.yaml')->resolve();

    expect($specification->openapi)->toStartWith('3.1');

    Spectator::reset();
})->group('RQ-06');

it('describe solo los endpoints cuya tarea existe, y todos bajo /api/v1', function (): void {
    // El Anexo B del doc 01 lista unos 50 endpoints como contrato de REFERENCIA.
    // Aqui solo entran los que ya tienen caso de uso: describir la forma de un
    // endpoint que nadie ha diseñado es inventarla, y en v1 lo inventado no se
    // puede quitar sin v2 (ADR-012).
    expect(Contract::keys('paths'))->toBe([
        '/api/v1/health',
        '/api/v1/ready',
        '/api/v1/scan',
        // Tarea 1.7: sincronizacion de la cola offline y padron/latido del quiosco.
        '/api/v1/scan/batch',
        '/api/v1/kiosk/roster',
        '/api/v1/kiosk/heartbeat',
        // Tarea 1.6: acceso de gestion y plantilla.
        '/api/v1/auth/login',
        '/api/v1/auth/logout',
        '/api/v1/auth/me',
        '/api/v1/employees',
        '/api/v1/employees/{uuid}',
        '/api/v1/employees/{uuid}/offboard',
        // Tarea 1.13: provision, entrega y restablecimiento del PIN (RF-ID-09).
        '/api/v1/employees/{uuid}/pin/reset',
        '/api/v1/employees/{uuid}/pin/deliver',
        // Tarea 1.16: detalle de jornada del panel (RF-PA-03). Cuelga de
        // `/employees` pero no es plantilla: son las horas de una persona, y por
        // eso su ambito es `attendance:read` y no `employees:*`.
        '/api/v1/employees/{uuid}/workdays',
        '/api/v1/departments',
        '/api/v1/departments/{id}',
        '/api/v1/sites',
        '/api/v1/sites/{id}',
        // Tarea 1.5: credenciales QR.
        '/api/v1/credentials',
        '/api/v1/credentials/{uuid}/revoke',
        // Tarea 1.10: impresion de tarjetas (ADR-034), lote, entrega y estado.
        '/api/v1/credentials/status',
        '/api/v1/credentials/print-batch',
        '/api/v1/credentials/{uuid}/print',
        '/api/v1/credentials/{uuid}/deliver',
        // Tarea 1.15: correcciones trazadas del registro horario (RF-PA-04).
        '/api/v1/shift-entries',
        '/api/v1/shift-entries/{uuid}',
        '/api/v1/shift-entries/{uuid}/void',
        // Tarea 1.17: exportacion normalizada para la Inspeccion (RF-IN-05).
        '/api/v1/reports/legal-export',
    ]);
})->group('RQ-06');

it('no describe el segundo factor, que es de la Fase 2', function (): void {
    // El Anexo A situa RF-ID-01 completo —2FA— en la tarea 2.1. Describir ahora
    // `POST /api/v1/auth/2fa/verify` fijaria en v1 la forma de un flujo que
    // nadie ha disenado, y en v1 lo escrito no se puede quitar (ADR-012).
    expect(Contract::has('paths', '/api/v1/auth/2fa/verify'))->toBeFalse();
})->group('RQ-06', 'RF-ID-01');

it('no ofrece ningun verbo de borrado en toda la API', function (): void {
    // Regla dura 5: nada se borra. La baja de un empleado es un cambio de estado
    // con fecha (RF-GP-03) y los centros y departamentos no se eliminan porque
    // se llevarian por delante el registro horario que hay que conservar cuatro
    // anos (RL-02). Que no exista un DELETE en el contrato es lo que impide que
    // aparezca uno «solo para el panel de administracion».
    $deletes = [];

    foreach (Contract::operations() as $operation) {
        if ($operation['method'] === 'delete') {
            $deletes[] = $operation['path'];
        }
    }

    expect($deletes)->toBe([]);
})->group('RF-GP-03', 'RQ-06');

it('exige el ambito employees:* en todo endpoint de plantilla', function (): void {
    // §7.3, y la mitad de la autorizacion que no es la policy (regla dura 18).
    $paths = [
        '/api/v1/employees',
        '/api/v1/employees/{uuid}',
        '/api/v1/employees/{uuid}/offboard',
        '/api/v1/employees/{uuid}/pin/reset',
        '/api/v1/employees/{uuid}/pin/deliver',
        '/api/v1/departments',
        '/api/v1/departments/{id}',
        '/api/v1/sites',
        '/api/v1/sites/{id}',
    ];

    foreach ($paths as $path) {
        foreach (Contract::keys('paths', $path) as $method) {
            if ($method === 'parameters') {
                continue;
            }

            expect(Contract::value('paths', $path, $method, 'security'))
                ->toBe([['managementToken' => ['employees:*']]], $method.' '.$path);
        }
    }
})->group('RS-04', 'RQ-07');

it('mantiene el correo del empleado opcional en el contrato', function (): void {
    // Regla dura 12 y ADR-015. El contrato es el sitio donde esto se rompe sin
    // que nadie lo note: basta anadir `email` a `required` para que el cliente
    // generado lo exija y el alta deje de poder hacerse sin correo.
    expect(Contract::value('components', 'schemas', 'CreateEmployeeRequest', 'required'))
        ->toBe(['site_id', 'first_name', 'last_name', 'hired_at'])
        ->and(Contract::value('components', 'schemas', 'Employee', 'properties', 'email', 'type'))
        ->toBe(['string', 'null']);
})->group('RF-GP-01');

it('no ofrece ningun campo por el que pueda salir el documento de identidad', function (): void {
    // RL-08: solo existe su digest, y no hay motivo para devolverlo. `Employee`
    // no admite propiedades adicionales, asi que el contrato lo hace imposible.
    $employee = Contract::map('components', 'schemas', 'Employee');

    expect($employee['additionalProperties'])->toBeFalse();

    $properties = Contract::keys('components', 'schemas', 'Employee', 'properties');

    expect($properties)->not->toContain('national_id');
    expect($properties)->not->toContain('national_id_hash');
    expect($properties)->not->toContain('pin_hash');

    // En la peticion si entra, y por eso esta marcado `writeOnly`: no existe
    // respuesta que pueda devolverlo.
    expect(Contract::value('components', 'schemas', 'CreateEmployeeRequest', 'properties', 'national_id', 'writeOnly'))
        ->toBeTrue();
})->group('RL-08');

it('deja el PIN en claro en una sola respuesta y en ninguna consulta', function (): void {
    // RF-ID-09 y regla dura 21. El contrato es donde esto se rompe sin que nadie
    // lo note: basta anadir `pin` a `Employee` para que el valor empiece a salir
    // en cada consulta de ficha y en cada listado.
    //
    // Las dos unicas operaciones que pueden devolverlo son las que lo generan:
    // el alta (201) y el restablecimiento (200). La entrega NO: registra que se
    // entrego, no que se entrego.
    $employeeProperties = Contract::keys('components', 'schemas', 'Employee', 'properties');

    expect($employeeProperties)->not->toContain('pin');

    expect(Contract::value(
        'paths', '/api/v1/employees', 'post', 'responses', '201',
        'content', 'application/json', 'schema',
    ))->toBe(['$ref' => '#/components/schemas/EmployeeProvisioned']);

    expect(Contract::value(
        'paths', '/api/v1/employees/{uuid}/pin/reset', 'post', 'responses', '200',
        'content', 'application/json', 'schema',
    ))->toBe(['$ref' => '#/components/schemas/IssuedPin']);

    // El acuse de entrega no ofrece ningun hueco donde alojar un PIN.
    $receipt = Contract::map('components', 'schemas', 'PinDeliveryReceipt');

    expect($receipt['additionalProperties'])->toBeFalse()
        ->and(Contract::keys('components', 'schemas', 'PinDeliveryReceipt', 'properties'))
        ->toBe(['employee_uuid', 'delivered_at', 'delivered_by', 'pin_status']);

    // Seis digitos, ni mas ni menos: el patron es lo que impide que un dia entre
    // por aqui un «PIN» de cuatro.
    expect(Contract::text('components', 'schemas', 'IssuedPin', 'properties', 'pin', 'pattern'))
        ->toBe('^[0-9]{6}$');
})->group('RF-ID-09', 'RL-05');

it('no ofrece ningun camino para enviar el PIN por correo', function (): void {
    // Regla dura 12 y ADR-015: el producto no depende del correo del empleado y
    // la entrega del PIN es un acto presencial y registrado. Ni «reenviar PIN»,
    // ni enlace de recuperacion, ni invitacion.
    $operaciones = [];

    foreach (Contract::operations() as $operation) {
        $operaciones[] = $operation['path'];
    }

    expect($operaciones)->not->toContain('/api/v1/employees/{uuid}/pin/email')
        ->and($operaciones)->not->toContain('/api/v1/employees/{uuid}/pin/send')
        ->and(Contract::keys('components', 'schemas', 'PinDeliveryReceipt', 'properties'))
        ->not->toContain('email');
})->group('RF-ID-09', 'RF-ID-06');

it('declara la seguridad de cada operacion de forma explicita', function (): void {
    // Incluido `security: []` para decir que una sonda es publica. Sin esta
    // prueba, olvidarse de `security` no se distingue de decidir que no hace
    // falta, y esa diferencia es la mitad de la autorizacion (la otra mitad es
    // la policy, regla dura 18).
    $sinSeguridad = [];

    foreach (Contract::operations() as $operation) {
        if (! Contract::has('paths', $operation['path'], $operation['method'], 'security')) {
            $sinSeguridad[] = strtoupper($operation['method']).' '.$operation['path'];
        }
    }

    expect($sinSeguridad)->toBe([]);
})->group('RQ-07', 'RS-04');

it('declara todos los ambitos de token del documento 02 §7.3', function (): void {
    // Los ambitos se declaran de una vez, aunque hoy solo `/scan` use uno. Es lo
    // que impide que cada tarea invente el suyo y que acaben conviviendo
    // `reports:*` y `report:read` para lo mismo.
    $declarados = [];

    foreach (Contract::keys('components', 'securitySchemes') as $scheme) {
        foreach (Contract::keys('components', 'securitySchemes', $scheme, 'flows') as $flow) {
            $declarados = [
                ...$declarados,
                ...Contract::keys('components', 'securitySchemes', $scheme, 'flows', $flow, 'scopes'),
            ];
        }
    }

    sort($declarados);

    expect($declarados)->toBe([
        'attendance:correct',
        'attendance:read',
        'audit:read',
        'credentials:*',
        'diagnostics:*',
        'employees:*',
        'heartbeat:write',
        'incidents:*',
        'license:*',
        'reports:*',
        'reports:legal',
        'roster:read',
        'scan:write',
        'self:read',
        'settings:*',
        'support:*',
    ]);
})->group('RS-04');

it('publica la version desplegada en la sonda de vida', function (): void {
    // Doc 02 §10.5: la version es visible en /api/v1/health para poder
    // correlacionar una incidencia con una version concreta sin entrar por SSH
    // al servidor del cliente.
    expect(Contract::value('components', 'schemas', 'Health', 'required'))
        ->toBe(['status', 'version'])
        ->and(Contract::text('components', 'schemas', 'Health', 'properties', 'version', 'pattern'))
        ->toBe('^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$');
})->group('RNF-D-01');

it('separa la sonda de vida de la de disponibilidad', function (): void {
    // La de vida no puede fallar por una dependencia caida: si lo hiciera, el
    // orquestador reiniciaria el contenedor cada vez que PostgreSQL tarde en
    // arrancar. La de disponibilidad si, y lo dice con un 503 que el orquestador
    // entiende como "reintenta", no como error del cliente.
    expect(Contract::keys('paths', '/api/v1/health', 'get', 'responses'))->toBe(['200'])
        ->and(Contract::keys('paths', '/api/v1/ready', 'get', 'responses'))->toBe(['200', '503']);
})->group('RNF-D-01');

it('exige el ambito scan:write para registrar un escaneo', function (): void {
    expect(Contract::value('paths', '/api/v1/scan', 'post', 'security'))
        ->toBe([['kioskToken' => ['scan:write']]]);
})->group('RF-AT-01', 'RS-04');

it('exige la cabecera Idempotency-Key en la escritura del quiosco', function (): void {
    $parameter = Contract::map('components', 'parameters', 'IdempotencyKey');

    expect($parameter['name'])->toBe('Idempotency-Key')
        ->and($parameter['in'])->toBe('header')
        ->and($parameter['required'])->toBeTrue()
        ->and(Contract::value('paths', '/api/v1/scan', 'post', 'parameters'))
        ->toBe([['$ref' => '#/components/parameters/IdempotencyKey']]);
})->group('RF-AT-07');

it('obliga a que el identificador del escaneo sea un UUID v7 del cliente', function (): void {
    // Regla dura 8 y doc 02 §6: v7 es ordenable temporalmente, lo que mantiene la
    // localidad del indice de scan_events. Un v4 aqui es un fallo del cliente, no
    // un caso a tolerar.
    expect(Contract::text('components', 'schemas', 'ScanId', 'pattern'))
        ->toBe('^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$');
})->group('RF-AT-07');

it('solo admite instantes en UTC con sufijo Z', function (): void {
    // Regla dura 3. Aceptar un desfase explicito convertiria la zona horaria en un
    // dato del cliente, y con turnos nocturnos y cambio de hora eso es una jornada
    // atribuida al dia equivocado.
    expect(Contract::text('components', 'schemas', 'UtcTimestamp', 'pattern'))
        ->toBe('^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$');

    $instantes = [
        'ScanRequest' => ['occurred_at'],
        'ScanAccepted' => ['occurred_at', 'recorded_at'],
    ];

    foreach ($instantes as $esquema => $campos) {
        foreach ($campos as $campo) {
            expect(Contract::value('components', 'schemas', $esquema, 'properties', $campo, 'allOf'))
                ->toBe([['$ref' => '#/components/schemas/UtcTimestamp']]);
        }
    }
})->group('RN-04', 'RF-AT-09');

it('devuelve al quiosco lo que enseña en pantalla y nada mas', function (): void {
    // RF-AT-05: nombre, accion, hora y acumulado del dia. No es un endpoint de
    // consulta de plantilla: un token de quiosco comprometido no puede
    // reconstruir la plantilla del hotel (RS-04, §7.3).
    expect(Contract::value('components', 'schemas', 'ScanAccepted', 'additionalProperties'))
        ->toBeFalse()
        ->and(Contract::keys('components', 'schemas', 'ScanAccepted', 'properties'))
        ->toBe([
            'scan_id',
            'action',
            'employee_display_name',
            'work_date',
            'occurred_at',
            'recorded_at',
            'worked_minutes',
        ]);
})->group('RF-AT-05', 'RS-04');

it('distingue la pausa del fin de turno en los dos sentidos', function (): void {
    // Nota de contrato del Anexo B y ADR-024: con la pausa modelada como dos
    // tramos, `break_start` y `clock_out` son identicos para el servidor. Sin
    // `intent` en la peticion, la jornada del tramo siguiente se atribuye mal
    // cuando la pausa cruza medianoche.
    expect(Contract::value('components', 'schemas', 'ScanIntent', 'enum'))
        ->toBe(['auto', 'break_start', 'break_end'])
        ->and(Contract::value('components', 'schemas', 'ScanIntent', 'default'))
        ->toBe('auto')
        ->and(Contract::value('components', 'schemas', 'ScanAction', 'enum'))
        ->toBe(['clock_in', 'clock_out', 'break_start', 'break_end', 'debounced']);

    // `debounced` no describe una accion sobre un tramo —no se creo ninguno— y
    // por eso no tiene `intent` que le corresponda: el cliente pide `auto`,
    // `break_start` o `break_end`, y el servidor puede responder que no hizo
    // nada (RF-AT-06, ADR-031). Ampliar este enum es aditivo (ADR-012).
    expect(Contract::value('components', 'schemas', 'ScanIntent', 'enum'))
        ->not->toContain('debounced');
})->group('RF-AT-12', 'RF-AT-06');

it('no impone un patron al payload del QR', function (): void {
    // Regla dura 17, la trampa mas facil de este contrato. Un `pattern` aqui haria
    // que un payload con el prefijo cambiado devolviera 400 en vez del 422
    // generico, y con eso el CONTRATO distinguiria "formato invalido" de
    // "credencial desconocida": justo lo que el doc 02 §5.2 obliga a no
    // distinguir. Los limites de longitud si, porque no dependen del contenido.
    $payload = Contract::map('components', 'schemas', 'ScanRequest', 'properties', 'qr_payload');

    expect(array_key_exists('pattern', $payload))->toBeFalse()
        ->and(array_key_exists('enum', $payload))->toBeFalse()
        ->and(array_key_exists('format', $payload))->toBeFalse()
        ->and($payload['minLength'])->toBe(1)
        ->and($payload['maxLength'])->toBe(128);
})->group('RS-03', 'RF-QR-02');

it('tiene una unica respuesta de rechazo de escaneo', function (): void {
    // Doc 02 §5.2, punto 6: todos los rechazos devuelven la misma respuesta.
    // Prefijo que no es FH1, clave desconocida, firma que no valida, credencial
    // revocada, empleado de baja: una sola forma para las cinco.
    expect(Contract::keys('paths', '/api/v1/scan', 'post', 'responses'))
        ->toBe(['200', '400', '401', '403', '422', '429'])
        ->and(Contract::value(
            'paths', '/api/v1/scan', 'post', 'responses', '422',
            'content', 'application/problem+json', 'schema',
        ))->toBe(['$ref' => '#/components/schemas/ScanRejected']);
})->group('RS-03', 'RF-QR-02');

it('hace imposible que el rechazo describa su causa', function (): void {
    // Que sea imposible es el punto, y no que este bien escrito hoy. Todos los
    // campos estan fijados a un valor unico y no se admiten miembros adicionales:
    // el contrato no ofrece ningun hueco donde alojar la causa, ni un codigo, ni
    // un motivo, ni un matiz en el texto. La causa concreta solo existe en
    // scan_events.result y en el log del servidor (RS-03, regla dura 17).
    $rechazo = Contract::map('components', 'schemas', 'ScanRejected');

    expect($rechazo['additionalProperties'])->toBeFalse()
        ->and(Contract::keys('components', 'schemas', 'ScanRejected', 'properties'))
        ->toBe(['type', 'title', 'status', 'detail', 'scan_id']);

    // Los cuatro miembros del problema estan clavados a un valor. El unico que
    // varia es scan_id, que es un eco de lo que envio el cliente y no dice nada
    // que el cliente no supiera ya.
    foreach (['type', 'title', 'status', 'detail'] as $campo) {
        $valores = Contract::value('components', 'schemas', 'ScanRejected', 'properties', $campo, 'enum');

        expect($valores)->toBeArray()->toHaveCount(1);
    }
})->group('RS-03', 'RF-QR-02');

it('expresa el anti-rebote como desenlace aceptado y no como rechazo', function (): void {
    // RF-AT-06 y ADR-031. Un segundo escaneo dentro de la ventana de gracia no
    // crea evento, pero SI se proceso correctamente: viaja en el 200.
    //
    // Que sea 2xx no es una preferencia de estilo. La cola offline del quiosco
    // reintenta con backoff ante fallo (RF-KI-04), asi que un 4xx dejaria un
    // escaneo encolado reintentandose contra una ventana que ya paso. La regla
    // dura 19 dice que el quiosco nunca bloquea al empleado, y una cola que no
    // drena es exactamente eso con retraso.
    $schema = ['paths', '/api/v1/scan', 'post', 'responses', '200', 'content', 'application/json', 'schema'];

    expect(Contract::value(...[...$schema, 'oneOf']))->toBe([
        ['$ref' => '#/components/schemas/ScanAccepted'],
        ['$ref' => '#/components/schemas/ScanDebounced'],
    ]);

    // Discriminado por `action`: el cliente generado es una union y no se puede
    // pintar la confirmacion sin ramificar.
    expect(Contract::value(...[...$schema, 'discriminator', 'propertyName']))->toBe('action')
        ->and(Contract::value(...[...$schema, 'discriminator', 'mapping', 'debounced']))
        ->toBe('#/components/schemas/ScanDebounced');

    // Y NO aparece entre los rechazos: el 422 sigue teniendo una sola forma.
    expect(Contract::value(
        'paths', '/api/v1/scan', 'post', 'responses', '422',
        'content', 'application/problem+json', 'schema',
    ))->toBe(['$ref' => '#/components/schemas/ScanRejected']);
})->group('RF-AT-06');

it('no deja que el anti-rebote afirme un tramo que no se creo', function (): void {
    // El fallo que este esquema existe para impedir: que el quiosco enseñe
    // «Entrada 07:02» por un fichaje que no ocurrio y el empleado se vaya
    // convencido de haber fichado dos veces.
    //
    // Por eso ScanDebounced NO lleva work_date: no hay tramo que atribuir a
    // ninguna jornada. Y por eso `action` esta clavado con enum de un solo
    // valor, igual que en ScanRejected: el esquema no ofrece hueco donde
    // afirmar otra cosa.
    $campos = Contract::keys('components', 'schemas', 'ScanDebounced', 'properties');

    expect($campos)->not->toContain('work_date')
        ->and(Contract::map('components', 'schemas', 'ScanDebounced')['additionalProperties'])->toBeFalse()
        ->and(Contract::value('components', 'schemas', 'ScanDebounced', 'properties', 'action', 'enum'))
        ->toBe(['debounced']);

    // last_accepted_at es obligatorio: sin el, el quiosco no puede decir «hace
    // unos segundos» (escenario «Anti-rebote» del doc 01 §11) sin inventarselo.
    expect(Contract::value('components', 'schemas', 'ScanDebounced', 'required'))
        ->toContain('last_accepted_at');
})->group('RF-AT-06', 'RF-AT-05');

it('exige el ambito attendance:correct en los tres endpoints de correccion', function (): void {
    // §7.3 y regla dura 18. Leer el registro de alguien y rectificarlo son dos
    // potestades distintas: un `auditor` tiene `attendance:read` y no puede
    // tener esta, porque auditar es mirar. Con un solo ambito, quien puede
    // consultar la presencia podria cambiar las horas de cualquiera.
    $paths = [
        '/api/v1/shift-entries',
        '/api/v1/shift-entries/{uuid}',
        '/api/v1/shift-entries/{uuid}/void',
    ];

    foreach ($paths as $path) {
        foreach (Contract::keys('paths', $path) as $method) {
            if ($method === 'parameters') {
                continue;
            }

            expect(Contract::value('paths', $path, $method, 'security'))
                ->toBe([['managementToken' => ['attendance:correct']]], $method.' '.$path);
        }
    }
})->group('RS-04', 'RF-PA-04', 'RF-ID-03');

it('devuelve los dos identificadores en toda respuesta de correccion', function (): void {
    // ADR-035, decision 1: la version corregida estrena `uuid` y la anterior
    // conserva el suyo. Si la respuesta llevara solo uno, el panel seguiria
    // enviando el identificador viejo en la siguiente correccion y recibiria un
    // 409 sin entender por que.
    $required = Contract::value('components', 'schemas', 'CorrectedShiftEntry', 'required');

    expect($required)
        ->toContain('shift_entry_uuid')
        ->toContain('superseded_shift_entry_uuid');

    // `superseded_shift_entry_uuid` ADMITE null y por eso no basta con que sea
    // obligatorio: en un alta y en una anulacion no hay version sustituida.
    $superseded = Contract::map(
        'components', 'schemas', 'CorrectedShiftEntry', 'properties', 'superseded_shift_entry_uuid',
    );

    expect($superseded['oneOf'])->toContain(['type' => 'null']);

    // Y las tres operaciones devuelven ese mismo esquema: los dos
    // identificadores no son una particularidad del PATCH.
    $responses = [
        ['/api/v1/shift-entries', 'post', '201'],
        ['/api/v1/shift-entries/{uuid}', 'patch', '200'],
        ['/api/v1/shift-entries/{uuid}/void', 'post', '200'],
    ];

    foreach ($responses as [$path, $method, $status]) {
        expect(Contract::value(
            'paths', $path, $method, 'responses', $status,
            'content', 'application/json', 'schema',
        ))->toBe(['$ref' => '#/components/schemas/CorrectedShiftEntry'], $method.' '.$path);
    }
})->group('RN-13', 'RF-PA-04');

it('declara los nueve motivos del Anexo C y ni uno mas', function (): void {
    // El catalogo es cerrado (doc 01 Anexo C) y es la columna por la que se
    // agrupa `manual_corrections_total{reason_code}` y por la que una inspeccion
    // pregunta «cuantas correcciones por olvido de fichaje hubo en marzo». Con
    // texto libre, la misma causa acaba escrita de tres formas.
    expect(Contract::value('components', 'schemas', 'CorrectionReasonCode', 'enum'))->toBe([
        'OLVIDO_FICHAJE_ENTRADA',
        'OLVIDO_FICHAJE_SALIDA',
        'FALLO_TECNICO_QUIOSCO',
        'TARJETA_NO_DISPONIBLE',
        'CREDENCIAL_NO_ENTREGADA',
        'ERROR_DE_ESCANEO_DUPLICADO',
        'AJUSTE_ACORDADO_CON_RRHH',
        'ALTA_RETROACTIVA',
        'OTROS',
    ]);

    // Y es obligatorio en las tres peticiones: sin motivo, el registro dice que
    // las horas cambiaron y no dice por que (RN-13).
    foreach (['AddShiftEntryRequest', 'CorrectShiftEntryRequest', 'VoidShiftEntryRequest'] as $schema) {
        expect(Contract::value('components', 'schemas', $schema, 'required'))->toContain('reason_code');
    }
})->group('RF-PA-04', 'RN-13');

it('reserva el 409 para la version que ya no es vigente', function (): void {
    // ADR-035: un PATCH repetido sobre el uuid viejo recibe 409, no 404. Los dos
    // codigos tienen que estar declarados en las dos operaciones que reciben un
    // {uuid}, porque significan cosas distintas para quien llama: el 404 dice
    // «te equivocaste de identificador» y el 409 «alguien llego antes».
    foreach ([['/api/v1/shift-entries/{uuid}', 'patch'], ['/api/v1/shift-entries/{uuid}/void', 'post']] as [$path, $method]) {
        $codes = Contract::keys('paths', $path, $method, 'responses');

        expect($codes)->toContain('404');
        expect($codes)->toContain('409');
    }

    // El alta NO declara 404: no recibe ningun identificador de tramo, asi que
    // no hay nada que no encontrar. Declararlo invitaria a que alguien lo
    // devolviera para decir «no existe el empleado», que es un 422.
    expect(Contract::keys('paths', '/api/v1/shift-entries', 'post', 'responses'))->not->toContain('404');
})->group('RF-PA-04', 'RN-13');

it('no ofrece ninguna forma de vaciar una marca ya registrada', function (): void {
    // Regla dura 5. Un tramo que no debio cerrarse se ANULA y se vuelve a dar de
    // alta, y asi consta lo que paso; si el PATCH admitiera `clocked_out_at:
    // null`, reabrir un tramo cerrado seria un efecto lateral sin rastro.
    $properties = Contract::map('components', 'schemas', 'CorrectShiftEntryRequest', 'properties');

    foreach (['clocked_in_at', 'clocked_out_at'] as $mark) {
        expect($properties[$mark])->toBe(['$ref' => '#/components/schemas/UtcTimestamp'], $mark);
    }
})->group('RN-13', 'RF-PA-04');

it('sirve el detalle de jornada con attendance:read y solo de lectura', function (): void {
    // §7.3 y regla dura 18, la mitad de la autorizacion que no es la policy.
    // `attendance:read` y no `employees:*` —lo que se lee no es la ficha de
    // nadie, son sus horas— ni `attendance:correct`, que es el ambito de las
    // rutas que CAMBIAN el registro (RF-PA-04).
    expect(Contract::value('paths', '/api/v1/employees/{uuid}/workdays', 'get', 'security'))
        ->toBe([['managementToken' => ['attendance:read']]]);

    // Y ningun verbo mas en esa ruta: consultar el registro horario no puede ser
    // tambien la via para escribirlo.
    expect(Contract::keys('paths', '/api/v1/employees/{uuid}/workdays'))
        ->toBe(['parameters', 'get']);
})->group('RF-PA-03', 'RS-04', 'RQ-07');

it('devuelve el detalle de jornada con las dos marcas de cada fichaje', function (): void {
    // Regla dura 9: `clocked_in_at` es cuando la persona ficho —lo que vale para
    // el registro legal— y `clocked_in_recorded_at` cuando el servidor lo
    // recibio. Si el contrato dejara de exigir la segunda, el panel no podria
    // explicar un fichaje que viajo en la cola offline: solo esconderlo.
    $required = Contract::value('components', 'schemas', 'WorkDayShiftEntry', 'required');

    expect($required)->toContain('clocked_in_at')
        ->and($required)->toContain('clocked_in_recorded_at')
        ->and($required)->toContain('clocked_out_recorded_at')
        // Regla dura 3: la hora local viaja ADEMAS de la UTC, con su
        // desplazamiento, para que el cliente no la adivine con la zona del
        // navegador.
        ->and($required)->toContain('clocked_in_at_local')
        ->and(Contract::value('components', 'schemas', 'WorkDayShiftEntry', 'properties', 'clocked_in_at'))
        ->toBe(['$ref' => '#/components/schemas/UtcTimestamp']);
})->group('RF-PA-03', 'RF-KI-04');

it('no deja salir el nombre de un empleado por el detalle de jornada', function (): void {
    // Regla dura 21. El unico nombre de persona de esta respuesta es el de quien
    // FIRMO una correccion, que RN-13 obliga a poder enseñar; el empleado viaja
    // por su UUID, como en el resto de la API. `additionalProperties: false` en
    // los cuatro esquemas es lo que impide que un dia entre un `employee_name`
    // «para no tener que pedir la ficha».
    foreach (['EmployeeWorkDays', 'WorkDayDetail', 'WorkDayShiftEntry', 'WorkDayCorrection'] as $schema) {
        expect(Contract::value('components', 'schemas', $schema, 'additionalProperties'))
            ->toBeFalse($schema);
    }

    $properties = Contract::keys('components', 'schemas', 'EmployeeWorkDays', 'properties');

    expect($properties)->toBe(['employee_uuid', 'time_zone', 'from', 'to', 'data', 'meta']);

    // Y una correccion no arrastra el correo de quien la firmo: en esa pantalla
    // no aporta nada y es un dato personal mas viajando de mas.
    expect(Contract::keys('components', 'schemas', 'CorrectionAuthor', 'properties'))
        ->toBe(['uuid', 'name']);
})->group('RF-PA-03', 'RL-08');

it('entrega la exportacion legal como fichero tabular y no como JSON', function (): void {
    // RL-06 exige «formato tabular legible y tratable, no propietario». Si el
    // contrato declarara `application/json`, el cliente TypeScript se generaria
    // esperando un objeto y el panel intentaria pintar una tabla que no existe.
    $content = Contract::keys('paths', '/api/v1/reports/legal-export', 'get', 'responses', '200', 'content');

    expect($content)->toBe(['text/csv']);

    // Y no ofrece ningun formato propietario como alternativa: los ofimaticos de
    // conveniencia son otro requisito y otra tarea (2.9).
    foreach ($content as $type) {
        expect($type)->not->toContain('spreadsheetml')
            ->and($type)->not->toContain('vnd.ms-excel');
    }
})->group('RF-IN-05', 'RL-06');

it('exige el ambito de informes legales, y admite cualquiera de los dos', function (): void {
    // El `auditor` lleva `reports:legal` —el ambito estrecho— y RRHH lleva
    // `reports:*`. Dos requisitos de seguridad separados es como OpenAPI expresa
    // «o»; uno solo con los dos ambitos dentro significaria «y», y dejaria fuera
    // precisamente al rol cuya funcion es esta (doc 02 §7.3, regla dura 18).
    $security = Contract::value('paths', '/api/v1/reports/legal-export', 'get', 'security');

    expect($security)->toBe([
        ['managementToken' => ['reports:legal']],
        ['managementToken' => ['reports:*']],
    ]);

    // Ni el token de quiosco ni el del portal del empleado alcanzan este
    // endpoint: entrega la lista nominal de la plantilla (RS-04).
    $declared = json_encode($security, JSON_THROW_ON_ERROR);

    expect($declared)->not->toContain('kioskToken')
        ->and($declared)->not->toContain('employeeToken');
})->group('RS-04', 'RF-IN-05', 'RF-ID-03');

it('acota la exportacion legal por fecha de jornada y por una sola persona', function (): void {
    // `format: date` y no `date-time`: un instante partiria el turno de noche
    // del dia 31 por la puerta de atras (RN-05, regla dura 4). Y `employee_uuid`
    // es uno, no una lista: es el unico alcance parcial que el asiento de
    // `audit_log` y la cabecera del fichero saben describir en una linea.
    $parameters = Contract::value('paths', '/api/v1/reports/legal-export', 'get', 'parameters');

    expect($parameters)->toBeArray();

    /** @var list<array<string, mixed>> $parameters */
    $byName = [];

    foreach ($parameters as $parameter) {
        $name = $parameter['name'];

        expect($name)->toBeString();

        /** @var string $name */
        $byName[$name] = $parameter;
    }

    expect(array_keys($byName))->toBe(['from', 'to', 'employee_uuid']);

    foreach (['from', 'to'] as $name) {
        expect($byName[$name]['required'])->toBeTrue($name.' es obligatorio.')
            ->and($byName[$name]['schema'])->toBe(['type' => 'string', 'format' => 'date'], $name);
    }

    expect($byName['employee_uuid']['required'] ?? false)->toBeFalse()
        ->and($byName['employee_uuid']['schema'])->toBe(['type' => 'string', 'format' => 'uuid']);
})->group('RF-IN-05', 'RN-05');

it('publica en cabeceras cuanto se llevo cada exportacion legal', function (): void {
    // Son las mismas cifras que afirma el asiento de `audit_log` (RS-05): lo que
    // permite comprobar que la descarga llego entera sin abrir el fichero, y
    // cuadrar el adjunto de un requerimiento con el trail meses despues.
    $headers = Contract::keys('paths', '/api/v1/reports/legal-export', 'get', 'responses', '200', 'headers');

    expect($headers)->toContain('X-Kronoqr-Export-Shift-Rows')
        ->and($headers)->toContain('X-Kronoqr-Export-Correction-Rows')
        ->and($headers)->toContain('Content-Disposition')
        // El cuerpo es una lista nominal de la plantilla: ni un proxy ni un
        // navegador pueden guardarla.
        ->and($headers)->toContain('Cache-Control');
})->group('RF-IN-05', 'RS-05');

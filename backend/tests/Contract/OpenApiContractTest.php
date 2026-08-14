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
    ]);
})->group('RQ-06');

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

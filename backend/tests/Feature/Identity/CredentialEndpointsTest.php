<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\CredentialResolver;
use App\Modules\Shared\Domain\ValueObject\CredentialRejectionReason;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\Credentials;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Emision y revocacion de credenciales por la API (RF-QR-01, RF-QR-03),
 * validadas contra el contrato.
 *
 * Cada respuesta pasa por Spectator: el cliente TypeScript de los tres frontends
 * se genera de `openapi.yaml`, asi que una desviacion aqui rompe a los tres a la
 * vez y sin aviso (ADR-013).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

/**
 * @return array{token: string, employee: string, site: int}
 */
function credentialsContext(string $status = 'active'): array
{
    $site = WorkforceFixtures::site();

    return [
        'token' => ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)),
        'employee' => WorkforceFixtures::employee($site, null, $status),
        'site' => $site,
    ];
}

it('emite una credencial y la deja pendiente de imprimir, sin acuñar ningun QR', function (): void {
    // ADR-034: emitir crea el derecho a una tarjeta. El token, su firma y su
    // hash nacen al imprimir, dentro del PDF, que es de la tarea 1.10.
    $context = credentialsContext();

    $response = Api::as($context['token'])
        ->post('/api/v1/credentials', ['employee_uuid' => $context['employee']])
        ->assertValidRequest()
        ->assertValidResponse(201)
        ->assertJsonPath('employee_uuid', $context['employee'])
        ->assertJsonPath('status', 'active')
        ->assertJsonPath('reissue', false)
        ->assertJsonPath('key_id', null)
        ->assertJsonPath('printed_at', null)
        ->assertJsonPath('revoked_at', null)
        // **Ninguna respuesta de esta API lleva un QR.** Que el esquema lo
        // prohiba (`additionalProperties: false`) y que se compruebe aqui son dos
        // redes distintas: la primera cae si alguien toca el contrato.
        ->assertJsonMissingPath('qr_payload');

    $row = DB::table('credentials')->where('employee_id', '>', 0)->first();

    expect($row?->secret_hash)->toBeNull()
        ->and($row?->key_id)->toBeNull()
        ->and($row?->printed_at)->toBeNull();

    // Se conserva `no-store` aunque ya no viaje ningun secreto: es gestion de
    // credenciales de personas identificadas. Se comprueba que CONTIENE
    // `no-store` y no que sea exactamente eso: Laravel anade `private` por su
    // cuenta, y las dos directivas juntas dicen lo mismo y algo mas.
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
})->group('RF-QR-01', 'RF-QR-08', 'RS-01');

it('una credencial pendiente de imprimir no la resuelve el quiosco', function (): void {
    // La consecuencia operativa de ADR-034, y lo que el panel de RF-QR-08 tiene
    // que enseñar antes de cada incorporacion: emitida no es lo mismo que
    // «puede fichar». Sin hash no hay nada por lo que resolverla, ni siquiera
    // con un payload bien firmado.
    $context = credentialsContext();

    Api::as($context['token'])->post('/api/v1/credentials', ['employee_uuid' => $context['employee']]);

    $resolution = app(CredentialResolver::class)->resolve(Credentials::payloadFor()->toString());

    expect($resolution->isResolved())->toBeFalse()
        ->and($resolution->rejectionReason())->toBe(CredentialRejectionReason::UNKNOWN);
})->group('RF-QR-01', 'RF-QR-02', 'RF-QR-08');

it('una credencial impresa si la resuelve el quiosco', function (): void {
    // El circuito completo: lo que se imprime es lo que el verificador acepta.
    // Sin esta prueba, acuñado y verificacion podrian divergir sin que ninguno
    // de los dos fallara por su cuenta. Mientras la impresion no exista como
    // caso de uso (tarea 1.10), el acuñado lo simula el fixture.
    $context = credentialsContext();

    /** @var int $employeeId */
    $employeeId = DB::table('employees')->where('uuid', $context['employee'])->value('id');

    $payload = Credentials::issueFor($employeeId);

    $resolution = app(CredentialResolver::class)->resolve($payload->toString());

    expect($resolution->isResolved())->toBeTrue()
        ->and($resolution->employeeUuid())->toBe($context['employee']);
})->group('RF-QR-02', 'RF-QR-04');

it('deja el asiento de la emision en audit_log', function (): void {
    // Regla dura 6: a partir de aqui hay una tarjeta capaz de fichar en nombre de
    // una persona.
    $context = credentialsContext();

    Api::as($context['token'])->post('/api/v1/credentials', ['employee_uuid' => $context['employee']]);

    $entry = DB::table('audit_log')->where('subject_type', 'credential')->first();

    expect($entry)->not->toBeNull()
        ->and($entry?->action)->toBe('credential.issued')
        ->and($entry?->actor_type)->toBe('user');

    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) $entry?->payload, true, 512, JSON_THROW_ON_ERROR);

    expect($payload['employee_uuid'])->toBe($context['employee'])
        // Sin `key_id`: en la emision todavia no hay ninguno (ADR-034). Lo lleva
        // el asiento de la impresion, que es de la tarea 1.10.
        ->and($payload)->not->toHaveKey('key_id')
        // Ni el token ni su hash: con el asiento se investiga, no se fabrica una
        // tarjeta (regla dura 21).
        ->and($payload)->not->toHaveKey('secret_hash')
        ->and($payload)->not->toHaveKey('qr_payload');
})->group('RF-QR-01', 'RS-07', 'RL-04');

it('no emite una segunda credencial activa para el mismo empleado', function (): void {
    // Invariante del doc 01 §5.2. La salida correcta es reemitir.
    $context = credentialsContext();

    Api::as($context['token'])->post('/api/v1/credentials', ['employee_uuid' => $context['employee']]);

    Api::as($context['token'])
        ->post('/api/v1/credentials', ['employee_uuid' => $context['employee']])
        ->assertValidResponse(409)
        ->assertJsonPath('type', 'urn:kronoqr:problem:conflict');
})->group('RF-QR-03');

it('reemite revocando la anterior en la misma transaccion', function (): void {
    // Gherkin «Reemision por perdida» del doc 01 §11 y primer paso del runbook
    // `tarjeta-perdida-o-rota.md`: revocar, reemitir e imprimir. Aqui se
    // comprueban los dos primeros; el tercero es de la tarea 1.10.
    $context = credentialsContext();

    /** @var int $employeeId */
    $employeeId = DB::table('employees')->where('uuid', $context['employee'])->value('id');

    // La tarjeta perdida es una que existe de verdad: impresa y en la calle.
    $anterior = Credentials::issueFor($employeeId);

    Api::as($context['token'])
        ->post('/api/v1/credentials', [
            'employee_uuid' => $context['employee'],
            'reissue' => true,
            'reason' => 'Tarjeta extraviada en el turno de noche',
        ])
        ->assertValidRequest()
        ->assertValidResponse(201)
        ->assertJsonPath('reissue', true)
        // La sustituta nace pendiente de imprimir: hasta que no pase por la
        // impresora, esa persona ficha con su PIN de respaldo (RF-AT-11).
        ->assertJsonPath('printed_at', null);

    // La perdida deja de ser aceptada en el mismo acto, que es lo que importa.
    expect(app(CredentialResolver::class)->resolve($anterior->toString())->rejectionReason())
        ->toBe(CredentialRejectionReason::REVOKED);

    /** @var list<string> $acciones */
    $acciones = DB::table('audit_log')
        ->where('subject_type', 'credential')
        ->orderBy('id')
        ->pluck('action')
        ->all();

    expect($acciones)->toBe(['credential.revoked', 'credential.reissued']);
})->group('RF-QR-03', 'RS-07');

it('exige motivo para reemitir', function (): void {
    $context = credentialsContext();

    Api::as($context['token'])->post('/api/v1/credentials', ['employee_uuid' => $context['employee']]);

    Api::as($context['token'])
        ->post('/api/v1/credentials', ['employee_uuid' => $context['employee'], 'reissue' => true])
        ->assertValidResponse(422)
        ->assertJsonPath('type', 'urn:kronoqr:problem:validation-failed');
})->group('RF-QR-03');

it('devuelve 404 cuando el empleado no existe', function (): void {
    $context = credentialsContext();

    Api::as($context['token'])
        ->post('/api/v1/credentials', ['employee_uuid' => '0199f0c2-1f4a-7c3e-9b21-000000000000'])
        ->assertValidResponse(404);
})->group('RF-QR-01');

it('rechaza campos que el endpoint no conoce', function (): void {
    // Ni el token ni el key_id se aceptan del cliente: quien los enviara se iria
    // convencido de haber fijado la tarjeta.
    $context = credentialsContext();

    Api::as($context['token'])
        ->post('/api/v1/credentials', [
            'employee_uuid' => $context['employee'],
            'key_id' => 'zz',
        ])
        ->assertValidResponse(422);
})->group('RF-QR-01', 'RS-01');

it('revoca una credencial y deja su motivo', function (): void {
    $context = credentialsContext();

    Api::as($context['token'])->post('/api/v1/credentials', ['employee_uuid' => $context['employee']]);

    /** @var string $uuid */
    $uuid = DB::table('credentials')->value('uuid');

    Api::as($context['token'])
        ->post('/api/v1/credentials/'.$uuid.'/revoke', ['reason' => 'Tarjeta extraviada en el turno de noche'])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('uuid', $uuid)
        ->assertJsonPath('status', 'revoked')
        ->assertJsonPath('revoked_reason', 'Tarjeta extraviada en el turno de noche')
        ->assertJsonPath('employee_uuid', $context['employee'])
        // La respuesta de una credencial ya emitida NO lleva el payload: solo
        // existe en el 201 de la emision.
        ->assertJsonMissingPath('qr_payload');
})->group('RF-QR-03');

it('no revoca dos veces', function (): void {
    // Sobrescribir la primera revocacion cambiaria el motivo y el momento que ya
    // constan en audit_log.
    $context = credentialsContext();

    Api::as($context['token'])->post('/api/v1/credentials', ['employee_uuid' => $context['employee']]);

    /** @var string $uuid */
    $uuid = DB::table('credentials')->value('uuid');

    Api::as($context['token'])->post('/api/v1/credentials/'.$uuid.'/revoke', ['reason' => 'Perdida']);

    Api::as($context['token'])
        ->post('/api/v1/credentials/'.$uuid.'/revoke', ['reason' => 'Otra vez'])
        ->assertValidResponse(409);
})->group('RF-QR-03');

it('exige un motivo que diga algo', function (string $reason): void {
    $context = credentialsContext();

    Api::as($context['token'])->post('/api/v1/credentials', ['employee_uuid' => $context['employee']]);

    /** @var string $uuid */
    $uuid = DB::table('credentials')->value('uuid');

    Api::as($context['token'])
        ->post('/api/v1/credentials/'.$uuid.'/revoke', ['reason' => $reason])
        ->assertValidResponse(422);
})->with([
    'vacio' => [''],
    'solo espacios' => ['     '],
])->group('RF-QR-03');

it('devuelve 404 al revocar una credencial que no existe', function (): void {
    $context = credentialsContext();

    Api::as($context['token'])
        ->post('/api/v1/credentials/0199f0d1-2a5b-7d4f-8c32-000000000000/revoke', ['reason' => 'Perdida'])
        ->assertValidResponse(404);
})->group('RF-QR-03');

it('no borra nada al revocar', function (): void {
    // Regla dura 5: la fila conserva su historia. Es lo que permite explicar
    // meses despues por que alguien no pudo fichar un martes.
    $context = credentialsContext();

    Api::as($context['token'])->post('/api/v1/credentials', ['employee_uuid' => $context['employee']]);

    /** @var string $uuid */
    $uuid = DB::table('credentials')->value('uuid');

    Api::as($context['token'])->post('/api/v1/credentials/'.$uuid.'/revoke', ['reason' => 'Perdida']);

    $row = DB::table('credentials')->where('uuid', $uuid)->first();

    expect($row)->not->toBeNull()
        ->and($row?->revoked_at)->not->toBeNull()
        ->and($row?->revoked_reason)->toBe('Perdida')
        ->and($row?->issued_at)->not->toBeNull();
})->group('RF-QR-03', 'RL-04');

it('un administrador tambien puede emitir', function (): void {
    // «rrhh+» del Anexo B incluye a `admin`.
    $site = WorkforceFixtures::site();
    $employee = WorkforceFixtures::employee($site);
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    Api::as($token)
        ->post('/api/v1/credentials', ['employee_uuid' => $employee])
        ->assertValidResponse(201);
})->group('RF-ID-02', 'RF-QR-01');

/*
 * El filtro `pending` del panel (RF-QR-08), tal y como lo serializa el contrato.
 *
 * El contrato declara `pending` como `schema: {type: boolean}` en la cadena de
 * consulta, y la serializacion estandar de OpenAPI para eso es el literal
 * `pending=true`. El cliente TypeScript de `@kronoqr/web-kit` la genera asi, y la
 * regla `boolean` de Laravel NO acepta esas cadenas: marcar la casilla «Solo
 * quien todavia no tiene la tarjeta en la mano» respondia `422`. La prueba que
 * existia mandaba `pending=1`, que si pasa, y por eso no lo veia nadie.
 */

/**
 * Dos personas: una con la tarjeta ya en la mano y otra sin nada.
 *
 * @return array{token: string, withCard: string, withoutCard: string}
 */
function credentialBoardContext(): array
{
    $site = WorkforceFixtures::site();
    $rrhh = ManagementUsers::withRole(UserRole::RRHH);

    $withCard = WorkforceFixtures::employee($site);
    $withoutCard = WorkforceFixtures::employee($site);

    $id = DB::table('employees')->where('uuid', $withCard)->value('id');

    Credentials::deliveredFor(is_numeric($id) ? (int) $id : 0, $rrhh->id);

    return ['token' => ManagementUsers::tokenFor($rrhh), 'withCard' => $withCard, 'withoutCard' => $withoutCard];
}

it('filtra con el `pending=true` que serializa el contrato', function (string $sent): void {
    $context = credentialBoardContext();

    Api::as($context['token'])
        ->get('/api/v1/credentials/status', ['pending' => $sent])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.employee_uuid', $context['withoutCard']);
})->with([
    // La forma que manda el cliente generado del contrato: la que fallaba.
    'literal de OpenAPI' => ['true'],
    // Las que ya funcionaban. Entran para que el arreglo no las rompa.
    'entero' => ['1'],
    // `filter_var` reconoce tambien las que escribe una persona a mano en la
    // barra de direcciones. Aceptarlas no afloja nada: ninguna es ambigua.
    'palabra' => ['yes'],
    'interruptor' => ['on'],
])->group('RF-QR-08');

it('no filtra con `pending=false`, ni con nada equivalente', function (string $sent): void {
    // Lo simetrico importa igual: si `false` se leyera como «hubo filtro», el
    // panel esconderia a quien SI tiene su tarjeta y la lista completa dejaria
    // de existir.
    $context = credentialBoardContext();

    Api::as($context['token'])
        ->get('/api/v1/credentials/status', ['pending' => $sent])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonCount(2, 'data');
})->with([
    'literal de OpenAPI' => ['false'],
    'entero' => ['0'],
    'palabra' => ['no'],
])->group('RF-QR-08');

it('sigue rechazando un `pending` que no es un booleano', function (string $garbage): void {
    // El arreglo normaliza lo que es reconociblemente booleano y NADA mas: un
    // filtro mal escrito tiene que doler, no colarse como `false` y devolver la
    // lista entera en silencio (mismo criterio que `rechaza un filtro que no
    // existe` en el listado de plantilla).
    $context = credentialBoardContext();

    Api::as($context['token'])
        ->get('/api/v1/credentials/status', ['pending' => $garbage])
        ->assertStatus(422);
})->with([
    'palabra inventada' => ['maybe'],
    'casi cierto' => ['truthy'],
    'numero cualquiera' => ['2'],
    // `?pending=` tampoco pasa, y queda fijado aqui a proposito. El contrato
    // declara `type: boolean` y la cadena vacia no lo es; ademas
    // `ConvertEmptyStringsToNull` la convierte en `null` antes de que este
    // `FormRequest` la vea, asi que no hay nada que normalizar. Quien construya
    // la peticion tiene que OMITIR el parametro para no filtrar, no mandarlo
    // vacio.
    'vacio' => [''],
])->group('RF-QR-08');

/*
 * El filtro `employee_uuid` del panel (RF-QR-08).
 *
 * La ficha de empleado enseña la fila de estado de la tarjeta de esa persona con
 * sus acciones. Sin este filtro habria que pedir el tablero del centro entero y
 * quedarse con una fila, lo que divulga —y audita como divulgada— toda la
 * plantilla del centro cada vez que alguien abre una ficha (ADR-037, RS-05).
 */

it('acota el panel a una sola persona', function (): void {
    $context = credentialBoardContext();

    Api::as($context['token'])
        ->get('/api/v1/credentials/status', ['employee_uuid' => $context['withoutCard']])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.employee_uuid', $context['withoutCard'])
        ->assertJsonPath('data.0.status', 'no_credential');
})->group('RF-QR-08');

it('deja el resumen del centro intacto aunque la fila sea una', function (): void {
    // Mismo criterio que `pending`: el numero que importa en la ficha sigue
    // siendo cuanta gente falta *de la que hay*, no «falta 1 de 1».
    $context = credentialBoardContext();

    Api::as($context['token'])
        ->get('/api/v1/credentials/status', ['employee_uuid' => $context['withoutCard']])
        ->assertValidResponse(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonCount(1, 'summary')
        ->assertJsonPath('summary.0.employees', 2)
        ->assertJsonPath('summary.0.without_delivered_credential', 1);
})->group('RF-QR-08');

it('combina `employee_uuid` con `site_id` con Y logico', function (): void {
    // Una persona que existe, pero no en el centro por el que se pregunta: la
    // respuesta es vacia, no la fila «colandose» por el otro filtro.
    $context = credentialBoardContext();
    $otroCentro = WorkforceFixtures::site('Hotel Vecino');

    Api::as($context['token'])
        ->get('/api/v1/credentials/status', [
            'employee_uuid' => $context['withoutCard'],
            'site_id' => $otroCentro,
        ])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonCount(0, 'data');
})->group('RF-QR-08');

it('combina `employee_uuid` con `pending` con Y logico', function (): void {
    $context = credentialBoardContext();

    Api::as($context['token'])
        ->get('/api/v1/credentials/status', [
            'employee_uuid' => $context['withCard'],
            'pending' => 'true',
        ])
        ->assertValidResponse(200)
        ->assertJsonCount(0, 'data');
})->group('RF-QR-08');

it('devuelve la lista vacia, y no un 404, para un UUID que no es de nadie', function (): void {
    // Este tablero no es un recurso por persona: es una consulta acotada, y una
    // consulta que no encuentra nada responde `200` con `data: []`. Un `404`
    // ademas convertiria el parametro en un oraculo de que UUID existen.
    $context = credentialBoardContext();

    Api::as($context['token'])
        ->get('/api/v1/credentials/status', ['employee_uuid' => Str::uuid7()->toString()])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonCount(0, 'data')
        ->assertJsonCount(1, 'summary');
})->group('RF-QR-08');

it('rechaza un `employee_uuid` que no tiene forma de UUID', function (string $garbage): void {
    $context = credentialBoardContext();

    Api::as($context['token'])
        ->get('/api/v1/credentials/status', ['employee_uuid' => $garbage])
        ->assertStatus(422);
})->with([
    'codigo de empleado' => ['E7K2M9QX4B'],
    'entero' => ['12'],
    'casi un uuid' => ['0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b9'],
    // `?employee_uuid=` tampoco pasa: `ConvertEmptyStringsToNull` lo deja en
    // `null` y `uuid` lo rechaza. Para no filtrar hay que OMITIR el parametro.
    'vacio' => [''],
])->group('RF-QR-08');

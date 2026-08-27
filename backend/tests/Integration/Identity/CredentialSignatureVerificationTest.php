<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\CredentialResolver;
use App\Modules\Identity\Domain\ValueObject\QrPayload;
use App\Modules\Identity\Infrastructure\Adapter\HmacSignatureVerifier;
use App\Modules\Shared\Domain\ValueObject\CredentialRejectionReason;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Identity\Credentials;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `HmacSignatureVerifier`: los seis pasos del §5.2 contra PostgreSQL de verdad
 * (RF-QR-01, RF-QR-02, RF-QR-03, RS-03, ADR-025).
 *
 * Integracion y no unitaria porque los pasos 4 y 5 —resolver la credencial por
 * el hash y comprobar el estado del empleado— son consultas, y lo que hay que
 * comprobar es el desenlace real, no que se llame a un doble.
 *
 * Estas pruebas ejercitan el adaptador **por el puerto que declara el nucleo**:
 * lo que se resuelve del contenedor es `CredentialResolver`. Si el enlace del
 * `IdentityServiceProvider` desapareciera, fallarian aqui y no en la tarea 1.4.
 */

uses(RefreshDatabase::class);

/**
 * @return array{site: int, employee: int, uuid: string}
 */
function verifierContext(string $status = 'active'): array
{
    $site = WorkforceFixtures::site();
    $uuid = WorkforceFixtures::employee($site, null, $status);

    /** @var int $employeeId */
    $employeeId = DB::table('employees')->where('uuid', $uuid)->value('id');

    return ['site' => $site, 'employee' => $employeeId, 'uuid' => $uuid];
}

function resolver(): CredentialResolver
{
    return app(CredentialResolver::class);
}

it('lo resuelve el adaptador que enlaza IdentityServiceProvider', function (): void {
    // ADR-025, restriccion 3: el enlace puerto -> adaptador se declara en el
    // proveedor del satelite. `Attendance` no sabe quien le sirve la credencial,
    // y esta prueba es lo que impide que el enlace desaparezca sin que nadie lo
    // note hasta el primer fichaje.
    expect(resolver())->toBeInstanceOf(HmacSignatureVerifier::class);
})->group('RF-QR-02');

it('resuelve una credencial valida al empleado que hay detras', function (): void {
    $context = verifierContext();
    $payload = Credentials::issueFor($context['employee']);

    $resolution = resolver()->resolve($payload->toString());

    expect($resolution->isResolved())->toBeTrue()
        ->and($resolution->employeeUuid())->toBe($context['uuid'])
        ->and($resolution->rejectionReason())->toBeNull();
})->group('RF-QR-01', 'RF-QR-02');

it('resuelve tambien las tarjetas firmadas con la clave anterior de un solape', function (): void {
    // §5.3, RF-QR-07: mientras dura la reimpresion progresiva, las dos claves
    // verifican. Sin esto habria que reimprimir toda la plantilla en un dia.
    $context = verifierContext();
    $payload = Credentials::issueFor($context['employee'], Credentials::previousKey());

    $resolution = resolver()->resolve($payload->toString());

    expect($resolution->isResolved())->toBeTrue()
        ->and($resolution->employeeUuid())->toBe($context['uuid']);
})->group('RF-QR-07');

it('rechaza un payload con la firma manipulada', function (): void {
    // Gherkin «QR falsificado» del doc 01 §11.
    $context = verifierContext();
    $payload = Credentials::issueFor($context['employee']);

    $resolution = resolver()->resolve(Credentials::tampered($payload)->toString());

    expect($resolution->isResolved())->toBeFalse()
        ->and($resolution->rejectionReason())->toBe(CredentialRejectionReason::INVALID_SIGNATURE);
})->group('RF-QR-02', 'RS-03');

it('rechaza un payload firmado con una clave que la instalacion no conoce', function (): void {
    // Paso 2 del §5.2. Es tambien lo que ocurre con una tarjeta cuya clave ya se
    // retiro al cerrar una rotacion.
    $resolution = resolver()->resolve(Credentials::signedWithUnknownKey()->toString());

    expect($resolution->rejectionReason())->toBe(CredentialRejectionReason::INVALID_SIGNATURE);
})->group('RF-QR-02', 'RF-QR-07', 'RS-03');

it('rechaza lo que no tiene la forma del §5.1', function (string $raw): void {
    // Paso 1. Y con el MISMO motivo interno que una firma invalida: el payload
    // no lo emitio este servidor, y es todo lo que se puede afirmar.
    expect(resolver()->resolve($raw)->rejectionReason())
        ->toBe(CredentialRejectionReason::INVALID_SIGNATURE);
})->with([
    'vacio' => [''],
    'texto cualquiera' => ['hola'],
    'otro prefijo' => ['FH2.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa'],
    'un JSON' => ['{"employee":"lucia"}'],
])->group('RF-QR-02', 'RS-03');

it('rechaza un token bien firmado que no corresponde a ninguna credencial', function (): void {
    // Paso 4. La firma verifica —la clave es la de la casa— pero no hay fila: es
    // una tarjeta que se emitio y cuya credencial ya no existe, o un payload
    // fabricado por alguien que tuviera la clave.
    verifierContext();

    $resolution = resolver()->resolve(Credentials::payloadFor()->toString());

    expect($resolution->rejectionReason())->toBe(CredentialRejectionReason::UNKNOWN);
})->group('RF-QR-02', 'RS-03');

it('rechaza una credencial revocada', function (): void {
    // Paso 5 y RF-QR-03: «la anterior deja de ser aceptada».
    $context = verifierContext();
    $payload = Credentials::issueFor($context['employee'], revokedReason: 'Perdida en el turno de noche');

    $resolution = resolver()->resolve($payload->toString());

    expect($resolution->rejectionReason())->toBe(CredentialRejectionReason::REVOKED);
})->group('RF-QR-03', 'RS-03');

it('rechaza la tarjeta de un empleado dado de baja', function (): void {
    // Paso 5. RN-14 hara que la baja revoque la credencial en la Fase 2; hasta
    // entonces —y tambien despues— esta comprobacion es la que impide que fiche.
    $context = verifierContext('terminated');
    $payload = Credentials::issueFor($context['employee']);

    $resolution = resolver()->resolve($payload->toString());

    expect($resolution->isResolved())->toBeFalse()
        ->and($resolution->rejectionReason())->toBe(CredentialRejectionReason::REVOKED);
})->group('RN-14', 'RS-03');

it('deja de aceptar la credencial anterior en cuanto se reemite', function (): void {
    // Gherkin «Reemision por perdida» del doc 01 §11.
    $context = verifierContext();

    $perdida = Credentials::issueFor($context['employee']);

    DB::table('credentials')->where('employee_id', $context['employee'])->update([
        'revoked_at' => '2026-08-20 21:14:02+00',
        'revoked_reason' => 'Perdida en el turno de noche',
    ]);

    $nueva = Credentials::issueFor($context['employee']);

    expect(resolver()->resolve($perdida->toString())->rejectionReason())
        ->toBe(CredentialRejectionReason::REVOKED)
        ->and(resolver()->resolve($nueva->toString())->employeeUuid())
        ->toBe($context['uuid']);
})->group('RF-QR-03');

it('registra el motivo real del rechazo sin escribir el token ni un nombre', function (): void {
    // Regla dura 21 y §5.2, paso 6: el detalle va al log del SERVIDOR, y el log
    // no puede llevar la tarjeta. Un `storage/logs` con payloads es un fajo de
    // credenciales validas en un fichero que se rota, se copia y viaja dentro
    // del paquete de diagnostico (ADR-020).
    $context = verifierContext();
    $payload = Credentials::issueFor($context['employee'], revokedReason: 'Perdida');

    /** @var list<array{message: string, context: array<string, mixed>}> $registrado */
    $registrado = [];

    Log::listen(static function (MessageLogged $mensaje) use (&$registrado): void {
        $registrado[] = ['message' => $mensaje->message, 'context' => $mensaje->context];
    });

    resolver()->resolve($payload->toString());

    expect($registrado)->not->toBeEmpty();

    $entrada = $registrado[0];

    expect($entrada['message'])->toBe('credential_rejected')
        // El vocabulario de `scan_events.result`, para poder cruzar log y tabla.
        ->and($entrada['context']['result'])->toBe('rejected_revoked')
        ->and($entrada['context']['employee_uuid'])->toBe($context['uuid']);

    $serializado = json_encode($registrado, JSON_THROW_ON_ERROR);

    expect($serializado)->not->toContain($payload->token)
        ->and($serializado)->not->toContain($payload->toString())
        ->and($serializado)->not->toContain($payload->signature)
        // Y ningun nombre: el empleado se identifica por su UUID y solo por el.
        ->and($serializado)->not->toContain('Persona')
        ->and($serializado)->not->toContain('De Prueba');
})->group('RS-03', 'RQ-07');

it('no acepta una tarjeta ajena reetiquetada con otro key_id', function (): void {
    // La firma cubre el key_id (§5.1). Sin eso, durante un solape se podria
    // reetiquetar una tarjeta y forzar la verificacion contra la otra clave.
    $context = verifierContext();
    $payload = Credentials::issueFor($context['employee'], Credentials::previousKey());

    $reetiquetado = QrPayload::of(
        Credentials::currentKey()->id,
        $payload->token,
        $payload->signature,
    );

    expect(resolver()->resolve($reetiquetado->toString())->rejectionReason())
        ->toBe(CredentialRejectionReason::INVALID_SIGNATURE);
})->group('RF-QR-02', 'RF-QR-07', 'RS-03');

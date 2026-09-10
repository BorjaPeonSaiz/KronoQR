<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\Port\AuditTrail;
use App\Modules\Compliance\Application\UseCase\VerifyAuditChain;
use App\Modules\Compliance\Domain\AuditChain;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditActionName;
use App\Modules\Compliance\Domain\ValueObject\AuditActor;
use App\Modules\Compliance\Domain\ValueObject\AuditChainBreakKind;
use App\Modules\Compliance\Domain\ValueObject\AuditEntryDraft;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use App\Modules\Compliance\Infrastructure\Persistence\AuditLogSchema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Time\Instants;

/*
 * COMPATIBILIDAD HACIA DELANTE DE LA CADENA DE AUDITORIA (RS-07, RL-04, RF-PD-10).
 *
 * EL DEFECTO QUE CIERRA ESTA SUITE, con nombre y fecha. Etapa ⑧b de la CI del
 * cierre de Fase 5, escenario U3 «vuelta atras»: tras deshacer una
 * actualizacion, la version ANTERIOR (2.1.0) queda corriendo sobre una base en
 * la que la version NUEVA ya escribio un asiento `system.restored_from_backup`.
 * Su `compliance:verify-audit-chain` reventaba con
 * `ValueError: "system.restored_from_backup" is not a valid backing value for
 * enum AuditAction`, porque la lectura de la fila hacia `AuditAction::from()`.
 * El verificador diario de RS-07 dejaba de correr por una accion que no era
 * ninguna rotura.
 *
 * LA DIRECCION DEL PROBLEMA ES ESTRUCTURAL. Las acciones nuevas las estrena
 * SIEMPRE la version siguiente, asi que un verificador antiguo tiene que poder
 * recorrer nombres que no conoce. Y puede, porque **la cadena se verifica por
 * hash, no por catalogo**: la formula del doc 02 §7.4 mete la accion como cadena
 * literal, de modo que el hash de una fila no depende de que este binario
 * reconozca su accion.
 *
 * POR QUE NO PODIA SER UNITARIA. Lo que hay que demostrar es que una fila que
 * **ninguna version de este codigo sabe escribir** se lee, se recalcula y sale
 * en verde de punta a punta: SQL directo → `DatabaseAuditChainReader` →
 * `AuditLogRow` → `AuditChain::hashFor()` → codigo de salida del comando. Un
 * doble en memoria se saltaria justo el trozo que fallaba. Las filas se insertan
 * **por SQL directo**, sin pasar por `DatabaseAuditTrail`, porque el producto no
 * tiene —ni debe tener— forma de escribir una accion fuera de su catalogo: la
 * escribio otro binario.
 */

uses(RefreshDatabase::class);

/** Instante fijo: la cadena no depende del reloj. El año es el de ADR-027, que siempre tiene particion. */
const FORWARD_AT = '2026-08-19 07:00:00';

/** El nombre que esta version no conoce, con la forma `sujeto.verbo` del §3.5. */
const FUTURE_ACTION = 'system.future_action';

/**
 * Un asiento normal, escrito por el producto, para que la cadena no arranque en
 * la fila rara: lo que se prueba es que la fila desconocida se ENGANCHA a lo que
 * ya habia.
 */
function forwardBaseEntry(): string
{
    /** @var AuditTrail $trail */
    $trail = app(AuditTrail::class);

    return $trail->append(new AuditEntryDraft(
        occurredAt: Instants::utc(FORWARD_AT),
        actor: AuditActor::device(1),
        action: AuditAction::ShiftEntryCreated,
        subject: AuditSubject::of('shift_entry', 1),
        payload: AuditPayload::of(['scan_id' => '0192f0c2-0000-7000-8000-000000000001']),
    ))->hash;
}

/**
 * Inserta por SQL directo la fila que escribiria la version SIGUIENTE.
 *
 * El hash se calcula con la formula real sobre el nombre literal, que es lo que
 * hara el binario que estrene la accion. Con `$hash` se fuerza otro valor para
 * el caso de manipulacion.
 *
 * @param  array<array-key, mixed>  $payload
 */
function insertFutureActionRow(string $previousHash, array $payload = ['to_version' => '2.2.0'], ?string $hash = null): string
{
    $draft = new AuditEntryDraft(
        occurredAt: Instants::utc(FORWARD_AT),
        actor: AuditActor::system(),
        action: AuditActionName::fromStorage(FUTURE_ACTION),
        subject: AuditSubject::of('installation'),
        payload: AuditPayload::of($payload),
    );

    $written = $hash ?? AuditChain::hashFor($draft, $previousHash);

    DB::table(AuditLogSchema::TABLE)->insert([
        'occurred_at' => $draft->occurredAt->format('Y-m-d H:i:s.uP'),
        'actor_type' => $draft->actor->type->value,
        'actor_id' => $draft->actor->id,
        'action' => FUTURE_ACTION,
        'subject_type' => $draft->subject->type,
        'subject_id' => $draft->subject->id,
        'payload' => $draft->payload->encode(),
        'prev_hash' => $previousHash,
        'hash' => $written,
        'ip' => null,
        'user_agent' => null,
    ]);

    return $written;
}

it('verifica en verde una fila con una accion que esta version no conoce', function (): void {
    $previous = forwardBaseEntry();
    insertFutureActionRow($previous);

    /** @var VerifyAuditChain $verify */
    $verify = app(VerifyAuditChain::class);
    $result = $verify->handle();

    expect($result->isIntact())->toBeTrue()
        ->and($result->rowsVerified)->toBe(2)
        ->and($result->failureCount())->toBe(0)
        // La accion no se pierde: se conserva como cadena y se nombra.
        ->and($result->unknownActions)->toBe([FUTURE_ACTION]);
})->group('RS-07', 'RL-04', 'RF-PD-10');

it('sale con codigo 0 y nombra la accion desconocida como aviso, no como error', function (): void {
    insertFutureActionRow(forwardBaseEntry());

    expect(Artisan::call('compliance:verify-audit-chain'))->toBe(0);

    $output = Artisan::output();

    expect($output)->toContain('Accion desconocida para esta version: '.FUTURE_ACTION)
        ->and($output)->toContain('no es una rotura')
        // El veredicto sigue siendo «integra»: un aviso no puede disfrazarse de
        // hallazgo, o la alerta de RS-07 sonaria en cada vuelta atras.
        ->and($output)->toContain('Cadena integra: 2 entradas verificadas.')
        ->and($output)->not->toContain('ROTURA DE LA CADENA');
})->group('RS-07', 'RL-04', 'RF-PD-10');

it('sigue detectando la manipulacion de una fila con accion desconocida', function (): void {
    // La contraparte imprescindible. Si tolerar el nombre significara dejar de
    // comprobar el contenido, bastaria con inventarse una accion para escribir
    // en el registro legal cualquier cosa sin que el verificador la mirase.
    $previous = forwardBaseEntry();

    // Formato valido -lo exige `audit_log_chk_hash_format`- pero no es el hash
    // de esta fila: es exactamente lo que dejaria un `UPDATE` de madrugada.
    insertFutureActionRow($previous, hash: hash('sha256', 'no es el hash de esta fila'));

    /** @var VerifyAuditChain $verify */
    $verify = app(VerifyAuditChain::class);
    $result = $verify->handle();

    expect($result->isIntact())->toBeFalse()
        ->and($result->failureCount())->toBe(1)
        ->and($result->breaks[0]->kind)->toBe(AuditChainBreakKind::ContentAltered)
        // Y la accion desconocida se sigue informando: las dos cosas son
        // independientes.
        ->and($result->unknownActions)->toBe([FUTURE_ACTION]);

    expect(Artisan::call('compliance:verify-audit-chain'))->toBe(1)
        ->and(Artisan::output())->toContain('ROTURA DE LA CADENA');
})->group('RS-07', 'RL-04');

it('deja la fila desconocida legible por las demas lecturas de audit_log', function (): void {
    // Ningun otro consumidor de la tabla puede reventar con la misma accion. El
    // recuento del paquete de diagnostico (RF-PD-11) y la exportacion integra
    // (RF-PD-12) la leen como cadena, sin pasar por el enum.
    insertFutureActionRow(forwardBaseEntry());

    /** @var object{action: string, total: int} $tally */
    $tally = DB::table(AuditLogSchema::TABLE)
        ->selectRaw('action, COUNT(*) AS total')
        ->where('action', FUTURE_ACTION)
        ->groupBy('action')
        ->firstOrFail();

    expect($tally->action)->toBe(FUTURE_ACTION)
        ->and((int) $tally->total)->toBe(1);
})->group('RS-07', 'RF-PD-10');

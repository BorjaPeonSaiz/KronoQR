<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Application\UseCase;

use App\Modules\Identity\Application\Command\IssueDeviceTokenCommand;
use App\Modules\Identity\Application\UseCase\IssueDeviceToken;
use App\Modules\Kiosk\Application\Command\ClaimPairingCommand;
use App\Modules\Kiosk\Application\Port\DeviceRegistry;
use App\Modules\Kiosk\Application\Port\KioskMetrics;
use App\Modules\Kiosk\Application\Port\PairingRequests;
use App\Modules\Kiosk\Application\Port\PairingSecrets;
use App\Modules\Kiosk\Application\Query\PairingClaimResult;
use App\Modules\Kiosk\Domain\Model\PairingRequest;
use App\Modules\Kiosk\Domain\ValueObject\ClaimOutcome;
use App\Modules\Kiosk\Domain\ValueObject\DeviceSummary;
use App\Modules\Kiosk\Domain\ValueObject\PairingStatus;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Support\ConstantTimeFloor;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Paso 3 de RF-PD-06: la tablet sondea y recoge su token.
 *
 * ## Es el unico sitio por el que sale el token de un quiosco
 *
 * Y sale **una sola vez**: el servidor guarda su hash y no puede volver a
 * enseñarlo. Si la tablet lo pierde, el camino es desvincular y volver a
 * emparejar.
 *
 * ## Tiempo constante: la misma tecnica que `HmacSignatureVerifier`
 *
 * Los tres rechazos —`pairing_id` desconocido, secreto que no coincide, y
 * solicitud caducada o ya consumida— tienen que ser indistinguibles entre si
 * (regla dura 17, RS-03), y eso exige tres cosas, no una:
 *
 * 1. **El mismo desenlace.** `PairingClaimResult::rejected()` no tiene ningun
 *    campo donde alojar la causa.
 * 2. **El mismo trabajo.** El `hash_equals` se ejecuta SIEMPRE, tambien cuando la
 *    fila no existe, contra el hash señuelo de `PairingSecrets::decoyHash()`. Un
 *    `return` temprano en el camino «no existe» seria un canal de tiempo medible
 *    y ademas un comprobador de `pairing_id`.
 * 3. **Un suelo de tiempo.** {@see ConstantTimeFloor}, el mismo objeto y el mismo
 *    umbral que usa la resolucion de credenciales del fichaje: los dos caminos
 *    tienen la misma obligacion y desde la tarea 5.6 ya no pueden divergir.
 *
 * **Lo que NO se promete** es que `Pending` y `Paired` tarden lo mismo que un
 * rechazo, y prometerlo seria mentir: emitir un token escribe en `audit_log`. El
 * suelo se aplica solo a los rechazos.
 *
 * ## El motivo real existe, y vive donde no lo ve quien sondea
 *
 * En el log del servidor y en `kiosk_pairing_total{result,reason}`, que
 * `/metrics` solo sirve a la red interna (§8.2). Sin ese dato, un pico de
 * rechazos no se distingue de un pico de tablets nuevas — y «alguien esta
 * probando secretos» se parece demasiado a «hoy se dan de alta cuatro quioscos».
 * **Nunca en la respuesta** (regla dura 17) y **nunca con datos personales**
 * (regla dura 21): un emparejamiento no conoce a nadie.
 *
 * ## Un solo uso bajo concurrencia
 *
 * `markClaimed()` escribe con `WHERE status = 'confirmed'`. Dos sondeos
 * simultaneos entran los dos y solo uno cambia una fila: el otro degrada a
 * rechazo **antes** de pedir ningun token. La marca va DELANTE de la emision a
 * proposito — al reves, los dos habrian emitido y habria dos tokens vivos para la
 * misma tablet.
 *
 * ## Llama a `Identity` por su caso de uso publico
 *
 * Doc 01 §5.5: la emision del token de un dispositivo «se expone a `Kiosk` por
 * caso de uso publico explicito», y el §1.6 concede la arista
 * `Kiosk/Application -> Identity/Application`. **Sin puerto invertido**: un
 * puerto declarado aqui e implementado alli añadiria una interfaz, un adaptador y
 * un `bind` para envolver una llamada que el mapa de modulos ya autoriza.
 *
 * ## Sin transaccion propia
 *
 * `IssueDeviceToken` abre la suya, y aqui no hay ninguna invariante que abarque
 * las dos escrituras: si la emision fallara despues de marcar la solicitud, la
 * tablet vuelve al paso 1 y pide otro codigo (regla dura 19). Envolverlas en una
 * transaccion externa habria alargado el bloqueo sobre `devices` durante la
 * escritura del asiento de auditoria de `Identity`, sin ganar nada.
 */
final readonly class ClaimPairing
{
    public function __construct(
        private PairingRequests $requests,
        private DeviceRegistry $devices,
        private PairingSecrets $secrets,
        private IssueDeviceToken $tokens,
        private KioskMetrics $metrics,
        private LoggerInterface $log,
        private Clock $clock,
        private ConstantTimeFloor $floor,
    ) {}

    public function handle(ClaimPairingCommand $command): PairingClaimResult
    {
        $startedAt = $this->floor->startedAt();
        $now = $this->clock->now();

        $found = $this->requests->findByPairingId($command->pairingId);

        // SIEMPRE, exista o no la fila. Ver el docblock: sin esto, «no existe»
        // costaria un `hash_equals` menos que «no es tuya».
        $secretMatches = $this->secrets->matches(
            $command->secret,
            $found['secret_hash'] ?? $this->secrets->decoyHash(),
        );

        // Y con una solicitud centinela cuando no hay fila, para que el agregado
        // decida por el mismo camino en los tres rechazos.
        $request = $found['request'] ?? $this->missingRequest($now);

        $outcome = $request->claim($now, $secretMatches);

        if ($outcome === ClaimOutcome::Pending) {
            return PairingClaimResult::pending();
        }

        if ($outcome === ClaimOutcome::Rejected) {
            return $this->rejected(
                $startedAt,
                $command->pairingId,
                $this->reasonOf($found, $secretMatches, $request, $now),
            );
        }

        // Confirmada: un solo uso. La escritura condicional decide quien gana.
        if (! $this->requests->markClaimed($request->uuid, $now)) {
            return $this->rejected($startedAt, $command->pairingId, 'race');
        }

        $device = $request->deviceId === null ? null : $this->devices->findById($request->deviceId);

        if (! $device instanceof DeviceSummary) {
            // No puede ocurrir: el CHECK de la tabla exige dispositivo en toda
            // solicitud confirmada. Si ocurriera —una fila tocada a mano— el
            // rechazo generico es la respuesta honesta, y la tablet pide otro
            // codigo en lugar de quedarse esperando (regla dura 19).
            return $this->rejected($startedAt, $command->pairingId, 'device_missing');
        }

        $token = $this->tokens->handle(new IssueDeviceTokenCommand(
            deviceUuid: $device->uuid,
            rotation: false,
            // El `claim` es anonimo: no hay persona detras de esta llamada. Quien
            // dio de alta el quiosco firma el asiento `device.provisioned` del
            // `confirm`; este queda con actor `system`, que es lo que es.
            actorUserId: null,
        ));

        if ($token === null) {
            // El dispositivo dejo de estar activo entre el `confirm` y este sondeo
            // —alguien lo desvinculo—. Rechazo generico y a empezar.
            //
            // Se compara con `null` y no con `instanceof IssuedAccessToken`: ese
            // tipo es de `Identity/Domain`, y la arista concedida por el §1.6
            // llega a `Identity/Application` y no mas alla. Nombrarlo aqui
            // ensancharia la frontera para ahorrarse una comparacion.
            return $this->rejected($startedAt, $command->pairingId, 'token_null');
        }

        $this->log->info('pairing_claimed', [
            'pairing_id' => $command->pairingId,
            'device_uuid' => $device->uuid,
        ]);
        $this->metrics->pairingClaimed();

        return PairingClaimResult::paired(
            deviceUuid: $device->uuid,
            deviceName: $device->name,
            token: $token->plainTextToken,
            tokenExpiresAt: $token->expiresAt,
        );
    }

    /**
     * El motivo interno del rechazo, **para el log y la metrica y para nada mas**.
     *
     * Se calcula DESPUES de que el agregado haya decidido, y sobre datos que ya
     * estan en memoria: si el camino se ramificara por causa para poder nombrarla,
     * esa ramificacion seria el canal de tiempo que todo lo demas evita.
     *
     * @param  array{request: PairingRequest, secret_hash: string}|null  $found
     */
    private function reasonOf(?array $found, bool $secretMatches, PairingRequest $request, DateTimeImmutable $now): string
    {
        if ($found === null) {
            return 'unknown';
        }

        if (! $secretMatches) {
            return 'secret';
        }

        if ($request->status === PairingStatus::Claimed) {
            return 'consumed';
        }

        return $request->hasExpiredAt($now) ? 'expired' : 'rejected';
    }

    /**
     * La solicitud que se usa cuando `pairing_id` no existe.
     *
     * Ya caducada y `pending`, de modo que el agregado la rechaza por el mismo
     * `match` que rechaza a una real caducada. Su `uuid` no se usa para nada: el
     * camino termina en el rechazo antes de tocar la base de datos.
     */
    private function missingRequest(DateTimeImmutable $now): PairingRequest
    {
        return PairingRequest::reconstitute(
            uuid: '00000000-0000-7000-8000-000000000000',
            status: PairingStatus::Pending,
            expiresAt: $now,
        );
    }

    private function rejected(float|int $startedAt, string $pairingId, string $reason): PairingClaimResult
    {
        // El motivo, aqui. En la respuesta, jamas.
        $this->log->warning('pairing_rejected', [
            'reason' => $reason,
            'pairing_id' => $pairingId,
        ]);
        $this->metrics->pairingRejected($reason);

        $this->floor->padTo($startedAt);

        return PairingClaimResult::rejected();
    }
}

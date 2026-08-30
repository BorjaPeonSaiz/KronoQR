<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

use App\Modules\Identity\Application\Command\RotateSigningKeyCommand;
use App\Modules\Identity\Application\Exception\SigningKeyRotationNotReady;
use App\Modules\Identity\Application\Port\CredentialRepository;
use App\Modules\Identity\Application\Port\IdentityEventPublisher;
use App\Modules\Identity\Application\Port\QrKeyProvider;
use App\Modules\Identity\Application\Support\CredentialTelemetry;
use App\Modules\Identity\Domain\Event\CredentialIssued;
use App\Modules\Identity\Domain\Event\SigningKeyRotated;
use App\Modules\Identity\Domain\Model\Credential;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\EmployeeRegistry;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * Abrir una rotacion de la clave de firma **sin dejar a nadie sin poder
 * fichar** (RF-QR-07, doc 02 §5.3).
 *
 * ## Que hace exactamente, y que no hace
 *
 * **No genera ninguna clave.** El operador ya ha puesto la nueva en
 * `QR_SIGNING_KEY_CURRENT` y ha movido la anterior a `QR_SIGNING_KEY_PREVIOUS`
 * (regla dura 13: las claves son configuracion del cliente, §7.7). Este caso de
 * uso **comprueba** que la configuracion esta en estado de solape y, si lo esta,
 * emite una credencial nueva por cada tarjeta que la clave saliente todavia
 * firma.
 *
 * **No invalida ninguna tarjeta vigente**, y esa es la razon de ser de toda la
 * funcionalidad. Las reemisiones nacen **pendientes de imprimir**, y una
 * credencial pendiente no tiene `key_id` ni hash: no la alcanza ningun escaneo
 * (ADR-034). Durante semanas conviven la tarjeta vieja —que sigue fichando con
 * la clave saliente— y el derecho a la nueva. La vieja se revoca sola cuando la
 * nueva se **entrega** ({@see DeliverCredential}), que es cuando la persona la
 * tiene en la mano y ya no necesita la anterior.
 *
 * ## Idempotente, y no por una comprobacion amable
 *
 * Repetir la orden no reemite dos veces porque el indice parcial
 * `one_pending_credential_per_employee` no admite dos credenciales pendientes
 * del mismo empleado. La seleccion se hace igualmente —se saltan las personas
 * que ya tienen una pendiente— para que el recuento del informe sea el real, no
 * para evitar el choque: si dos operadores lanzaran la rotacion a la vez, quien
 * decide es la base de datos.
 *
 * ## Una transaccion, con los asientos dentro
 *
 * Sesenta reemisiones y su `signing_key.rotated` son un solo hecho. Si el
 * asiento de una fallara, no se confirma ninguna (ADR-027): una rotacion a
 * medias es peor que una rotacion no empezada, porque el operador cree que ya
 * puede empezar a reimprimir y hay gente sin tarjeta nueva en la cola.
 */
final readonly class RotateSigningKey
{
    public function __construct(
        private QrKeyProvider $keys,
        private CredentialRepository $credentials,
        private EmployeeRegistry $employees,
        private IdentityEventPublisher $events,
        private Clock $clock,
        private ConnectionInterface $connection,
        private CredentialTelemetry $telemetry,
    ) {}

    /**
     * @throws SigningKeyRotationNotReady si la configuracion no esta en solape
     */
    public function handle(RotateSigningKeyCommand $command): SigningKeyRotationReport
    {
        $keyring = $this->keys->keyring();

        if (! $keyring->hasCurrent()) {
            throw SigningKeyRotationNotReady::noCurrentKey();
        }

        $currentKeyId = $keyring->current()->id;
        $retiringKeyId = $keyring->previousId();

        if ($retiringKeyId === null) {
            throw SigningKeyRotationNotReady::noPreviousKey();
        }

        $this->guardNoRotationLeftOpen($keyring->keyIds());

        $cards = $this->credentials->activeSignedWith($retiringKeyId);
        $pending = $this->employeesWithPendingCredential($cards);

        $toReissue = array_values(array_filter(
            $cards,
            static fn (Credential $card): bool => ! isset($pending[$card->employeeId]),
        ));

        $report = new SigningKeyRotationReport(
            retiringKeyId: $retiringKeyId,
            currentKeyId: $currentKeyId,
            cardsOnRetiringKey: \count($cards),
            reissued: \count($toReissue),
            alreadyPending: \count($cards) - \count($toReissue),
            dryRun: $command->dryRun,
        );

        if ($command->dryRun) {
            return $report;
        }

        return $this->telemetry->measure(
            'identity.signing_key_rotate',
            [
                'retiring_key_id' => $retiringKeyId,
                'current_key_id' => $currentKeyId,
                'cards' => $report->cardsOnRetiringKey,
                'reissued' => $report->reissued,
            ],
            fn (): SigningKeyRotationReport => $this->connection->transaction(
                fn (): SigningKeyRotationReport => $this->reissue($toReissue, $report, $command),
            ),
        );
    }

    /**
     * @param  list<Credential>  $cards
     */
    private function reissue(
        array $cards,
        SigningKeyRotationReport $report,
        RotateSigningKeyCommand $command,
    ): SigningKeyRotationReport {
        $now = $this->clock->now();

        foreach ($cards as $card) {
            $this->reissueOne($card, $now, $command->actorUserId);
        }

        $this->events->publish(new SigningKeyRotated(
            retiringKeyId: $report->retiringKeyId,
            currentKeyId: $report->currentKeyId,
            reissued: $report->reissued,
            alreadyPending: $report->alreadyPending,
            actorUserId: $command->actorUserId,
            occurredAt: $now,
        ));

        return $report;
    }

    /**
     * Una reemision es un acto **administrativo sin secreto** (ADR-034): crea el
     * derecho a una tarjeta nueva y nada mas. El token, la clave y el hash nacen
     * cuando esa credencial pase por la impresora, y entonces llevaran la clave
     * vigente en ese momento — que es justo lo que hace posible reimprimir a lo
     * largo de semanas sin volver a decidir nada.
     */
    private function reissueOne(Credential $card, DateTimeImmutable $now, ?int $actorUserId): void
    {
        $credential = Credential::issue(
            uuid: Str::uuid7()->toString(),
            employeeId: $card->employeeId,
            issuedAt: $now,
        );

        $credentialId = $this->credentials->add($credential);

        $this->events->publish(new CredentialIssued(
            credentialId: $credentialId,
            credentialUuid: $credential->uuid,
            // Sin UUID no habria a quien atribuir el asiento. La clave ajena de
            // `credentials.employee_id` hace que no pueda faltar.
            employeeUuid: $this->employees->uuidOf($card->employeeId) ?? '',
            // `credential.reissued` y no `credential.issued`: esta persona ya
            // tenia tarjeta, y quien lea el trail dentro de un año tiene que
            // poder distinguir una alta de un relevo por rotacion.
            reissue: true,
            actorUserId: $actorUserId,
            occurredAt: $now,
        ));
    }

    /**
     * Los empleados que ya tienen una credencial pendiente de imprimir.
     *
     * @param  list<Credential>  $cards
     * @return array<int, true> Indexado por `employee_id`.
     */
    private function employeesWithPendingCredential(array $cards): array
    {
        $employeeIds = array_values(array_unique(array_map(
            static fn (Credential $card): int => $card->employeeId,
            $cards,
        )));

        $pending = [];

        foreach ($this->credentials->pendingPrintForEmployees($employeeIds) as $credential) {
            $pending[$credential->employeeId] = true;
        }

        return $pending;
    }

    /**
     * Se niega si quedan tarjetas activas firmadas con una clave que ya no esta
     * en el llavero.
     *
     * Ese estado significa que una rotacion anterior nunca se cerro y que hay
     * gente que **no puede fichar ahora mismo**: sus firmas no verifican contra
     * ninguna clave conocida. Abrir otra rotacion encima solo añadiria tarjetas a
     * la cola de impresion sin resolver el problema, y ademas expulsaria del
     * llavero a la clave saliente actual, dejando sin fichar a un segundo grupo.
     *
     * @param  list<string>  $knownKeyIds
     *
     * @throws SigningKeyRotationNotReady
     */
    private function guardNoRotationLeftOpen(array $knownKeyIds): void
    {
        foreach ($this->credentials->activeCountsByKeyId() as $keyId => $cards) {
            if (! \in_array($keyId, $knownKeyIds, true)) {
                throw SigningKeyRotationNotReady::orphanedCards($keyId, $cards);
            }
        }
    }
}

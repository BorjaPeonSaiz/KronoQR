<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

use App\Modules\Identity\Application\Command\RetireSigningKeyCommand;
use App\Modules\Identity\Application\Exception\SigningKeyStillInUse;
use App\Modules\Identity\Application\Port\CredentialRepository;
use App\Modules\Identity\Application\Port\IdentityEventPublisher;
use App\Modules\Identity\Application\Port\QrKeyProvider;
use App\Modules\Identity\Application\Support\CredentialTelemetry;
use App\Modules\Identity\Domain\Event\SigningKeyRetired;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\InstallationSiteProvider;
use App\Modules\Shared\Domain\Exception\InstallationSiteMissing;
use App\Modules\Shared\Domain\ValueObject\InstallationSite;
use Illuminate\Database\ConnectionInterface;

/**
 * Cerrar el solape de una rotacion (RF-QR-07, doc 02 §5.3).
 *
 * **La condicion es una sola y no se negocia**: no queda ninguna credencial
 * activa firmada con esa clave. Mientras quede una, retirarla dejaria a esa
 * persona delante del quiosco con un rechazo generico que —correctamente, por
 * RS-03— no le explica nada. Por eso el caso de uso se **niega** y dice cuantas
 * faltan y en que centro, en lugar de avisar y seguir.
 *
 * **Aqui no se revoca nada.** Las tarjetas de la clave saliente se van revocando
 * solas a medida que se **entrega** su relevo ({@see DeliverCredential}), que es
 * el momento en que su titular ya tiene la nueva en la mano. Si este caso de uso
 * revocara las que quedan para poder cerrar, la comprobacion no valdria nada:
 * seria un boton que deja sin fichar a quien no haya pasado por RRHH.
 *
 * **Retirar la clave es un acto del operador, no de la aplicacion** (regla dura
 * 13). Lo que hace este caso de uso es **certificar** que ya se puede: comprueba
 * el recuento y sella el asiento `signing_key.retired`. Vaciar
 * `QR_SIGNING_KEY_PREVIOUS` lo hace despues quien opera el servidor, y el
 * comando se lo dice.
 */
final readonly class RetireSigningKey
{
    public function __construct(
        private QrKeyProvider $keys,
        private CredentialRepository $credentials,
        private IdentityEventPublisher $events,
        private InstallationSiteProvider $installation,
        private Clock $clock,
        private ConnectionInterface $connection,
        private CredentialTelemetry $telemetry,
    ) {}

    /**
     * @throws SigningKeyStillInUse mientras quede una tarjeta viva con esa clave
     * @throws InstallationSiteMissing antes de la puesta en marcha
     */
    public function handle(RetireSigningKeyCommand $command): SigningKeyRetirementReport
    {
        $keyring = $this->keys->keyring();

        if ($keyring->hasCurrent() && $keyring->current()->id === $command->keyId) {
            throw SigningKeyStillInUse::isCurrentKey($command->keyId);
        }

        $site = $this->installation->installationSite();

        if (! $site instanceof InstallationSite) {
            throw InstallationSiteMissing::make();
        }

        $active = $this->credentials->countActiveSignedWith($command->keyId);

        if ($active > 0) {
            throw SigningKeyStillInUse::withCards($command->keyId, $active, $site->name);
        }

        // Cuantas tarjetas llevo esa clave hasta el final. No condiciona nada:
        // esta en el asiento para que, dentro de dos años, la retirada se pueda
        // cruzar con las revocaciones de ese lote sin recorrer la tabla entera.
        $retired = $this->credentials->countSignedWith($command->keyId);

        return $this->telemetry->measure(
            'identity.signing_key_retire',
            ['key_id' => $command->keyId, 'signed_credentials' => $retired],
            fn (): SigningKeyRetirementReport => $this->connection->transaction(function () use (
                $command,
                $retired,
            ): SigningKeyRetirementReport {
                $this->events->publish(new SigningKeyRetired(
                    keyId: $command->keyId,
                    signedCredentials: $retired,
                    actorUserId: $command->actorUserId,
                    occurredAt: $this->clock->now(),
                ));

                return new SigningKeyRetirementReport($command->keyId, $retired);
            }),
        );
    }
}

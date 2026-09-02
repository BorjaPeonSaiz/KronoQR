<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

use App\Modules\Product\Application\Command\ActivateLicenseCommand;
use App\Modules\Product\Application\Port\LicenseRepository;
use App\Modules\Product\Application\Port\LicenseVerifier;
use App\Modules\Product\Application\Port\ProductEventPublisher;
use App\Modules\Product\Domain\Event\LicenseActivated;
use App\Modules\Product\Domain\Exception\InvalidLicenseKey;
use App\Modules\Product\Domain\Exception\LicenseKeyRejected;
use App\Modules\Product\Domain\ValueObject\LicenseStatus;
use App\Modules\Product\Domain\ValueObject\StoredLicense;
use App\Modules\Shared\Application\Port\Clock;
use Illuminate\Database\ConnectionInterface;

/**
 * Activa una clave de licencia: verifica, persiste y audita (RF-PD-04, RL-04).
 *
 * ## Aqui SI se lanza
 *
 * Al contrario que la lectura, que es tolerante y nunca falla, la activacion es
 * estricta: quien acaba de pegar una clave tiene que enterarse de que no vale, y
 * con cual de los cuatro motivos. Es la misma politica de la tarea 5.1
 * —**lectura tolerante, escritura estricta**— y por el mismo motivo: una
 * instalacion no puede quedarse a medias creyendo que activo algo.
 *
 * ## Una clave caducada SI se activa
 *
 * Y es deliberado. Un hotel que renueva con dos semanas de retraso recibe una
 * clave cuya vigencia empezo el dia 1; rechazarla porque «ya ha empezado» o
 * porque «ya ha terminado» obligaria a pedir otra. Lo que se guarda es lo que el
 * fabricante emitio, y el estado resultante —incluido `expired`— viaja en el
 * asiento para que conste que se activo asi.
 *
 * ## Transaccion y asiento
 *
 * El evento se publica **dentro** de la transaccion y el listener de
 * `Compliance` es sincrono: si el asiento falla, la activacion no se guarda
 * (ADR-027, regla dura 6). Una licencia activada sin traza deja sin respuesta
 * la pregunta comercial de «¿desde cuando tiene este cliente este plan?».
 *
 * ## Sin red
 *
 * No hay ninguna llamada saliente en este camino, ni aqui ni en el verificador
 * (ADR-018). Lo comprueba una prueba con el cliente HTTP simulado.
 */
final readonly class ActivateLicenseHandler
{
    public function __construct(
        private LicenseRepository $licenses,
        private LicenseVerifier $verifier,
        private ProductEventPublisher $events,
        private Clock $clock,
        private ConnectionInterface $connection,
        private int $expiryWarningDays,
    ) {}

    /**
     * @throws LicenseKeyRejected si la clave no verifica
     * @throws InvalidLicenseKey si verifica pero la carga util no sirve
     */
    public function handle(ActivateLicenseCommand $command): LicenseStatus
    {
        // Se normaliza antes de verificar: una clave copiada de un correo llega
        // con espacios y con saltos de linea, y rechazarla por eso seria
        // convertir un problema del portapapeles en una llamada de soporte.
        $signedKey = preg_replace('/\s+/', '', $command->signedKey) ?? '';

        $verification = $this->verifier->verify($signedKey);

        if (! $verification->isVerified()) {
            throw LicenseKeyRejected::because(
                $verification->rejection ?? throw new \LogicException('A rejected verification has a reason.'),
            );
        }

        $license = $verification->license;
        $now = $this->clock->now();
        $status = LicenseStatus::of($license, $now, $this->expiryWarningDays);

        $this->connection->transaction(function () use ($signedKey, $license, $now, $status, $command): void {
            $this->licenses->activate($signedKey, $license, $now, $command->actorUserId);
            $this->licenses->markVerified($now);

            $this->events->publish(new LicenseActivated(
                licenseId: $license->licenseId,
                // La huella y no la clave: el asiento acaba en el trail y en su
                // exportacion, y no hay razon para difundir ahi 400 caracteres
                // que ademas repiten el nombre del cliente.
                //
                // Se calcula en un SOLO sitio, compartido con el que la sirve el
                // recurso: es el valor con el que alguien confirma por telefono
                // que la clave activada es la que se envio, y dos copias del
                // calculo son dos huellas distintas el dia que una cambie.
                fingerprint: StoredLicense::fingerprintOf($signedKey),
                customerName: $license->customerName,
                plan: $license->plan,
                maxEmployees: $license->limits->maxEmployees,
                maxDevices: $license->limits->maxDevices,
                features: $license->featureNames(),
                validFrom: $license->validFrom,
                validUntil: $license->validUntil,
                resultingState: $status->state->value,
                actorUserId: $command->actorUserId,
                occurredAt: $now,
            ));
        });

        return $status;
    }
}

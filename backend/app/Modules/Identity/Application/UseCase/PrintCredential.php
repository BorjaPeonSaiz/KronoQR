<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

use App\Modules\Identity\Application\Command\PrintCredentialCommand;
use App\Modules\Identity\Application\Port\CredentialRepository;
use App\Modules\Identity\Application\Support\CredentialTelemetry;
use App\Modules\Identity\Domain\Exception\CredentialAlreadyPrinted;
use App\Modules\Identity\Domain\Exception\CredentialAlreadyRevoked;
use App\Modules\Identity\Domain\Model\Credential;
use App\Modules\Identity\Domain\ValueObject\CardFormat;
use App\Modules\Shared\Application\Port\EmployeeCardDirectory;
use App\Modules\Shared\Application\Port\EmployeeRegistry;
use App\Modules\Shared\Domain\ValueObject\EmployeeCardProfile;

/**
 * Imprimir **una** tarjeta, en formato tarjeta de credito (RF-QR-04, ADR-034).
 *
 * Este caso de uso resuelve **cual** es la credencial y delega el acto de acuñar
 * en {@see MintCards}, que es donde vive el orden de los seis pasos. La
 * separacion no es decorativa: la impresion individual y la del lote tienen que
 * hacer exactamente lo mismo con el token, y escrito dos veces acabaria
 * divergiendo.
 *
 * **Devuelve `null` si no hay nada que imprimir** —la credencial no existe, o el
 * empleado no tiene ninguna activa— y quien llama lo traduce a `404`. No se
 * emite una credencial sobre la marcha: emitir e imprimir son dos actos con dos
 * asientos, y fundirlos aqui haria que un `POST /print` sobre alguien sin tarjeta
 * le creara una en silencio.
 */
final readonly class PrintCredential
{
    public function __construct(
        private CredentialRepository $credentials,
        private EmployeeRegistry $employees,
        private EmployeeCardDirectory $directory,
        private MintCards $mint,
        private CredentialTelemetry $telemetry,
    ) {}

    /**
     * @throws CredentialAlreadyPrinted no hay reimpresion (ADR-034): `409`
     * @throws CredentialAlreadyRevoked imprimir una tarjeta retirada produciria un QR muerto
     */
    public function handle(PrintCredentialCommand $command): ?PrintedCards
    {
        $credential = $this->resolve($command);

        if (! $credential instanceof Credential) {
            return null;
        }

        $holder = $this->directory->profileFor($credential->employeeId);

        if (! $holder instanceof EmployeeCardProfile) {
            // La clave ajena lo impide y nada se borra, asi que no deberia
            // ocurrir. Si ocurriera, es preferible un `404` a imprimir una
            // tarjeta sin nombre: RF-QR-04 exige nombre, departamento y centro, y
            // esta credencial solo tiene un token que gastar.
            return null;
        }

        return $this->telemetry->measure(
            'identity.credential_print',
            [
                'credential_uuid' => $credential->uuid,
                'employee_uuid' => $holder->employeeUuid,
                'site_id' => $holder->siteId,
                'batch' => false,
            ],
            fn (): PrintedCards => $this->mint->mint(
                targets: [new CardToMint($credential, $holder)],
                format: CardFormat::CARD,
                batch: false,
                actorUserId: $command->actorUserId,
            ),
        );
    }

    /**
     * La credencial de la orden: por su UUID —la via del endpoint— o la del
     * empleado —la via de la consola—.
     *
     * **Por la via de la consola manda la pendiente de imprimir.** Durante una
     * rotacion con solape esa persona tiene dos activas: la tarjeta que lleva
     * encima, que ya esta impresa y no se puede reimprimir (ADR-034), y el
     * relevo que espera turno. `credentials:print {empleado}` se refiere
     * evidentemente al segundo. Fuera de una rotacion no hay diferencia, y
     * cuando no hay ninguna pendiente se devuelve la activa para que el error
     * siga siendo «esa tarjeta ya se imprimio» y no un `404` que no explica
     * nada.
     */
    private function resolve(PrintCredentialCommand $command): ?Credential
    {
        if ($command->credentialUuid !== null) {
            return $this->credentials->findByUuid($command->credentialUuid);
        }

        $employeeId = $this->employees->internalIdFor((string) $command->employeeUuid);

        if ($employeeId === null) {
            return null;
        }

        $pending = $this->credentials->pendingPrintForEmployees([$employeeId]);

        return $pending[0] ?? $this->credentials->activeForEmployee($employeeId);
    }
}

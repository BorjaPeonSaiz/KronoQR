<?php

declare(strict_types=1);

namespace Tests\Support\Attendance;

use App\Modules\Attendance\Application\Port\CredentialResolver;
use App\Modules\Shared\Domain\ValueObject\CredentialRejectionReason;
use App\Modules\Shared\Domain\ValueObject\CredentialResolution;

/**
 * Doble del puerto `CredentialResolver`.
 *
 * **Por que existe teniendo `Identity` su adaptador real.** Porque una prueba
 * del caso de uso del fichaje no debe fallar cuando cambie el esquema de firma:
 * lo que aqui se comprueba es que un escaneo abre o cierra un tramo, no que el
 * HMAC verifique. Ese es el punto de que `CredentialResolver` sea un puerto
 * (ADR-025) y esta es la segunda implementacion que lo justifica (doc 02 §3.5).
 *
 * El recorrido con el verificador real —`HmacSignatureVerifier` de la tarea
 * 1.5— se prueba aparte, con una credencial emitida de verdad: son dos
 * preguntas distintas y conviene que fallen por separado.
 *
 * **No imita el tiempo constante.** El adaptador real tiene esa obligacion
 * (RS-03, regla dura 17) y sus propias pruebas; un doble que lo fingiera solo
 * ralentizaria la suite sin comprobar nada.
 */
final class FakeCredentialResolver implements CredentialResolver
{
    /** @var array<string, CredentialResolution> */
    private array $byPayload = [];

    private CredentialResolution $fallback;

    /** @var list<string> */
    public array $resolvedPayloads = [];

    public function __construct()
    {
        // Lo que no se ha declarado no existe, que es el comportamiento seguro:
        // una prueba que se olvide de dar de alta su tarjeta ve el rechazo, no
        // un fichaje de nadie.
        $this->fallback = CredentialResolution::rejected(CredentialRejectionReason::UNKNOWN);
    }

    public static function new(): self
    {
        return new self;
    }

    /**
     * Ese payload es la tarjeta de ese empleado.
     */
    public function resolving(string $qrPayload, string $employeeUuid): self
    {
        $this->byPayload[$qrPayload] = CredentialResolution::resolved($employeeUuid);

        return $this;
    }

    /**
     * Ese payload se rechaza por el motivo indicado (RF-QR-02, RF-QR-03).
     */
    public function rejecting(string $qrPayload, CredentialRejectionReason $reason): self
    {
        $this->byPayload[$qrPayload] = CredentialResolution::rejected($reason);

        return $this;
    }

    public function resolve(string $qrPayload): CredentialResolution
    {
        $this->resolvedPayloads[] = $qrPayload;

        return $this->byPayload[$qrPayload] ?? $this->fallback;
    }
}

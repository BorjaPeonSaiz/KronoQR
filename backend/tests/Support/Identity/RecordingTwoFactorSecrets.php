<?php

declare(strict_types=1);

namespace Tests\Support\Identity;

use App\Modules\Identity\Application\Port\TwoFactorSecrets;
use DateTimeImmutable;
use SensitiveParameter;
use Tests\Support\Shared\RecordingPinAttempts;

/**
 * Espia del almacen del secreto TOTP: **delega en el real y apunta la secuencia**.
 *
 * Mismo papel y mismo motivo que {@see RecordingPinAttempts}
 * en el camino del PIN. La pregunta es la de RS-03 aplicada al segundo factor:
 * ¿cuesta lo mismo presentar un codigo contra una cuenta que todavia no ha dado de
 * alta su TOTP que contra una que si lo tiene? Si no cuesta lo mismo, un `401`
 * rapido señala la cuenta mas facil de atacar — la que cualquiera con la
 * contrasena puede darse de alta.
 *
 * **Decora en vez de sustituir**: un doble que devolviera siempre `null` no
 * ejercitaria las consultas, que es justo lo que se esta contando.
 *
 * Se apunta el **metodo, nunca el secreto** (regla dura 21): lo que se compara es
 * la forma del camino, no su contenido.
 */
final class RecordingTwoFactorSecrets implements TwoFactorSecrets
{
    /** @var list<string> */
    public array $calls = [];

    public function __construct(private readonly TwoFactorSecrets $inner) {}

    public function activeSecretFor(string $uuid): ?string
    {
        $this->calls[] = 'activeSecretFor';

        return $this->inner->activeSecretFor($uuid);
    }

    public function unconfirmedSecretFor(string $uuid): ?string
    {
        $this->calls[] = 'unconfirmedSecretFor';

        return $this->inner->unconfirmedSecretFor($uuid);
    }

    public function storeUnconfirmedSecret(string $uuid, #[SensitiveParameter] string $secret): void
    {
        $this->calls[] = 'storeUnconfirmedSecret';

        $this->inner->storeUnconfirmedSecret($uuid, $secret);
    }

    public function confirm(string $uuid, DateTimeImmutable $at): void
    {
        $this->calls[] = 'confirm';

        $this->inner->confirm($uuid, $at);
    }

    public function forget(string $uuid): void
    {
        $this->calls[] = 'forget';

        $this->inner->forget($uuid);
    }

    public function lastAcceptedSliceFor(string $uuid): ?int
    {
        $this->calls[] = 'lastAcceptedSliceFor';

        return $this->inner->lastAcceptedSliceFor($uuid);
    }

    public function rememberAcceptedSlice(string $uuid, int $slice): void
    {
        $this->calls[] = 'rememberAcceptedSlice';

        $this->inner->rememberAcceptedSlice($uuid, $slice);
    }

    /**
     * Vacia lo apuntado y devuelve la secuencia observada hasta ahora.
     *
     * @return list<string>
     */
    public function drain(): array
    {
        $calls = $this->calls;

        $this->calls = [];

        return $calls;
    }
}

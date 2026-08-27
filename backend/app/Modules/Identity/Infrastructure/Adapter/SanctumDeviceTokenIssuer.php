<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Adapter;

use App\Modules\Identity\Application\Port\DeviceRepository;
use App\Modules\Identity\Application\Port\DeviceTokenIssuer;
use App\Modules\Identity\Domain\Model\Device as DeviceEntity;
use App\Modules\Identity\Domain\ValueObject\DeviceTokenLifetime;
use App\Modules\Identity\Domain\ValueObject\IssuedAccessToken;
use App\Modules\Identity\Domain\ValueObject\TokenAbility;
use App\Modules\Identity\Infrastructure\Persistence\Device;
use DateTimeImmutable;
use Laravel\Sanctum\PersonalAccessToken;
use RuntimeException;

/**
 * El token de un quiosco, sobre Laravel Sanctum (RF-ID-04, RS-04, doc 02 §7.3).
 *
 * **Tres decisiones que sostienen la promesa del §7.3.**
 *
 * 1. **Los ambitos salen de {@see TokenAbility::kioskAbilities()} y no de una
 *    lista escrita aqui.** Es lo unico que impide que un token de quiosco acabe
 *    con `employees:*` el dia que alguien copie y pegue el emisor de sesiones.
 * 2. **Emitir retira lo anterior.** Se borran los tokens que ese dispositivo
 *    tuviera antes de crear el nuevo: una tablet emparejada dos veces con dos
 *    tokens vivos deja uno funcionando cuando se revoca el otro.
 * 3. **El hash se copia a `devices.token_hash` en la misma operacion.** Sanctum
 *    guarda su propio hash en `personal_access_tokens.token`; la copia existe
 *    para que la fila del dispositivo se explique sola (ver el modelo). Los dos
 *    son SHA-256 del mismo valor, asi que no pueden discrepar en contenido, solo
 *    en existencia — y por eso se escriben juntos.
 *
 * **El token en claro no se registra en ningun sitio.** Sale dentro de
 * {@see IssuedAccessToken}, viaja en la respuesta que lo entrega y se olvida.
 */
final readonly class SanctumDeviceTokenIssuer implements DeviceTokenIssuer
{
    public function __construct(private DeviceRepository $devices) {}

    public function issueFor(DeviceEntity $device, DateTimeImmutable $issuedAt, DateTimeImmutable $expiresAt): IssuedAccessToken
    {
        $row = $this->rowOf($device);

        $this->deleteTokensOf($row);

        $token = $row->createToken(
            name: 'kiosk:'.$device->uuid,
            abilities: array_map(
                static fn (TokenAbility $ability): string => $ability->value,
                TokenAbility::kioskAbilities(),
            ),
            expiresAt: $expiresAt,
        );

        // Mismo algoritmo que usa Sanctum para su columna `token`.
        $this->devices->storeTokenHash($device->id, hash('sha256', $this->secretOf($token->plainTextToken)));

        return new IssuedAccessToken($token->plainTextToken, $expiresAt);
    }

    public function revokeAllFor(DeviceEntity $device): void
    {
        $this->deleteTokensOf($this->rowOf($device));
    }

    public function currentLifetime(DeviceEntity $device, float $rotationThreshold): ?DeviceTokenLifetime
    {
        $row = $this->rowOf($device);

        /** @var PersonalAccessToken|null $token */
        $token = $row->tokens()->latest('id')->first();

        if (! $token instanceof PersonalAccessToken || $token->expires_at === null) {
            // Un token sin caducidad no existe en este producto: si aparece
            // uno, no se puede decir cuando esta al 80 % de su vida y no se
            // rota. Rotar por si acaso retiraria un token valido.
            return null;
        }

        return new DeviceTokenLifetime(
            issuedAt: $token->created_at?->toDateTimeImmutable() ?? $token->expires_at->toDateTimeImmutable(),
            expiresAt: $token->expires_at->toDateTimeImmutable(),
            rotationThreshold: $rotationThreshold,
        );
    }

    private function rowOf(DeviceEntity $device): Device
    {
        $row = Device::query()->whereKey($device->id)->first();

        if (! $row instanceof Device) {
            // Solo puede ocurrir si el dispositivo desaparece entre que el caso
            // de uso lo lee y emite su token. No se degrada a «token sin dueno».
            throw new RuntimeException('El dispositivo ha dejado de existir mientras se emitia su token.');
        }

        return $row;
    }

    private function deleteTokensOf(Device $row): void
    {
        $row->tokens()->delete();
    }

    /**
     * La mitad secreta de un token de Sanctum: `<id>|<secreto>`.
     *
     * Se hashea solo el secreto porque es lo que Sanctum guarda en su columna.
     * Hashear la cadena entera daria un valor distinto y `devices.token_hash`
     * dejaria de poder cotejarse con `personal_access_tokens.token`, que es toda
     * su razon de ser.
     */
    private function secretOf(string $plainTextToken): string
    {
        $separator = strpos($plainTextToken, '|');

        return $separator === false ? $plainTextToken : substr($plainTextToken, $separator + 1);
    }
}

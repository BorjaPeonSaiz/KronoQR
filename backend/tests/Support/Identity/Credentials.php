<?php

declare(strict_types=1);

namespace Tests\Support\Identity;

use App\Modules\Identity\Domain\ValueObject\CredentialSecret;
use App\Modules\Identity\Domain\ValueObject\QrPayload;
use App\Modules\Identity\Domain\ValueObject\QrSigningKey;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Credenciales y payloads para las pruebas de la tarea 1.5.
 *
 * **Escribe con el constructor de consultas, sin pasar por el caso de uso.** Es
 * deliberado: las pruebas de esquema y las de tiempo constante tienen que poder
 * crear filas que el dominio no crearia —una credencial ya revocada, dos activas
 * del mismo empleado— y comprobar quien las rechaza.
 *
 * Las claves salen de la configuracion de la suite (`phpunit.xml`), no de una
 * constante escrita aqui: si el llavero del proceso y el de la prueba
 * divergieran, la prueba verificaria firmas contra una clave que el codigo no
 * usa y pasaria sin comprobar nada.
 */
final class Credentials
{
    public static function currentKey(): QrSigningKey
    {
        return QrSigningKey::fromBase64(
            Config::string('identity.credentials.signing_keys.current.id'),
            Config::string('identity.credentials.signing_keys.current.secret'),
        );
    }

    public static function previousKey(): QrSigningKey
    {
        return QrSigningKey::fromBase64(
            Config::string('identity.credentials.signing_keys.previous.id'),
            Config::string('identity.credentials.signing_keys.previous.secret'),
        );
    }

    /**
     * Un token aleatorio valido, firmado con la clave indicada.
     */
    public static function payloadFor(?QrSigningKey $key = null): QrPayload
    {
        return ($key ?? self::currentKey())->sign(self::freshSecret()->value);
    }

    public static function freshSecret(): CredentialSecret
    {
        return CredentialSecret::fromBytes(random_bytes(CredentialSecret::ENTROPY_BYTES));
    }

    /**
     * Inserta una credencial **ya impresa** para ese empleado y devuelve el
     * payload de su tarjeta.
     *
     * Impresa y no solo emitida a proposito: desde ADR-034 el QR se acuña al
     * imprimir, asi que una credencial pendiente de imprimir no tiene hash y no
     * la resuelve ningun escaneo. Lo que estas pruebas necesitan es una tarjeta
     * que exista de verdad. Para el otro estado esta {@see self::pendingFor()}.
     *
     * @param  string|null  $revokedReason  Si se indica, la credencial nace ya revocada.
     */
    public static function issueFor(
        int $employeeId,
        ?QrSigningKey $key = null,
        ?string $revokedReason = null,
    ): QrPayload {
        $key ??= self::currentKey();
        $secret = self::freshSecret();

        DB::table('credentials')->insert([
            'uuid' => Str::uuid7()->toString(),
            'employee_id' => $employeeId,
            'key_id' => $key->id,
            'secret_hash' => $secret->hash(),
            'issued_at' => '2026-08-14 06:00:00+00',
            'printed_at' => '2026-08-14 06:00:00+00',
            'revoked_at' => $revokedReason === null ? null : '2026-08-15 06:00:00+00',
            'revoked_reason' => $revokedReason,
        ]);

        return $key->sign($secret->value);
    }

    /**
     * Inserta una credencial **pendiente de imprimir**: sin `key_id`, sin hash y
     * sin `printed_at`.
     *
     * Es el estado en el que nace toda credencial desde ADR-034, y el que el
     * panel de RF-QR-08 cuenta como «todavia no puede fichar».
     *
     * @return string El UUID publico de la credencial.
     */
    public static function pendingFor(int $employeeId): string
    {
        $uuid = Str::uuid7()->toString();

        DB::table('credentials')->insert([
            'uuid' => $uuid,
            'employee_id' => $employeeId,
            'issued_at' => '2026-08-14 06:00:00+00',
        ]);

        return $uuid;
    }

    /**
     * Inserta una credencial **ya entregada**: impresa y con la entrega firmada.
     *
     * Es el unico estado en el que el panel de RF-QR-08 deja de contar a esa
     * persona como «todavia no puede fichar», asi que es el que hace falta para
     * probar el filtro `pending`. Se escribe en una sola sentencia y no por la
     * API porque imprimir de verdad arranca un Chromium: lo que estas pruebas
     * necesitan es el estado, no el PDF.
     *
     * `delivered_by_user_id` es obligatorio: `credentials_chk_delivery_is_signed`
     * no admite una entrega sin responsable (RF-QR-06).
     *
     * @return string El UUID publico de la credencial.
     */
    public static function deliveredFor(int $employeeId, int $deliveredByUserId): string
    {
        $uuid = Str::uuid7()->toString();
        $key = self::currentKey();

        DB::table('credentials')->insert([
            'uuid' => $uuid,
            'employee_id' => $employeeId,
            'key_id' => $key->id,
            'secret_hash' => self::freshSecret()->hash(),
            'issued_at' => '2026-08-14 06:00:00+00',
            'printed_at' => '2026-08-15 06:00:00+00',
            'delivered_at' => '2026-08-16 06:00:00+00',
            'delivered_by_user_id' => $deliveredByUserId,
        ]);

        return $uuid;
    }

    /**
     * Un payload con la firma manipulada en un solo caracter.
     *
     * Cambiar un caracter y no la firma entera es el caso que importa: con una
     * comparacion que no fuera de tiempo constante, un atacante iria acertandola
     * byte a byte sin conocer la clave.
     */
    public static function tampered(QrPayload $payload): QrPayload
    {
        $first = $payload->signature[0] === 'A' ? 'B' : 'A';

        return QrPayload::of($payload->keyId, $payload->token, $first.substr($payload->signature, 1));
    }

    /**
     * Un payload bien formado firmado con una clave que la instalacion no
     * conoce.
     */
    public static function signedWithUnknownKey(): QrPayload
    {
        return QrSigningKey::fromBase64('zz', base64_encode('clave-que-esta-instalacion-nunc'.'a'))
            ->sign(self::freshSecret()->value);
    }
}

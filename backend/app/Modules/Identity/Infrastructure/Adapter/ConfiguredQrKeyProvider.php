<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Adapter;

use App\Modules\Identity\Application\Port\QrKeyProvider;
use App\Modules\Identity\Domain\Exception\InvalidSigningKey;
use App\Modules\Identity\Domain\ValueObject\QrKeyring;
use App\Modules\Identity\Domain\ValueObject\QrSigningKey;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * Las claves HMAC, leidas de la configuracion de la instalacion (doc 02 Anexo B
 * y §7.7).
 *
 * ```
 * QR_SIGNING_KEY_CURRENT_ID=a3      QR_SIGNING_KEY_CURRENT=<32 bytes en base64>
 * QR_SIGNING_KEY_PREVIOUS_ID=a2     QR_SIGNING_KEY_PREVIOUS=<32 bytes en base64>
 * ```
 *
 * **Nada especifico de un cliente entra en el codigo** (regla dura 13,
 * ADR-017): las claves las genera el instalador en el servidor de cada cliente y
 * no se transmiten (§7.7). Aqui no hay ningun valor por defecto —una clave por
 * defecto seria la misma en todas las instalaciones, que es peor que no tener
 * ninguna—.
 *
 * **Una clave mal configurada no revienta el fichaje.** Se registra un error y
 * esa clave sencillamente no entra en el llavero: los escaneos firmados con ella
 * se rechazan de forma generica (RS-03) como cualquier otro. Que la aplicacion
 * dejara de responder al escanear seria un modo de fallo peor y ademas
 * informativo para quien esta probando.
 *
 * El llavero se memoriza en la instancia: leer y decodificar dos claves en cada
 * escaneo no aporta nada, y el proceso se recicla en cada peticion.
 */
final class ConfiguredQrKeyProvider implements QrKeyProvider
{
    private ?QrKeyring $keyring = null;

    public function keyring(): QrKeyring
    {
        return $this->keyring ??= QrKeyring::of(
            $this->key('current'),
            $this->key('previous'),
        );
    }

    private function key(string $slot): ?QrSigningKey
    {
        $id = Config::string('identity.credentials.signing_keys.'.$slot.'.id', '');
        $secret = Config::string('identity.credentials.signing_keys.'.$slot.'.secret', '');

        // Ausente es lo normal para `previous` fuera de una rotacion: no es un
        // error y no se registra nada.
        if ($id === '' || $secret === '') {
            return null;
        }

        try {
            return QrSigningKey::fromBase64($id, $secret);
        } catch (InvalidSigningKey $exception) {
            // El mensaje lleva el `key_id` y el motivo. Nunca la clave.
            Log::error('qr_signing_key_invalid', [
                'slot' => $slot,
                'key_id' => $id,
                'reason' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}

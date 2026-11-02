<?php

declare(strict_types=1);

namespace KronoQR\DiagnosticsOpener;

use App\Modules\Product\Domain\Support\CanonicalJson;
use Throwable;

/*
 * La forma canonica es la del producto, no una copia: si las dos dejaran de
 * coincidir, ningun sobre volveria a abrir. Dentro de la suite del backend la
 * clase ya esta cargada por su autoloader; aqui, que no hay ninguno, se carga
 * del arbol del repositorio. `CanonicalJson` es dominio puro (regla dura 1).
 */
if (! class_exists(CanonicalJson::class)) {
    require_once __DIR__.'/../../../backend/app/Modules/Product/Domain/Support/CanonicalJson.php';
}

/**
 * Abre el paquete de diagnostico con datos personales que una instalacion
 * cifro para la clave de soporte del fabricante (RL-19; ADR-020, enmienda del
 * 01-11-2026).
 *
 * **Herramienta del fabricante.** No viaja en ninguna imagen (`.dockerignore`
 * excluye `tools/`) y nada en el producto abre un sobre: la instalacion solo
 * tiene la clave publica.
 *
 * ## Lo que comprueba, en orden
 *
 * 1. Que es un sobre de esta version (`format`, `envelope_version`,
 *    `algorithm`, campos y base64). Si no, {@see InvalidEnvelope}.
 * 2. Que hay una clave privada para su `recipient_key_id` y que abre
 *    `sealed_key` (`crypto_box_seal_open`).
 * 3. Que el texto cifrado se descifra con la cabecera como datos asociados:
 *    cambiar `anonymized`, la lista de secciones o un recuento, o cortar el
 *    fichero, hace que no descifre.
 * 4. Que el manifiesto descifrado es el de la cabecera, que la huella del texto
 *    en claro sale integra y que la descripcion de `contents` es la del
 *    contenido.
 *
 * Cualquier fallo de 2 a 4 es {@see EnvelopeNotOpened}. Lo que no hace: no
 * autentica a quien lo genero. Cualquiera con la clave publica puede fabricar
 * un sobre; la referencia es el asiento `diagnostics.bundle_generated` del
 * cliente, con la misma huella.
 */
final class DiagnosticsOpener
{
    public const string FORMAT = 'kronoqr-diagnostics-sealed';

    public const int ENVELOPE_VERSION = 1;

    public const string ALGORITHM = 'x25519-sealedbox+xchacha20poly1305-ietf';

    /** Los tres campos que no son cabecera. */
    private const array ENVELOPE_FIELDS = ['sealed_key', 'nonce', 'ciphertext'];

    /**
     * Un par X25519 nuevo, en hexadecimal.
     *
     * @return array{public: string, secret: string, key_id: string}
     */
    public static function generateKeyPair(): array
    {
        $pair = sodium_crypto_box_keypair();
        $public = sodium_crypto_box_publickey($pair);
        $secret = sodium_crypto_box_secretkey($pair);
        sodium_memzero($pair);

        $result = [
            'public' => bin2hex($public),
            'secret' => bin2hex($secret),
            'key_id' => self::keyId($public),
        ];

        sodium_memzero($secret);

        return $result;
    }

    /** Los 16 primeros hexadecimales del SHA-256 de la clave publica en bruto. */
    public static function keyId(string $publicKey): string
    {
        return substr(hash('sha256', $publicKey), 0, 16);
    }

    /**
     * Un llavero desde una clave privada en hexadecimal.
     *
     * @return array<string, string> `key_id` => clave privada en bruto
     */
    public static function keyringFromSecretHex(string $secretHex): array
    {
        $secretHex = trim($secretHex);

        if (preg_match('/^[0-9a-fA-F]{64}$/D', $secretHex) !== 1) {
            return [];
        }

        $secret = (string) hex2bin($secretHex);

        return [self::keyId(sodium_crypto_box_publickey_from_secretkey($secret)) => $secret];
    }

    /**
     * El llavero de un directorio: cada `*.key` con una clave privada en
     * hexadecimal. Los ficheros que no lo son se ignoran.
     *
     * @return array<string, string> `key_id` => clave privada en bruto
     */
    public static function keyringFromDirectory(string $directory): array
    {
        $keyring = [];

        foreach (glob(rtrim($directory, '/').'/*.key') ?: [] as $file) {
            $content = @file_get_contents($file);

            if (\is_string($content)) {
                $keyring += self::keyringFromSecretHex($content);
            }
        }

        return $keyring;
    }

    /**
     * Abre un sobre.
     *
     * @param  array<string, string>  $keyring  `key_id` => clave privada en bruto
     * @return array{plaintext: string, key_id: string, manifest: array<array-key, mixed>}
     *
     * @throws InvalidEnvelope
     * @throws EnvelopeNotOpened
     */
    public static function open(string $json, array $keyring): array
    {
        $envelope = self::envelope($json);
        $keyId = $envelope['header']['recipient_key_id'];

        if (! isset($keyring[$keyId])) {
            throw new EnvelopeNotOpened('No tienes la clave privada '.$keyId.' en el llavero: este paquete va cifrado para ella.');
        }

        $plaintext = self::decrypt($envelope, $keyring[$keyId]);
        $bundle = json_decode($plaintext, true);

        if (! \is_array($bundle) || ! \is_array($bundle['manifest'] ?? null)) {
            throw new EnvelopeNotOpened('Lo descifrado no es un paquete de diagnostico.');
        }

        self::assertConsistent($envelope['header'], $bundle, $plaintext);

        return ['plaintext' => $plaintext, 'key_id' => $keyId, 'manifest' => $bundle['manifest']];
    }

    /**
     * El sobre leido y validado en su forma, sin abrir.
     *
     * @return array{header: array{recipient_key_id: string, manifest: array<array-key, mixed>, contents: array<array-key, mixed>}&array<string, mixed>, sealed_key: string, nonce: string, ciphertext: string}
     *
     * @throws InvalidEnvelope
     */
    private static function envelope(string $json): array
    {
        $document = json_decode($json, true);

        if (! \is_array($document) || array_is_list($document)) {
            throw new InvalidEnvelope('El fichero no es un JSON con forma de objeto.');
        }

        if (($document['format'] ?? null) !== self::FORMAT
            || ($document['envelope_version'] ?? null) !== self::ENVELOPE_VERSION
            || ($document['algorithm'] ?? null) !== self::ALGORITHM) {
            throw new InvalidEnvelope('No es un paquete cifrado de esta version (format, envelope_version o algorithm).');
        }

        $binary = [];

        foreach (self::ENVELOPE_FIELDS as $field) {
            $value = \is_string($document[$field] ?? null) ? base64_decode($document[$field], true) : false;

            if ($value === false || $value === '') {
                throw new InvalidEnvelope('El campo '.$field.' falta o no es base64.');
            }

            $binary[$field] = $value;
            unset($document[$field]);
        }

        $keyId = $document['recipient_key_id'] ?? null;

        if (! \is_string($keyId) || preg_match('/^[0-9a-f]{16}$/D', $keyId) !== 1
            || ! \is_array($document['manifest'] ?? null) || ! \is_array($document['contents'] ?? null)
            || \strlen($binary['nonce']) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
            throw new InvalidEnvelope('La cabecera no tiene la forma esperada (recipient_key_id, manifest, contents o nonce).');
        }

        /** @var array{recipient_key_id: string, manifest: array<array-key, mixed>, contents: array<array-key, mixed>}&array<string, mixed> $document */
        return ['header' => $document, ...$binary];
    }

    /**
     * @param  array{header: array<string, mixed>, sealed_key: string, nonce: string, ciphertext: string}  $envelope
     *
     * @throws EnvelopeNotOpened
     */
    private static function decrypt(array $envelope, string $secret): string
    {
        $keypair = sodium_crypto_box_keypair_from_secretkey_and_publickey(
            $secret,
            sodium_crypto_box_publickey_from_secretkey($secret),
        );

        try {
            $key = sodium_crypto_box_seal_open($envelope['sealed_key'], $keypair);
        } catch (Throwable) {
            $key = false;
        } finally {
            sodium_memzero($keypair);
        }

        if (! \is_string($key)) {
            throw new EnvelopeNotOpened('La clave privada no abre este sobre: no es la suya, o el sobre esta dañado.');
        }

        try {
            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                $envelope['ciphertext'],
                CanonicalJson::encode($envelope['header']),
                $envelope['nonce'],
                $key,
            );
        } catch (Throwable) {
            $plaintext = false;
        } finally {
            sodium_memzero($key);
        }

        if (! \is_string($plaintext)) {
            throw new EnvelopeNotOpened(
                'No se descifra: la cabecera o el texto cifrado no son los que se sellaron (alterados o cortados).'
            );
        }

        return $plaintext;
    }

    /**
     * Lo descifrado es lo que dice la cabecera: el mismo manifiesto, la huella
     * integra y la misma descripcion de `personal_data`.
     *
     * @param  array<string, mixed>  $header
     * @param  array<array-key, mixed>  $bundle
     *
     * @throws EnvelopeNotOpened
     */
    private static function assertConsistent(array $header, array $bundle, string $plaintext): void
    {
        $manifest = $bundle['manifest'];

        if (! \is_array($manifest) || ! \is_array($header['manifest'] ?? null)
            || CanonicalJson::encode($manifest) !== CanonicalJson::encode($header['manifest'])) {
            throw new EnvelopeNotOpened('El manifiesto descifrado no es el de la cabecera.');
        }

        $sealed = $manifest['sha256'] ?? null;
        unset($manifest['sha256']);
        $bundle['manifest'] = $manifest;

        if (! \is_string($sealed) || ! hash_equals($sealed, CanonicalJson::digest($bundle))) {
            throw new EnvelopeNotOpened('La huella del paquete descifrado no coincide con la de su manifiesto.');
        }

        $contents = $header['contents'] ?? null;
        $periodDays = \is_array($contents) && \is_int($contents['period_days'] ?? null) ? $contents['period_days'] : 0;

        if (! \is_array($contents)
            || CanonicalJson::encode($contents) !== CanonicalJson::encode(self::describe($bundle, $periodDays, \strlen($plaintext)))) {
            throw new EnvelopeNotOpened('La cabecera no describe lo que lleva el paquete (contents).');
        }
    }

    /**
     * La misma descripcion que escribe el producto
     * (`SealedDiagnosticsBundle::contentsOf`), recalculada sobre lo descifrado.
     *
     * @param  array<array-key, mixed>  $bundle
     * @return array<string, mixed>
     */
    private static function describe(array $bundle, int $periodDays, int $plaintextBytes): array
    {
        $contents = ['period_days' => $periodDays, 'plaintext_bytes' => $plaintextBytes, 'status' => 'unavailable'];
        $section = $bundle['personal_data'] ?? null;

        if (! \is_array($section)) {
            return $contents;
        }

        if (($section['status'] ?? null) === 'omitted') {
            return [...$contents, 'status' => 'omitted'];
        }

        $collections = [];

        foreach ($section as $name => $collection) {
            if (\is_array($collection) && \is_array($collection['items'] ?? null)) {
                $collections[(string) $name] = self::columns($collection['items']);
            }
        }

        return $collections === [] ? $contents : [...$contents, 'status' => 'included', 'collections' => $collections];
    }

    /**
     * @param  array<array-key, mixed>  $items
     * @return array{rows: int, columns: list<string>}
     */
    private static function columns(array $items): array
    {
        $columns = [];

        foreach ($items as $item) {
            foreach (\is_array($item) ? array_keys($item) : [] as $column) {
                $columns[(string) $column] = true;
            }
        }

        return ['rows' => \count($items), 'columns' => array_map(strval(...), array_keys($columns))];
    }
}

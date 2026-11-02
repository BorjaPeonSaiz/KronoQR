<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

use App\Modules\Product\Domain\Support\CanonicalJson;

/**
 * El paquete de diagnostico **con datos personales**, cifrado para el
 * fabricante (contrato `SealedDiagnosticsBundle`, **RL-19**, RF-PD-09; ADR-020,
 * enmienda del 01-11-2026).
 *
 * ## Un sobre hibrido, y por que no un solo `crypto_box_seal`
 *
 * El paquete entero va cifrado con XChaCha20-Poly1305 bajo una clave de un solo
 * uso, y esa clave va en un sobre sellado X25519 para la clave publica de
 * soporte del fabricante. Con un unico sobre sellado del texto entero, la
 * cabecera en claro —lo unico que el cliente puede leer— no estaria atada a
 * nada: se podria cambiar `anonymized` o la lista de secciones sin que el sobre
 * dejara de abrir. Aqui la cabecera son los **datos asociados** del cifrado:
 * cambiar un solo campo hace que no abra.
 *
 * ## La cabecera es lo que el cliente revisa en lugar del contenido
 *
 * Que secciones lleva, cuantas filas y que columnas de cada coleccion de
 * `personal_data`, y la huella del texto en claro, que es la misma de sus dos
 * asientos en `audit_log`. El cliente renuncia a abrir el contenido —la
 * instalacion no tiene la clave privada—, y lo compensan esa cabecera, el
 * asiento con la huella y que esos datos ya los ve en su panel.
 *
 * ## Lo que no hace
 *
 * No autentica a quien lo genera: cualquiera con la clave publica puede
 * fabricar un sobre. La referencia de que salio de esta instalacion es su
 * asiento en `audit_log`, no el sobre.
 *
 * Este objeto solo **describe** el sobre. Cifrar es de la infraestructura
 * (`SodiumDiagnosticsBundleSealer`), y abrirlo, del fabricante
 * (`tools/diagnostics-opener/`): nada en el producto abre un sobre.
 */
final readonly class SealedDiagnosticsBundle
{
    public const string FORMAT = 'kronoqr-diagnostics-sealed';

    public const int ENVELOPE_VERSION = 1;

    public const string ALGORITHM = 'x25519-sealedbox+xchacha20poly1305-ietf';

    /** El tipo de la respuesta HTTP (contrato, `200`). */
    public const string MEDIA_TYPE = 'application/vnd.kronoqr.diagnostics-sealed+json';

    /**
     * Margen para la cabecera al repartir el tope de tamaño: el manifiesto, los
     * recuentos y los nombres de columna ocupan unos pocos KiB.
     */
    private const int HEADER_MARGIN_BYTES = 16 * 1024;

    /** Los tres campos que no son cabecera: no entran en los datos asociados. */
    private const array ENVELOPE_FIELDS = ['sealed_key', 'nonce', 'ciphertext'];

    /**
     * @param  array{period_days: int, plaintext_bytes: int, status: string, collections?: array<string, array{rows: int, columns: list<string>}>}  $contents
     * @param  string  $sealedKey  La clave del contenido en un sobre X25519, en base64.
     * @param  string  $nonce  Los 24 bytes del nonce, en base64.
     * @param  string  $ciphertext  El texto cifrado con su etiqueta, en base64.
     */
    public function __construct(
        public DiagnosticsManifest $manifest,
        public string $recipientKeyId,
        public array $contents,
        public string $sealedKey,
        public string $nonce,
        public string $ciphertext,
    ) {}

    /**
     * La cabecera: todo el documento menos `sealed_key`, `nonce` y
     * `ciphertext`. Su forma canonica son los datos asociados del cifrado.
     *
     * @param  array{period_days: int, plaintext_bytes: int, status: string, collections?: array<string, array{rows: int, columns: list<string>}>}  $contents
     * @return array<string, mixed>
     */
    public static function header(DiagnosticsManifest $manifest, string $recipientKeyId, array $contents): array
    {
        return [
            'format' => self::FORMAT,
            'envelope_version' => self::ENVELOPE_VERSION,
            'algorithm' => self::ALGORITHM,
            'recipient_key_id' => $recipientKeyId,
            'manifest' => $manifest->toArray(),
            'contents' => $contents,
        ];
    }

    /**
     * Los datos asociados del cifrado: la cabecera en forma canonica.
     *
     * Canonica y no `json_encode` a secas por lo mismo que la huella: quien abre
     * el sobre la recalcula sobre el fichero **releido**, y un `json_decode` de
     * ida y vuelta no conserva ni el orden de las claves ni los espacios.
     *
     * @param  array<array-key, mixed>  $header
     */
    public static function associatedData(array $header): string
    {
        foreach (self::ENVELOPE_FIELDS as $field) {
            unset($header[$field]);
        }

        return CanonicalJson::encode($header);
    }

    /**
     * Lo que lleva `personal_data`, **sin ningun valor**: cuantas filas y que
     * columnas de cada coleccion, y si la seccion entro.
     *
     * Ninguna coleccion vacia se describe como mapa vacio: un `{}` releido por
     * `json_decode` vuelve como lista y cambiaria los datos asociados. Por eso
     * `collections` solo aparece con la seccion dentro, y la seccion siempre
     * lleva sus cuatro colecciones.
     *
     * @return array{period_days: int, plaintext_bytes: int, status: string, collections?: array<string, array{rows: int, columns: list<string>}>}
     */
    public static function contentsOf(DiagnosticsBundle $bundle, int $periodDays, int $plaintextBytes): array
    {
        $contents = [
            'period_days' => $periodDays,
            'plaintext_bytes' => $plaintextBytes,
            'status' => 'unavailable',
        ];

        $section = $bundle->sections['personal_data'] ?? null;

        if (! is_array($section)) {
            return $contents;
        }

        if (($section['status'] ?? null) === 'omitted') {
            return [...$contents, 'status' => 'omitted'];
        }

        $collections = [];

        foreach ($section as $name => $collection) {
            if (is_array($collection) && is_array($collection['items'] ?? null)) {
                $collections[(string) $name] = self::describe($collection['items']);
            }
        }

        if ($collections === []) {
            return $contents;
        }

        return [...$contents, 'status' => 'included', 'collections' => $collections];
    }

    /**
     * El tope del texto en claro para que el fichero que sale quepa en el de
     * `PRODUCT_DIAGNOSTICS_MAX_BYTES`: el base64 lo infla en 4/3, y la cabecera
     * se lleva su margen.
     */
    public static function plaintextBudget(int $maxBytes): int
    {
        return max(1, intdiv($maxBytes * 3, 4) - self::HEADER_MARGIN_BYTES);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            ...self::header($this->manifest, $this->recipientKeyId, $this->contents),
            'sealed_key' => $this->sealedKey,
            'nonce' => $this->nonce,
            'ciphertext' => $this->ciphertext,
        ];
    }

    /**
     * El fichero que sale: indentado como el anonimizado, para que la cabecera
     * se lea con `less`. El texto cifrado es una sola linea.
     */
    public function toJson(): string
    {
        return json_encode(
            $this->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
        )."\n";
    }

    /**
     * SHA-256 de los bytes que se entregan (`sealed_sha256` de los asientos).
     * La huella del texto en claro es `manifest.sha256`.
     */
    public function sha256(): string
    {
        return hash('sha256', $this->toJson());
    }

    public function fileName(): string
    {
        return $this->manifest->sealedFileName();
    }

    /**
     * Si un documento releido del disco es un sobre (`--verify`). Solo mira la
     * forma, no lo abre.
     *
     * @param  array<array-key, mixed>  $document
     */
    public static function looksSealed(array $document): bool
    {
        return ($document['format'] ?? null) === self::FORMAT;
    }

    /**
     * El identificador de clave de un sobre releido del disco, si tiene la
     * forma de esta version del sobre (`--verify`); nulo si no. Solo la forma:
     * no lo abre.
     *
     * @param  array<array-key, mixed>  $document
     */
    public static function wellFormedKeyId(array $document): ?string
    {
        if (! self::looksSealed($document) || ($document['envelope_version'] ?? null) !== self::ENVELOPE_VERSION) {
            return null;
        }

        foreach (['recipient_key_id', ...self::ENVELOPE_FIELDS] as $field) {
            if (! \is_string($document[$field] ?? null)) {
                return null;
            }
        }

        /** @var string $keyId */
        $keyId = $document['recipient_key_id'];

        return $keyId;
    }

    /**
     * @param  array<array-key, mixed>  $items
     * @return array{rows: int, columns: list<string>}
     */
    private static function describe(array $items): array
    {
        $columns = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            foreach (array_keys($item) as $column) {
                $columns[(string) $column] = true;
            }
        }

        return ['rows' => \count($items), 'columns' => array_map(strval(...), array_keys($columns))];
    }
}

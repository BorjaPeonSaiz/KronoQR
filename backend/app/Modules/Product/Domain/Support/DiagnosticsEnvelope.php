<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Support;

/**
 * La forma del sobre del paquete de diagnostico con datos personales, escrita
 * **una sola vez** para quien lo cierra y quien lo abre (**RL-19**, RF-PD-09;
 * ADR-020, enmienda del 01-11-2026).
 *
 * La usan el producto —`SealedDiagnosticsBundle` y
 * `SodiumDiagnosticsBundleSealer`— y el abridor del fabricante
 * (`tools/diagnostics-opener/`), que la carga del arbol del repositorio igual
 * que {@see CanonicalJson}: el abridor no viaja en ninguna imagen ni tiene
 * autoloader. Antes cada lado tenia su copia del formato, del identificador de
 * clave y de la descripcion de `personal_data`; si dejaran de coincidir, ningun
 * sobre volveria a abrir, o el abridor rechazaria una cabecera correcta.
 *
 * **Funciones puras sobre arrays y cadenas**, sin criptografia: cifrar es de la
 * infraestructura y abrir, del fabricante. Dominio puro (regla dura 1), y por
 * eso el abridor puede cargarla sin nada mas que `CanonicalJson`.
 */
final readonly class DiagnosticsEnvelope
{
    public const string FORMAT = 'kronoqr-diagnostics-sealed';

    public const int ENVELOPE_VERSION = 1;

    public const string ALGORITHM = 'x25519-sealedbox+xchacha20poly1305-ietf';

    /** Los tres campos que no son cabecera: no entran en los datos asociados. */
    public const array ENVELOPE_FIELDS = ['sealed_key', 'nonce', 'ciphertext'];

    /** Longitud del identificador de clave, en hexadecimales. */
    public const int KEY_ID_LENGTH = 16;

    /**
     * El identificador de una clave publica X25519 en bruto: los
     * {@see self::KEY_ID_LENGTH} primeros hexadecimales de su SHA-256. Es el
     * `recipient_key_id` de la cabecera y el nombre de la clave en el llavero
     * del fabricante.
     */
    public static function keyId(string $publicKey): string
    {
        return substr(hash('sha256', $publicKey), 0, self::KEY_ID_LENGTH);
    }

    /**
     * Los datos asociados del cifrado: la cabecera —el documento sin
     * {@see self::ENVELOPE_FIELDS}— en forma canonica.
     *
     * Canonica y no `json_encode` a secas por lo mismo que la huella: quien abre
     * el sobre la recalcula sobre el fichero **releido**, y un `json_decode` de
     * ida y vuelta no conserva ni el orden de las claves ni los espacios.
     *
     * @param  array<array-key, mixed>  $document
     */
    public static function associatedData(array $document): string
    {
        foreach (self::ENVELOPE_FIELDS as $field) {
            unset($document[$field]);
        }

        return CanonicalJson::encode($document);
    }

    /**
     * Lo que lleva `personal_data`, **sin ningun valor**: cuantas filas y que
     * columnas de cada coleccion, y si la seccion entro.
     *
     * `$sections` son las secciones del paquete en claro: las de
     * `DiagnosticsBundle` al cerrarlo, o el paquete descifrado entero al
     * abrirlo (lleva ademas `manifest`, que aqui no se mira).
     *
     * Ninguna coleccion vacia se describe como mapa vacio: un `{}` releido por
     * `json_decode` vuelve como lista y cambiaria los datos asociados. Por eso
     * `collections` solo aparece con la seccion dentro, y la seccion siempre
     * lleva sus cuatro colecciones.
     *
     * @param  array<array-key, mixed>  $sections
     * @return array{period_days: int, plaintext_bytes: int, status: string, collections?: array<string, array{rows: int, columns: list<string>}>}
     */
    public static function contents(array $sections, int $periodDays, int $plaintextBytes): array
    {
        $contents = [
            'period_days' => $periodDays,
            'plaintext_bytes' => $plaintextBytes,
            'status' => 'unavailable',
        ];

        $section = $sections['personal_data'] ?? null;

        if (! \is_array($section)) {
            return $contents;
        }

        if (($section['status'] ?? null) === 'omitted') {
            return [...$contents, 'status' => 'omitted'];
        }

        $collections = [];

        foreach ($section as $name => $collection) {
            if (\is_array($collection) && \is_array($collection['items'] ?? null)) {
                $collections[(string) $name] = self::describe($collection['items']);
            }
        }

        if ($collections === []) {
            return $contents;
        }

        return [...$contents, 'status' => 'included', 'collections' => $collections];
    }

    /**
     * @param  array<array-key, mixed>  $items
     * @return array{rows: int, columns: list<string>}
     */
    private static function describe(array $items): array
    {
        $columns = [];

        foreach ($items as $item) {
            if (! \is_array($item)) {
                continue;
            }

            foreach (array_keys($item) as $column) {
                $columns[(string) $column] = true;
            }
        }

        return ['rows' => \count($items), 'columns' => array_map(strval(...), array_keys($columns))];
    }
}

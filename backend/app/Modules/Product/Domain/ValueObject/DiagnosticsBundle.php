<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

use DateTimeImmutable;

/**
 * El paquete de diagnostico (contrato `DiagnosticsBundle`, **RF-PD-09**,
 * doc 02 §11.6.6, ADR-020).
 *
 * ## Un solo fichero JSON, legible y sin cifrar
 *
 * Sin cifrar **a proposito**: la ficha 5.9 exige que el cliente inspeccione el
 * paquete antes de enviarlo, y un fichero cifrado se lo impediria. El canal de
 * envio es el del contrato de soporte del cliente y vive fuera del producto. La
 * integridad la da la huella del manifiesto, no un sobre.
 *
 * ## El tope de tamaño y a que se renuncia primero
 *
 * Un paquete de 300 MB no llega a soporte: lo corta el servidor de correo del
 * hotel, o el portal de tickets, y el cliente descubre el problema cuando ya
 * lleva dos dias esperando respuesta. Por eso hay tope
 * (`PRODUCT_DIAGNOSTICS_MAX_BYTES`, 8 MiB de serie).
 *
 * Y por eso **el orden de sacrificio esta escrito y es fijo**: se renuncia
 * primero a lo que menos falta hace para diagnosticar. `manifest`,
 * `installation`, `configuration`, `services`, `doctor` y `license` **no se
 * sacrifican nunca**: son las seis secciones que responden a las primeras
 * preguntas de cualquier incidencia, y un paquete sin ellas no sirve para nada.
 *
 * Lo omitido no desaparece en silencio: queda `{"status": "omitted", "reason":
 * "size_limit"}` con el tamaño que ocupaba, para que soporte sepa que existe y
 * pueda pedirlo aparte.
 */
final readonly class DiagnosticsBundle
{
    /** Clave de la constancia de asiento pendiente dentro de la seccion `audit`. */
    public const string GENERATION_ENTRY = 'generation_entry';

    /**
     * Las secciones que puede llevar el paquete, sin el manifiesto (contrato
     * `DiagnosticsBundle`). Contra esta lista se valida un pendiente releido
     * del disco.
     *
     * @var list<string>
     */
    public const array SECTIONS = [
        'installation',
        'configuration',
        'services',
        'doctor',
        'license',
        'kiosks',
        'error_events',
        'metrics',
        'updates',
        'audit',
        'personal_data',
    ];

    /**
     * Orden en el que se renuncia a las secciones cuando el paquete no cabe.
     *
     * De la que menos aporta a la que mas. `personal_data` va primero porque es
     * con diferencia la mas voluminosa —y porque, si no cabe, lo correcto es
     * pedir un periodo mas corto y no recortar el diagnostico tecnico.
     *
     * @var list<string>
     */
    private const array SACRIFICE_ORDER = [
        'personal_data',
        'audit',
        'metrics',
        'updates',
        'error_events',
        'kiosks',
    ];

    /**
     * @param  array<string, array<array-key, mixed>>  $sections  Secciones en el orden del contrato.
     */
    private function __construct(
        public DiagnosticsManifest $manifest,
        public array $sections,
    ) {}

    /**
     * Compone el paquete: recorta hasta el tope, calcula la huella del resto y
     * la sella en el manifiesto.
     *
     * **La huella se calcula sobre el documento YA recortado**, que es el que
     * viaja. Calcularla antes daria una huella que nunca verificaria.
     *
     * @param  array<string, array<array-key, mixed>>  $sections
     */
    public static function of(
        string $productVersion,
        DateTimeImmutable $generatedAt,
        bool $anonymized,
        DiagnosticsActor $generatedBy,
        array $sections,
        int $maxBytes,
    ): self {
        return self::seal($productVersion, $generatedAt, $anonymized, $generatedBy, self::fit($sections, $maxBytes));
    }

    /**
     * El mismo paquete, vuelto a sellar con la constancia de que su asiento en
     * `audit_log` queda pendiente (ADR-020, enmienda del 14-10-2026).
     *
     * La constancia va en la seccion `audit` y **dentro de la huella**: quien
     * recibe el paquete sabe que el asiento aun no existe. Quitarla sin
     * recalcular la huella hace fallar `--verify`; la huella no lleva clave, asi
     * que la referencia fiable es el `sha256` del asiento
     * `diagnostics.bundle_generated` en `audit_log`. No va en el manifiesto, cuyo esquema es cerrado.
     *
     * No se vuelve a recortar: la constancia ocupa unas decenas de bytes y
     * recortar ahora podria sacrificar una seccion que ya se habia decidido
     * conservar. La huella se recalcula sobre el documento resultante.
     */
    public function withGenerationEntryPending(string $reason): self
    {
        $sections = $this->sections;
        $audit = $sections['audit'] ?? [];
        $audit[self::GENERATION_ENTRY] = ['status' => 'pending', 'reason' => $reason];
        $sections['audit'] = $audit;

        return self::seal(
            $this->manifest->productVersion,
            $this->manifest->generatedAt,
            $this->manifest->anonymized,
            $this->manifest->generatedBy,
            $sections,
        );
    }

    /** Si el asiento de este paquete quedo pendiente al generarlo. */
    public function generationEntryPending(): bool
    {
        return isset($this->sections['audit'][self::GENERATION_ENTRY]);
    }

    /**
     * El documento entero, listo para escribir.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['manifest' => $this->manifest->toArray(), ...$this->sections];
    }

    /**
     * El documento como fichero: JSON indentado, con salto final.
     *
     * **Indentado y no compacto**, aunque ocupe mas: lo tiene que leer una
     * persona con `less` para comprobar que no lleva nada suyo antes de
     * enviarlo. La huella no se calcula sobre esto sino sobre la forma canonica,
     * asi que el formato de lectura no ata nada.
     *
     * **`JSON_PRESERVE_ZERO_FRACTION` si ata, y por eso esta.** Sin el, un `2.0`
     * se escribe como `2`, al releerlo vuelve como entero y la forma canonica
     * cambia: `--verify` denunciaria como alterado un paquete intacto. Es el
     * unico detalle del formato de lectura que no es cosmetico.
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
     * Comprueba la huella de un paquete releido del disco
     * (`product:diagnostics --verify`).
     *
     * @param  array<string, mixed>  $document  El JSON decodificado tal cual.
     */
    public static function digestMatches(array $document): bool
    {
        return self::checkDigest($document)->matches();
    }

    /**
     * La huella segun la version del paquete (PR16). La `2` cubre el
     * documento entero salvo la huella; la `1`, anterior a la 2.3.0, solo las
     * secciones, y se sigue comprobando asi para no denunciar como alterado
     * un paquete viejo intacto.
     *
     * @param  array<string, mixed>  $document  El JSON decodificado tal cual.
     */
    public static function checkDigest(array $document): DiagnosticsDigestCheck
    {
        $manifest = $document['manifest'] ?? null;

        if (! is_array($manifest) || ! is_string($manifest['sha256'] ?? null)) {
            return DiagnosticsDigestCheck::Mismatch;
        }

        $sealed = $manifest['sha256'];

        return match ($manifest['schema_version'] ?? null) {
            DiagnosticsManifest::SCHEMA_VERSION => self::withoutDigest($document, $manifest, $sealed),
            DiagnosticsManifest::SECTIONS_ONLY_SCHEMA_VERSION => self::sectionsOnly($document, $sealed),
            default => DiagnosticsDigestCheck::Mismatch,
        };
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  array<array-key, mixed>  $manifest
     */
    private static function withoutDigest(array $document, array $manifest, string $sealed): DiagnosticsDigestCheck
    {
        unset($manifest['sha256']);
        $document['manifest'] = $manifest;

        return hash_equals($sealed, CanonicalJson::digest($document))
            ? DiagnosticsDigestCheck::Intact
            : DiagnosticsDigestCheck::Mismatch;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private static function sectionsOnly(array $document, string $sealed): DiagnosticsDigestCheck
    {
        unset($document['manifest']);

        return hash_equals($sealed, CanonicalJson::digest($document))
            ? DiagnosticsDigestCheck::IntactWithoutManifest
            : DiagnosticsDigestCheck::Mismatch;
    }

    /**
     * Pone la huella en el manifiesto.
     *
     * **La huella cubre el documento entero salvo ella misma** (PR16): las
     * secciones y el manifiesto sin su `sha256`. Antes cubria solo las
     * secciones, y cambiar `"anonymized": false` por `true` en un paquete con
     * datos personales seguia verificando como integro: quien lo recibia lo
     * trataba como anonimo. Ahora cualquier cambio en la version, el instante,
     * el actor, la lista de secciones o la marca de anonimizado hace fallar
     * `--verify`.
     *
     * @param  array<string, array<array-key, mixed>>  $sections
     */
    private static function seal(
        string $productVersion,
        DateTimeImmutable $generatedAt,
        bool $anonymized,
        DiagnosticsActor $generatedBy,
        array $sections,
    ): self {
        $unsealed = new DiagnosticsManifest(
            productVersion: $productVersion,
            generatedAt: $generatedAt,
            anonymized: $anonymized,
            generatedBy: $generatedBy,
            sections: array_keys($sections),
            sha256: '',
        );

        return new self(
            new DiagnosticsManifest(
                productVersion: $productVersion,
                generatedAt: $generatedAt,
                anonymized: $anonymized,
                generatedBy: $generatedBy,
                sections: array_keys($sections),
                sha256: CanonicalJson::digest(['manifest' => $unsealed->toUnsealedArray(), ...$sections]),
            ),
            $sections,
        );
    }

    /**
     * @param  array<string, array<array-key, mixed>>  $sections
     * @return array<string, array<array-key, mixed>>
     */
    private static function fit(array $sections, int $maxBytes): array
    {
        foreach (self::SACRIFICE_ORDER as $candidate) {
            if (\strlen(CanonicalJson::encode($sections)) <= $maxBytes) {
                return $sections;
            }

            if (! array_key_exists($candidate, $sections)) {
                continue;
            }

            $sections[$candidate] = [
                'status' => 'omitted',
                'reason' => 'size_limit',
                'bytes' => \strlen(CanonicalJson::encode($sections[$candidate])),
            ];
        }

        return $sections;
    }
}

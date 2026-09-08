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
        $sections = self::fit($sections, $maxBytes);

        return new self(
            new DiagnosticsManifest(
                productVersion: $productVersion,
                generatedAt: $generatedAt,
                anonymized: $anonymized,
                generatedBy: $generatedBy,
                sections: array_keys($sections),
                sha256: CanonicalJson::digest($sections),
            ),
            $sections,
        );
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
        $manifest = $document['manifest'] ?? null;

        if (! is_array($manifest) || ! is_string($manifest['sha256'] ?? null)) {
            return false;
        }

        $sections = $document;
        unset($sections['manifest']);

        return hash_equals($manifest['sha256'], CanonicalJson::digest($sections));
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

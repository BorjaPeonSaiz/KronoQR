<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

use App\Modules\Shared\Domain\ValueObject\UtcInstant;
use DateTimeImmutable;

/**
 * Cabecera del paquete de diagnostico (contrato `DiagnosticsManifest`).
 *
 * Es lo primero que lee soporte y lo unico que se puede dar por bueno sin abrir
 * el resto: **que version lo genero, cuando, si va anonimizado y la huella del
 * resto del documento**.
 *
 * `schema_version` cambia solo si cambia lo que **significa** una seccion o la
 * huella, no cada vez que se añade una seccion: un runbook escrito contra una
 * version tiene que seguir siendo valido cuando el paquete crezca.
 *
 * - `1`, hasta la 2.2.x: `sha256` cubre solo las secciones.
 * - `2`, desde la 2.3.0 (PR16): `sha256` cubre el documento entero con el
 *   manifiesto sin ese campo, de modo que cambiar `anonymized`, la version, el
 *   instante, el actor o la lista de secciones hace fallar `--verify`.
 *
 * `--verify` acepta las dos y dice cual ha comprobado
 * ({@see DiagnosticsDigestCheck}).
 */
final readonly class DiagnosticsManifest
{
    /** Version de la forma del paquete que se genera (contrato, `const: 2`). */
    public const int SCHEMA_VERSION = 2;

    /** La version anterior, cuya huella cubria solo las secciones. */
    public const int SECTIONS_ONLY_SCHEMA_VERSION = 1;

    /**
     * @param  list<string>  $sections
     */
    public function __construct(
        public string $productVersion,
        public DateTimeImmutable $generatedAt,
        public bool $anonymized,
        public DiagnosticsActor $generatedBy,
        public array $sections,
        public string $sha256,
    ) {}

    /**
     * @return array{schema_version: int, product_version: string, generated_at: string, anonymized: bool, generated_by: string, sections: list<string>, sha256: string}
     */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'product_version' => $this->productVersion,
            'generated_at' => UtcInstant::of($this->generatedAt),
            'anonymized' => $this->anonymized,
            'generated_by' => $this->generatedBy->value,
            'sections' => $this->sections,
            'sha256' => $this->sha256,
        ];
    }

    /**
     * El manifiesto sin su `sha256`: la parte de el que entra en la huella
     * (PR16). La huella no puede cubrirse a si misma.
     *
     * @return array{schema_version: int, product_version: string, generated_at: string, anonymized: bool, generated_by: string, sections: list<string>}
     */
    public function toUnsealedArray(): array
    {
        $manifest = $this->toArray();
        unset($manifest['sha256']);

        return $manifest;
    }

    /**
     * Nombre del fichero: `kronoqr-diagnostics-<version>-<YYYYMMDDTHHMMSSZ>.json`.
     *
     * Lleva la version y el instante porque el caso normal es que un cliente
     * envie varios paquetes de la misma incidencia con horas de diferencia, y
     * `diagnostics.json` repetido cuatro veces en una bandeja de correo no se
     * puede ordenar. La version delante porque es la primera pregunta de
     * cualquier incidencia.
     */
    public function fileName(): string
    {
        return 'kronoqr-diagnostics-'
            .(string) preg_replace('/[^A-Za-z0-9._-]/', '_', $this->productVersion)
            .'-'.UtcInstant::compact($this->generatedAt).'.json';
    }
}

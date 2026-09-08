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
 * `schema_version` es `1` y cambia solo si cambia lo que **significa** una
 * seccion, no cada vez que se añade una: un runbook escrito contra la version 1
 * tiene que seguir siendo valido cuando el paquete crezca.
 */
final readonly class DiagnosticsManifest
{
    /** Version de la forma del paquete (contrato, `const: 1`). */
    public const int SCHEMA_VERSION = 1;

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

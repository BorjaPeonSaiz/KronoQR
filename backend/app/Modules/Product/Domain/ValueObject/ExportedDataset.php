<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

/**
 * Un fichero del ZIP de la exportacion integra: que se llama, en que formato
 * sale y **exactamente que columnas lleva** (**RF-PD-14**, RL-20, tarea 5.10).
 *
 * ## La lista de permitidos es la pieza central, y es la misma del diagnostico
 *
 * {@see FieldAllowlist} se escribio para el paquete de diagnostico (5.9) y aqui
 * hace el mismo trabajo por el mismo motivo: **una lista de exclusiones falla en
 * silencio cada vez que alguien añade una columna**. El dia que `employees` gane
 * un campo `nickname`, con exclusiones empezaria a viajar en cada exportacion sin
 * que nadie lo decidiera; con permitidos, no aparece hasta que alguien lo añada
 * aqui a mano, mirandolo.
 *
 * La diferencia de alcance con el diagnostico es total y deliberada: aquel va
 * **anonimizado** porque sale hacia el fabricante (ADR-020, regla dura 16); este
 * lleva **todo** porque se queda con el cliente, que es su responsable del
 * tratamiento (RL-16). Lo que comparten es el mecanismo, no la politica.
 *
 * ## Las columnas de la lista son las de la CONSULTA, no las de la tabla
 *
 * La consulta ya resuelve las referencias a `uuid` —`employee_uuid`,
 * `device_uuid`, `user_uuid`— porque **los identificadores internos (`BIGINT`) no
 * salen del producto** (doc 01 §5.5), y no declara nunca `pin_hash`,
 * `secret_hash`, `token_hash`, `signed_key`, `password` ni los secretos de 2FA.
 * Que la lista y la consulta digan lo mismo lo garantiza el propio mecanismo: lo
 * que la consulta traiga de mas se descarta al aplicar la lista, y lo que la
 * lista pida de mas sale como columna vacia — visible de inmediato en el
 * fichero, no meses despues.
 *
 * ## `jsonColumns`
 *
 * Las columnas cuyo valor **es** un documento JSON en la base de datos
 * (`installation_settings.value`, `compliance_profiles.holiday_calendar`,
 * `license.features`, `sites.settings`). En un CSV salen como su texto, que es
 * lo correcto para una celda; en un fichero JSON se incrustan como documento,
 * que es lo correcto para un documento. Sin esta distincion, el JSON de la
 * configuracion llevaria cadenas con JSON dentro y quien lo lea tendria que
 * interpretarlo dos veces.
 */
final readonly class ExportedDataset
{
    /**
     * @param  string  $name  Nombre del fichero dentro del ZIP, sin extension. Es tambien
     *                        la clave de `row_counts` y la del `manifest.json`.
     * @param  list<string>  $jsonColumns  Ver el docblock de la clase.
     */
    private function __construct(
        public string $name,
        public DataExportFormat $format,
        public FieldAllowlist $allowlist,
        public array $jsonColumns = [],
    ) {}

    /**
     * @param  list<string>  $columns
     */
    public static function csv(string $name, array $columns): self
    {
        return new self($name, DataExportFormat::Csv, new FieldAllowlist(...$columns));
    }

    /**
     * @param  list<string>  $columns
     * @param  list<string>  $jsonColumns
     */
    public static function json(string $name, array $columns, array $jsonColumns = []): self
    {
        return new self($name, DataExportFormat::Json, new FieldAllowlist(...$columns), $jsonColumns);
    }

    public function fileName(): string
    {
        return $this->name.'.'.$this->format->extension();
    }

    /**
     * Las columnas declaradas, en el orden en que salen.
     *
     * @return list<string>
     */
    public function columns(): array
    {
        return $this->allowlist->fields;
    }

    public function embedsJson(string $column): bool
    {
        return in_array($column, $this->jsonColumns, true);
    }
}

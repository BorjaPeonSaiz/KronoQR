<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;

/**
 * El indice de la bandeja de incidencias y el de la exportacion legal
 * (RF-PA-05, RF-IN-05, RNF-P-02; hallazgos DB6 y DB8 de la verificacion de la
 * 2.1.0).
 *
 * ## Que problema resuelve
 *
 * - **`incidents_status_urgency_index`** `(status, (CASE severity WHEN 'high'
 *   THEN 0 WHEN 'medium' THEN 1 ELSE 2 END), detected_at DESC, id DESC)`. La
 *   pagina de la bandeja (`DatabaseIncidentBoard::page()`) filtra por `status`
 *   y ordena por urgencia, mas reciente y `id`. Sin el, era `Seq Scan on
 *   incidents` y un `top-N heapsort` de todas las del estado en cada visita;
 *   con el, el filtro y el orden salen del indice y la pagina lee sus
 *   veinticinco entradas sin ordenar nada. Los indices que habia no servian:
 *   `incidents_open_by_assignee` empieza por el responsable y solo cubre las
 *   abiertas.
 *
 *   **Es un indice de expresion, y la expresion tiene que ser IDENTICA a la del
 *   `ORDER BY` de `DatabaseIncidentBoard::ORDER`** —misma forma, mismos
 *   literales enteros—: PostgreSQL solo usa el indice para ordenar si reconoce
 *   la expresion, y una variante equivalente (`'low' THEN 2`, otro orden de las
 *   ramas) le parece otra. No se comparte una constante porque una migracion
 *   no puede depender del codigo de un modulo: el codigo cambia y la migracion
 *   tiene que seguir creando lo mismo que creo el dia que se aplico. Lo ata
 *   `tests/Integration/Compliance/IncidentBoardIndexUsageTest.php`, que falla
 *   si la pagina vuelve a tener un `Sort` sobre `incidents`.
 * - **`shift_entries_work_date_index`** `(work_date)`. La exportacion legal de
 *   toda la plantilla (`DatabaseLegalExportSource`) acota por `work_date
 *   BETWEEN` sin filtro por persona, y ningun indice de `shift_entries` empezaba
 *   por `work_date`: exportar una semana recorria todo el historico (RL-02,
 *   cuatro años). `shift_entries_employee_id_work_date_index` sigue sirviendo la
 *   exportacion de una sola persona y el diario.
 *
 * ## Patron `/migracion-segura`: expand puro
 *
 * | Despliegue | Que se hace | Estado del codigo |
 * |---|---|---|
 * | **1 (expand)** | Dos indices nuevos. Ninguna fila cambia. | La version anterior ejecuta las mismas consultas y se beneficia igual. |
 * | **2 (migrate)** | *No aplica.* | — |
 * | **3 (contract)** | *No aplica.* | — |
 *
 * ## `CONCURRENTLY`, con las consecuencias de siempre
 *
 * Las de `2026_10_08_100000_index_work_day_journal_lookups`: las dos tablas
 * tienen datos en toda instalacion desplegada, la migracion deja de ser atomica
 * (`$withinTransaction = false`), y si una construccion falla a mitad su indice
 * queda `INVALID`; `LimitsMigrationLocks::createIndexConcurrently()` lo borra y
 * lo reconstruye en el reintento, lanza si no queda valido y no choca con el
 * que ya se construyo. Solo `lock_timeout` y sin tope de duracion.
 *
 * `down()`: `DROP INDEX CONCURRENTLY IF EXISTS` de los dos
 * (`dropIndexConcurrently()`), verificado por
 * `MigrationsRoundTripTest`; el uso de los indices, por
 * `IncidentBoardIndexUsageTest` y `LegalExportIndexUsageTest`.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    /**
     * `CREATE INDEX CONCURRENTLY` no se ejecuta dentro de una transaccion.
     *
     * @var bool
     */
    public $withinTransaction = false;

    /**
     * Nombre => definicion. Los nombres son constantes de esta clase y nunca
     * entrada externa: PostgreSQL no admite parametros enlazados en un
     * identificador.
     *
     * La expresion de la urgencia es la de `DatabaseIncidentBoard::ORDER`,
     * caracter a caracter salvo el alias `i.`: ver el docblock de la clase.
     *
     * @var array<string, string>
     */
    private const array INDEXES = [
        'incidents_status_urgency_index' => "ON incidents (status, (CASE severity WHEN 'high' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END), detected_at DESC, id DESC)",
        'shift_entries_work_date_index' => 'ON shift_entries (work_date)',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => $definition) {
            $this->createIndexConcurrently($name, $definition);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(array_keys(self::INDEXES)) as $name) {
            $this->dropIndexConcurrently($name);
        }
    }
};

<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\UseCase;

use App\Modules\Compliance\Application\Port\LegalExportEmployeeDirectory;
use App\Modules\Compliance\Application\Port\LegalExportEmployeeMatch;
use App\Modules\Compliance\Domain\ValueObject\EmployeeSearch;
use App\Modules\Shared\Application\Port\PersonalDataAccessLog;
use App\Modules\Shared\Application\Support\UseCaseInstrumentation;

/**
 * Busca a la persona que nombra un requerimiento de la Inspeccion, por su nombre
 * o por su DNI, y deja constancia de la busqueda (RF-IN-05, V21-3-PA-1, ADR-037
 * enmienda del 18-10-2026, RS-05).
 *
 * ## Por que existe
 *
 * El `auditor` solo lleva `reports:legal` (RF-ID-02) y no puede leer la
 * plantilla. Sin esto, para exportar a una persona tenia que pedir su UUID a
 * RRHH o exportar la plantilla entera, y ninguna de las dos cosas es minimizar
 * (RGPD art. 5.1.c).
 *
 * ## Los limites que impiden que sea el directorio
 *
 * El criterio ya llega validado en {@see EmployeeSearch} —tres caracteres
 * minimo, o un documento exacto—. Aqui se pone el tope: se piden
 * {@see self::MAX_RESULTS} + 1 personas al adaptador y se devuelven
 * {@see self::MAX_RESULTS}; la que sobra solo sirve para saber si habia mas
 * (`truncated`) sin un `count(*)` aparte que contaria a toda la plantilla que
 * casa con el termino.
 *
 * ## El asiento: en cada busqueda, antes de responder y sin el termino
 *
 * `personal_data.accessed` con el dataset `legal_export_employee_search`, el
 * numero de personas **devueltas** y el contexto
 * `{criterion: name_or_code|national_id, truncated, employee_uuids}`, donde
 * `employee_uuids` son los `uuid` de las como mucho diez personas que se le
 * enseñaron, separados por comas —como en `incident_digest` y
 * `report_export.*`—, y no aparece si no hubo ninguna. Sin ellos el trail diria
 * cuantas personas vio el auditor, pero no cuales, y esa es la pregunta que
 * RL-15 obliga a contestar. Tres decisiones mas, las tres de ADR-037:
 *
 * - **Tambien sin resultados**, con recuento cero: probar documentos hasta
 *   acertar es justo lo que el trail tiene que poder enseñar.
 * - **Antes de devolver nada**: si el asiento falla, la excepcion sube y no hay
 *   respuesta (regla dura 6). Una lista nominal servida sin constancia es peor
 *   que un `500`.
 * - **Nunca el termino buscado** (regla dura 21): un nombre o un DNI en
 *   `audit_log` serian el dato personal que el asiento existe para no copiar, y
 *   un DNI en claro, ademas, el documento que RL-08 no deja guardar.
 *
 * Un asiento por busqueda y sin agrupar por ventana: no es un conjunto que se
 * sondee, cada busqueda es un acto de una persona
 * (`compliance.disclosure_grouping.datasets` no lo incluye).
 *
 * ## No se degrada con la licencia
 *
 * Es parte de atender un requerimiento, como la exportacion (ADR-019, regla
 * dura 15): no consulta el estado de la licencia.
 */
final readonly class SearchLegalExportEmployees
{
    /** Tope de personas por respuesta: el `maxItems` del contrato (RF-IN-05). */
    public const int MAX_RESULTS = 10;

    /** Vocabulario estable del `audit_log`, en ingles y sin datos dentro (ADR-037). */
    private const string DATASET = 'legal_export_employee_search';

    public function __construct(
        private LegalExportEmployeeDirectory $directory,
        private PersonalDataAccessLog $disclosures,
    ) {}

    public function handle(EmployeeSearch $search): LegalExportEmployeeMatches
    {
        return UseCaseInstrumentation::run(
            'compliance.search_legal_export_employees',
            fn (): LegalExportEmployeeMatches => $this->doHandle($search),
            // Atributos del span: el criterio y los recuentos, nunca el termino.
            static fn (LegalExportEmployeeMatches $result): array => [
                'criterion' => $search->criterion->value,
                'result_count' => $result->count(),
                'truncated' => $result->truncated,
            ],
        );
    }

    private function doHandle(EmployeeSearch $search): LegalExportEmployeeMatches
    {
        $found = $this->directory->search($search, self::MAX_RESULTS + 1);

        $result = new LegalExportEmployeeMatches(
            matches: \array_slice($found, 0, self::MAX_RESULTS),
            truncated: \count($found) > self::MAX_RESULTS,
        );

        $uuids = array_map(
            static fn (LegalExportEmployeeMatch $match): string => $match->uuid,
            $result->matches,
        );

        $this->disclosures->recordDisclosure(self::DATASET, $result->count(), [
            'criterion' => $search->criterion->value,
            'truncated' => $result->truncated,
            // Las personas que vio, en el orden en que se le enseñaron. Sin
            // resultados no hay clave, como en `report_export.*`.
            ...($uuids === [] ? [] : ['employee_uuids' => implode(',', $uuids)]),
        ]);

        return $result;
    }
}

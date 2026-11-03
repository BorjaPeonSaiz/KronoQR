<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Query;

use App\Modules\Shared\Application\Port\PersonalDataAccessLog;
use App\Modules\Shared\Application\Support\UseCaseInstrumentation;
use App\Modules\Workforce\Application\Port\AbsenceFilter;
use App\Modules\Workforce\Application\Port\AbsenceRepository;
use App\Modules\Workforce\Application\Port\AbsenceView;
use App\Modules\Workforce\Domain\Model\Absence;
use App\Modules\Workforce\Domain\ValueObject\AbsenceStatus;
use App\Modules\Workforce\Domain\ValueObject\AbsenceType;

/**
 * Lecturas de ausencias (**RF-GP-04**).
 *
 * **Por que existe si envuelve al repositorio casi sin añadir nada.** Por lo
 * mismo que {@see EmployeeQueries}: es donde vive el alcance de RF-ID-03 y donde
 * queda constancia de lo divulgado (RS-05). Si los controladores consultaran el
 * repositorio directamente, esas dos cosas habria que repetirlas en cada uno y
 * bastaria olvidarlas en uno.
 *
 * **El alcance entra como parametro y no se resuelve aqui.** Quien esta
 * autenticado lo sabe la capa HTTP; esta capa no puede preguntarselo al
 * contenedor sin atarse al transporte.
 *
 * ## Por que el listado deja asiento, y el detalle solo si es una baja medica
 *
 * Una pagina de este listado es un conjunto de personas —nombre, codigo,
 * departamento— **y ademas la categoria por la que faltan**, que en el caso de
 * `sick_leave` es dato de salud. Quien la pide es un tercero, asi que el asiento
 * describe el alcance —filtros, pagina, cuantas filas— y nunca lo divulgado
 * (regla dura 21).
 *
 * El detalle de una vacacion o de un permiso no deja asiento propio, con el
 * mismo criterio que la ficha de un empleado: quien puede abrirlo puede listar
 * el indice, y el asiento del indice ya dice que esa cuenta tuvo el conjunto
 * delante.
 *
 * **El de una baja medica si** (enmienda de ADR-037 del 03-11-2026, RGPD art.
 * 9). El asiento del indice dice «miro el cuadro de marzo», y ante la queja de
 * una persona hay que poder contestar «quien abrio MI baja», con su nota y su
 * historial. Ese detalle se puede pedir directamente por su `uuid`, sin pasar
 * por el indice, asi que el asiento del indice no lo cubre.
 */
final readonly class AbsenceQueries
{
    /** Vocabulario estable del `audit_log`, en ingles y sin datos dentro. */
    private const string DATASET = 'absence_register';

    /**
     * El detalle de una ausencia que es, o fue en alguna version, una baja
     * medica. Un nombre propio y no `absence_register` con un filtro: quien
     * acota una brecha (RL-15) busca «quien abrio bajas medicas» por conjunto.
     */
    private const string SICK_LEAVE_DETAIL_DATASET = 'sick_leave_detail';

    public function __construct(
        private AbsenceRepository $absences,
        private PersonalDataAccessLog $disclosures,
    ) {}

    /**
     * @return array{items: list<AbsenceView>, total: int, page: int, per_page: int, total_pages: int}
     */
    public function page(AbsenceFilter $filter, int $page, int $perPage): array
    {
        return UseCaseInstrumentation::run('workforce.list_absences', fn () => $this->doPage($filter, $page, $perPage));
    }

    /**
     * @return array{items: list<AbsenceView>, total: int, page: int, per_page: int, total_pages: int}
     */
    private function doPage(AbsenceFilter $filter, int $page, int $perPage): array
    {
        $total = $this->absences->countMatching($filter);
        $items = $this->absences->search($filter, $perPage, ($page - 1) * $perPage);

        // Antes de devolver, no despues: si la escritura de auditoria falla, la
        // divulgacion no ocurre (regla dura 6, ADR-027). El recuento es el de
        // las filas de ESTA pagina, que es lo que de verdad sale por la
        // respuesta; `total` describe el filtro, no lo entregado.
        $this->disclosures->recordDisclosure(self::DATASET, \count($items), [
            'from' => $filter->from->format('Y-m-d'),
            'to' => $filter->to->format('Y-m-d'),
            ...($filter->departmentId === null ? [] : ['department_id' => $filter->departmentId]),
            // El UUID publico de la persona SI entra cuando el filtro acota a
            // una: es lo que distingue «alguien miro el cuadro del mes» de
            // «alguien consulto las ausencias de esta persona concreta», que
            // ante una brecha (RL-15) no es lo mismo. Nunca su nombre.
            ...($filter->employeeUuid === null ? [] : ['employee_uuid' => $filter->employeeUuid]),
            // El operador ternario y no `?->value ?? …`: el `value` de un enum
            // nunca es nulo, asi que el `??` de la derecha seria codigo muerto y
            // PHPStan 9 lo dice en voz alta. Lo que puede faltar es el filtro
            // entero.
            'type' => $filter->type instanceof AbsenceType ? $filter->type->value : 'any',
            'status' => $filter->status instanceof AbsenceStatus ? $filter->status->value : 'all',
            // El alcance con el que se sirvio la pagina (RF-ID-03). Va el tipo,
            // no la lista de departamentos: quien pregunto ya esta identificado
            // como actor.
            'scope' => $filter->scope->isUnrestricted() ? 'all' : 'departments',
            'page' => $page,
            'per_page' => $perPage,
        ]);

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
        ];
    }

    public function find(string $uuid): ?AbsenceView
    {
        return UseCaseInstrumentation::run('workforce.find_absence', fn (): ?AbsenceView => $this->doFind($uuid));
    }

    private function doFind(string $uuid): ?AbsenceView
    {
        return $this->absences->viewOf($uuid);
    }

    /**
     * Las versiones anteriores de la ausencia que se va a enseñar en su
     * detalle, de la mas antigua a la mas reciente y sin incluirla (RN-13), y
     * el asiento si lo que sale lleva una baja medica.
     *
     * **Se llama con el alcance ya comprobado**: una lectura denegada deja
     * `access.denied` y nunca `personal_data.accessed`.
     *
     * @param  bool  $withNote  Si la respuesta lleva la nota, que puede traer un
     *                          diagnostico: el responsable de departamento no la
     *                          recibe.
     * @return list<AbsenceView>
     */
    public function historyForDetail(AbsenceView $view, bool $withNote): array
    {
        return UseCaseInstrumentation::run(
            'workforce.read_absence_history',
            fn (): array => $this->doHistoryForDetail($view, $withNote),
        );
    }

    /**
     * @return list<AbsenceView>
     */
    private function doHistoryForDetail(AbsenceView $view, bool $withNote): array
    {
        $history = $this->absences->historyOf($view->absence->uuid);
        $delivered = array_map(
            static fn (AbsenceView $version): Absence => $version->absence,
            [$view, ...$history],
        );

        // Cualquier version, no solo la que se pidio: una baja corregida a
        // vacaciones sigue enseñando en su historial que fue una baja.
        $sickLeave = array_filter(
            $delivered,
            static fn (Absence $version): bool => $version->type === AbsenceType::SickLeave,
        );

        if ($sickLeave !== []) {
            // Antes de devolver: si la escritura de auditoria falla, la baja no
            // se enseña (regla dura 6, ADR-027). Los `uuid` publicos de la
            // ausencia y de la persona, nunca su nombre ni la nota (regla dura
            // 21); `has_note` dice si la nota salio, no lo que decia.
            $this->disclosures->recordDisclosure(self::SICK_LEAVE_DETAIL_DATASET, 1, [
                'absence_uuid' => $view->absence->uuid,
                'employee_uuid' => $view->absence->employeeUuid,
                'versions' => \count($delivered),
                'has_note' => $withNote && array_filter(
                    $delivered,
                    static fn (Absence $version): bool => $version->hasNote(),
                ) !== [],
            ]);
        }

        return $history;
    }
}

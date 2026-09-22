<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Shared\Application\Authorization\ScopeGuard;
use App\Modules\Workforce\Application\Port\AbsenceFilter;
use App\Modules\Workforce\Domain\Model\Absence;
use App\Modules\Workforce\Domain\ValueObject\AbsenceStatus;
use App\Modules\Workforce\Domain\ValueObject\AbsenceType;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Filtros y paginacion del listado de ausencias (**RF-GP-04**).
 *
 * **Rechaza lo desconocido en lugar de ignorarlo.** Un filtro mal escrito
 * —`?tipo=baja`— devolveria el cuadro entero en silencio, y quien lo escribio se
 * iria convencido de haber filtrado.
 *
 * ## Los valores por omision se resuelven aqui y no en el caso de uso
 *
 * «El primer dia del mes en curso» depende de la zona del centro y del reloj:
 * las dos son propiedades del borde, no del dominio (regla dura 2). El caso de
 * uso recibe el periodo ya resuelto, igual que recibe los umbrales legales ya
 * resueltos (regla dura 14).
 *
 * ## El techo de dos años no es una regla de negocio
 *
 * Es lo que impide que una URL manipulada pida diez años de historico a una
 * consulta paginada que acaba pintando una tabla. La conservacion del registro
 * son cuatro años (RL-02) y sigue consultable: lo que se acota es **una** lectura.
 */
final class IndexAbsenceRequest extends FormRequest
{
    use RejectsUnknownInput;

    /**
     * Periodo por omision cuando no se envia `to`: un año menos un dia.
     *
     * La pantalla se usa tanto para el mes en curso como para el cuadrante de
     * vacaciones del año, y un mes por omision escondia la mitad de lo
     * registrado.
     */
    public const int DEFAULT_SPAN_DAYS = 364;

    /** Techo de una sola consulta, en dias. Dos años. */
    public const int MAX_SPAN_DAYS = 730;

    public function authorize(): bool
    {
        return Gate::allows('viewAny', Absence::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'employee_uuid' => ['sometimes', 'uuid'],
            'department_id' => ['sometimes', 'integer', 'min:1', 'exists:departments,id'],
            // Los casos salen del enum y no de una lista escrita a mano: un tipo
            // nuevo entraria aqui solo, y una lista copiada se quedaria atras sin
            // que nada fallara.
            'type' => ['sometimes', 'string', 'in:'.implode(',', AbsenceType::names())],
            // `all` no es un estado: es la ausencia de filtro, y se escribe para
            // que pedirlo sea una decision y no el descuido de omitir el
            // parametro.
            'status' => ['sometimes', 'string', 'in:'.implode(',', [...AbsenceStatus::names(), 'all'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to.after_or_equal' => 'El periodo termina antes de empezar.',
        ];
    }

    /**
     * El filtro ya resuelto, con el alcance de quien pregunta dentro.
     *
     * **El alcance entra aqui y no puede omitirse**: es la acotacion que quien
     * llama no elige (RF-ID-03), y componerla en el `FormRequest` es lo que evita
     * que un controlador se la deje.
     */
    public function toFilter(ScopeGuard $scope, string $timezone, DateTimeImmutable $now): AbsenceFilter
    {
        $from = $this->fromDate($timezone, $now);

        return new AbsenceFilter(
            scope: $scope->scopeOf($this->user()),
            from: $from,
            to: $this->toDate($from),
            employeeUuid: $this->has('employee_uuid') ? $this->string('employee_uuid')->value() : null,
            departmentId: $this->has('department_id') ? $this->integer('department_id') : null,
            type: $this->has('type') ? AbsenceType::from($this->string('type')->value()) : null,
            status: $this->statusFilter(),
        );
    }

    public function page(): int
    {
        return $this->has('page') ? $this->integer('page') : 1;
    }

    public function perPage(): int
    {
        return $this->has('per_page') ? $this->integer('per_page') : 25;
    }

    /**
     * `active` por omision, `null` cuando se pide `all`.
     *
     * `null` significa «sin filtro de estado» para {@see AbsenceFilter}, y `all`
     * es como se escribe eso en la URL. Son dos vocabularios y la traduccion vive
     * aqui, que es el borde.
     */
    private function statusFilter(): ?AbsenceStatus
    {
        $status = $this->has('status') ? $this->string('status')->value() : AbsenceStatus::Active->value;

        return $status === 'all' ? null : AbsenceStatus::from($status);
    }

    /**
     * El primer dia del periodo, o el primero del mes en curso **en la zona del
     * centro**.
     *
     * ## Ni la zona ni el instante se leen aqui
     *
     * Los dos entran como parametro. La zona la sabe el controlador, que la pide
     * al proveedor del centro (ADR-040); y el instante sale del puerto `Clock`,
     * que es lo unico que este producto admite como fuente de tiempo (regla dura
     * 2, ADR-021).
     *
     * **Antes habia aqui un `new DateTimeImmutable('now', …)`**, y era el unico
     * punto del producto fuera de `SystemClock` que leia el reloj del proceso:
     * `FrozenTime` no lo detenia, asi que «el mes en curso» no se podia probar y
     * la primera peticion de cada 1 de mes devolvia un rango distinto al de la
     * anterior sin que ninguna prueba lo viera.
     */
    private function fromDate(string $timezone, DateTimeImmutable $now): DateTimeImmutable
    {
        if ($this->has('from')) {
            return self::asDate($this->string('from')->value());
        }

        // El instante llega en UTC y se traslada a la zona del centro **antes**
        // de quedarse con el mes: el 1 de marzo a las 00:30 en Canarias sigue
        // siendo febrero en UTC, y quien abre la pantalla espera marzo.
        return self::asDate($now->setTimezone(new DateTimeZone($timezone))->format('Y-m-01'));
    }

    /**
     * El ultimo dia, acotado a {@see self::MAX_SPAN_DAYS}.
     *
     * Se **recorta** en lugar de responder `422` cuando el periodo es demasiado
     * largo: un enlace guardado con un rango enorme tiene que seguir devolviendo
     * algo util, y lo que el cliente pidio de mas es historico que puede pedir en
     * otra consulta. El `422` se reserva para lo que no tiene sentido, como un
     * periodo invertido.
     */
    private function toDate(DateTimeImmutable $from): DateTimeImmutable
    {
        $to = $this->has('to')
            ? self::asDate($this->string('to')->value())
            : $from->add(new DateInterval('P'.self::DEFAULT_SPAN_DAYS.'D'));

        $ceiling = $from->add(new DateInterval('P'.self::MAX_SPAN_DAYS.'D'));

        return $to > $ceiling ? $ceiling : $to;
    }

    private static function asDate(string $isoDate): DateTimeImmutable
    {
        return new DateTimeImmutable($isoDate.' 00:00:00', new DateTimeZone('UTC'));
    }
}

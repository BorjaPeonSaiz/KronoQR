<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Product\Application\Port\ErrorEventQuery;
use App\Modules\Product\Domain\ValueObject\ErrorEvent;
use App\Modules\Product\Domain\ValueObject\ErrorEventStatusFilter;
use App\Modules\Shared\Domain\ValueObject\ErrorLevel;
use App\Modules\Shared\Domain\ValueObject\ErrorSource;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Los cinco filtros y la paginacion de `GET /api/v1/diagnostics/errors`
 * (**RF-PD-15**).
 *
 * **Rechaza lo desconocido en lugar de ignorarlo**, igual que el resto de
 * listados: un `?severity=critical` —el nombre en ingles del campo que aqui se
 * llama `level`— devolveria el historico entero en silencio y quien lo escribio
 * se iria convencido de estar mirando solo lo urgente.
 *
 * **`status` tiene valor por omision y los otros cuatro no.** `open` es la
 * pregunta de quien abre la pantalla; un historico sin filtro de situacion
 * serian noventa dias de todo con una columna de estado.
 *
 * **Los casos salen de los enums y no de listas escritas a mano**: un origen
 * nuevo entra aqui solo, y una lista copiada se quedaria atras sin que nada
 * fallara.
 *
 * **`from` y `to` acotan `last_seen_at`** y llegan en UTC, como todo instante de
 * esta API (regla dura 3). El panel envia lo que quiera mostrar convertido; aqui
 * no se interpreta ninguna zona.
 */
final class IndexErrorEventRequest extends FormRequest
{
    /** El mismo techo que el resto de listados y que el `maximum` del contrato. */
    public const int MAX_PER_PAGE = 100;

    /** Lo que el panel pide sin decir nada. */
    public const int DEFAULT_PER_PAGE = 25;

    use RejectsUnknownInput;

    public function authorize(): bool
    {
        return Gate::allows('view', ErrorEvent::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', 'in:'.implode(',', ErrorEventStatusFilter::names())],
            'source' => ['sometimes', 'string', 'in:'.implode(',', ErrorSource::names())],
            'level' => ['sometimes', 'string', 'in:'.implode(',', ErrorLevel::names())],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'page' => ['sometimes', 'integer', 'min:1'],
            // El techo es proteccion de recursos: una instalacion con un
            // problema de verdad acumula cientos de grupos, y ninguna pantalla
            // los sirve todos de una vez.
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }

    public function toQuery(): ErrorEventQuery
    {
        return new ErrorEventQuery(
            status: $this->statusFilter(),
            source: $this->sourceFilter(),
            level: $this->levelFilter(),
            from: $this->instant('from'),
            to: $this->instant('to'),
            page: $this->has('page') ? $this->integer('page') : 1,
            perPage: $this->has('per_page') ? $this->integer('per_page') : self::DEFAULT_PER_PAGE,
        );
    }

    private function statusFilter(): ErrorEventStatusFilter
    {
        $status = $this->string('status')->value();

        return $status === ''
            ? ErrorEventStatusFilter::default()
            : ErrorEventStatusFilter::from($status);
    }

    private function sourceFilter(): ?ErrorSource
    {
        $source = $this->string('source')->value();

        return $source === '' ? null : ErrorSource::from($source);
    }

    private function levelFilter(): ?ErrorLevel
    {
        $level = $this->string('level')->value();

        return $level === '' ? null : ErrorLevel::from($level);
    }

    /**
     * Un instante del rango, ya en UTC.
     *
     * `date` en las reglas admite cualquier forma que PHP entienda, y eso es
     * deliberado: el panel manda ISO-8601 con `Z`, y quien pruebe con `curl`
     * escribira `2026-09-09`. La conversion a UTC ocurre aqui una sola vez, para
     * que la consulta no dependa de la zona del proceso.
     */
    private function instant(string $field): ?DateTimeImmutable
    {
        $value = $this->string($field)->value();

        if ($value === '') {
            return null;
        }

        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}

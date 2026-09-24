<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Request;

use App\Modules\Reporting\Application\Support\ReportDelivery;
use App\Modules\Reporting\Domain\ValueObject\AdoptionReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * `GET /api/v1/reports/adoption/export` — que cuadro se descarga y en que formato
 * (**RF-IN-08**).
 *
 * ## El periodo es **el mismo** que el de la consulta, por construccion
 *
 * Lo declara {@see DescribesAdoptionPeriod}, el mismo rasgo que usa
 * {@see GetAdoptionReportRequest}. Con dos listas separadas bastaria cambiar una
 * para que el papel y la pantalla hablaran de periodos distintos.
 *
 * ## Los tres formatos, al contrario que la nomina
 *
 * Alli el PDF esta fuera porque ningun programa de nomina importa un PDF. Aqui es
 * al reves: **el PDF es el formato mas util de los tres**, porque es el que se
 * adjunta a una renovacion de licencia o se lleva a una reunion, y lleva el sello
 * con emisor, periodo y huella en cada pagina.
 *
 * **Sin valor por omision** y como parametro y no como cabecera `Accept`, por lo
 * mismo que en el informe por periodo: un parametro sobrevive a un enlace y a un
 * historial de descargas, y el cliente TypeScript que se genera del contrato no
 * sabe elegir entre tres respuestas binarias por negociacion de contenido.
 *
 * **`json` no se admite** aunque exista en el enumerado: esa forma la sirve el
 * otro endpoint, y aceptarla aqui daria dos URL para la misma respuesta.
 *
 * ## La policy es `export()` y no `view()`
 *
 * Aunque hoy digan lo mismo. Lo que sale por aqui es un fichero que se reenvia y
 * que **deja asiento en `audit_log`**; el dia que alguien quisiera dejar ver el
 * cuadro sin poder llevarselo, ese cambio tiene que poder hacerse sin tocar la
 * consulta.
 */
final class ExportAdoptionReportRequest extends FormRequest
{
    use DescribesAdoptionPeriod;

    /**
     * Los formatos de fichero, sin `json` y sin `mail`. Una sola lista: la que
     * valida es la que traduce, asi que no pueden divergir.
     *
     * @var list<ReportDelivery>
     */
    private const array FORMATS = [ReportDelivery::Csv, ReportDelivery::Xlsx, ReportDelivery::Pdf];

    public function authorize(): bool
    {
        return Gate::allows('export', AdoptionReport::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            ...$this->adoptionPeriodRules(),
            'format' => ['required', 'string', 'in:'.implode(',', self::formatNames())],
        ];
    }

    /**
     * El formato pedido, ya validado.
     *
     * **Se llama `exportFormat()` y no `format()`** porque `Illuminate\Http\Request`
     * ya tiene un `format()` —el de la negociacion de contenido— y sobrescribirlo
     * con otra firma y otro significado es la clase de colision que se descubre en
     * produccion. Mismo nombre que en el informe por periodo.
     */
    public function exportFormat(): ReportDelivery
    {
        return ReportDelivery::from($this->string('format')->value());
    }

    /**
     * @return list<string>
     */
    private static function formatNames(): array
    {
        return array_map(
            static fn (ReportDelivery $format): string => $format->value,
            self::FORMATS,
        );
    }
}

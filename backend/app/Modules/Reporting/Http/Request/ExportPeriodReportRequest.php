<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Request;

use App\Modules\Reporting\Application\Support\ReportDelivery;
use App\Modules\Reporting\Domain\ValueObject\PeriodReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * `GET /api/v1/reports/period/export` — que informe se descarga y en que formato
 * (**RF-IN-04**).
 *
 * ## Los parametros son **los mismos** que los de la consulta
 *
 * Y lo son por construccion, no por acuerdo: los declara
 * {@see DescribesPeriodReport}, el mismo rasgo que usa
 * {@see GeneratePeriodReportRequest}. Con dos listas separadas bastaria añadir un
 * filtro en una para que el fichero que alguien adjunta a un correo describiera
 * un informe distinto del que estaba mirando en pantalla.
 *
 * La policy tambien es la misma —`PeriodReportPolicy` sobre {@see PeriodReport}—
 * y con ella el ambito `reports:*` que verifica el middleware antes. Regla dura
 * 18: descargar no puede estar peor protegido que consultar, porque lo que sale
 * es exactamente lo mismo y ademas se puede reenviar.
 *
 * ## `format` es obligatorio, y es un parametro y no una cabecera
 *
 * La alternativa era negociar por `Accept`. Se descarta por tres motivos que el
 * contrato tambien recoge: un parametro sobrevive a un enlace y a un historial
 * de descargas, queda en el registro de acceso sin reconstruirlo desde una
 * cabecera, y el cliente TypeScript que se genera del contrato no sabe elegir
 * entre tres respuestas binarias distintas por `Accept`.
 *
 * **Sin valor por omision.** Suponer CSV porque es el mas comun seria decidir
 * por quien descarga, igual que suponer «mes» en la granularidad: quien pulsa un
 * boton de descarga ya ha elegido formato.
 *
 * **`json` no se admite** aunque exista en el enumerado: esa forma la sirve el
 * otro endpoint, y aceptarla aqui daria dos URL para la misma respuesta.
 */
final class ExportPeriodReportRequest extends FormRequest
{
    use DescribesPeriodReport;

    /**
     * Los formatos de fichero, sin `json`. Una sola lista: la que valida es la
     * que traduce, asi que no pueden divergir.
     *
     * @var list<ReportDelivery>
     */
    private const array FORMATS = [ReportDelivery::Csv, ReportDelivery::Xlsx, ReportDelivery::Pdf];

    public function authorize(): bool
    {
        return Gate::allows('view', PeriodReport::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            ...$this->periodReportRules(),
            'format' => ['required', 'string', 'in:'.implode(',', self::formatNames())],
        ];
    }

    /**
     * El formato pedido, ya validado.
     *
     * `ReportDelivery::from()` no puede fallar aqui: la lista `in:` de
     * {@see self::rules()} se construye de este mismo enumerado, asi que un valor
     * que llegue a este punto es uno de los tres.
     *
     * **Se llama `exportFormat()` y no `format()`** porque `Illuminate\Http\Request`
     * ya tiene un `format()` —el de la negociacion de contenido, con su parametro
     * por omision— y sobrescribirlo con otra firma y otro significado es la clase
     * de colision que se descubre en produccion. Aqui la detecto PHPStan.
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

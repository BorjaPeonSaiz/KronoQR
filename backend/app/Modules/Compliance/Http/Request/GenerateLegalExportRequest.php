<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Compliance\Application\Command\GenerateLegalExportCommand;
use App\Modules\Compliance\Application\UseCase\LegalExport;
use App\Modules\Compliance\Domain\ValueObject\LegalExportPeriod;
use App\Modules\Compliance\Domain\ValueObject\LegalExportScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * `GET /api/v1/reports/legal-export` — los tres parametros de un requerimiento
 * de Inspeccion (RF-IN-05, RL-06).
 *
 * ## `from` y `to` son fechas de JORNADA, no instantes
 *
 * Y por eso la regla es `date_format:Y-m-d` y no un `date` cualquiera. Un
 * `2026-01-31T22:00:00Z` seria un instante, y con instantes el turno de noche
 * del 31 se partiria por la puerta de atras: la mitad en el fichero de enero y
 * la mitad en el de febrero (RN-05, regla dura 4). `work_date` es una fecha
 * civil; el parametro tambien.
 *
 * ## Los dos extremos son inclusivos
 *
 * «Del 1 al 31 de enero» son los 31 dias, que es como lo entiende quien redacta
 * un requerimiento. `after_or_equal` sobre `to` es lo que impide pedir un
 * periodo que termina antes de empezar; el dominio lo vuelve a comprobar en
 * {@see LegalExportPeriod}, porque el comando de consola no pasa por aqui.
 *
 * ## `employee_uuid` es opcional y no admite lista
 *
 * O la plantilla completa, o una persona. Una lista de UUID en la cadena de
 * consulta seria un alcance que ni el asiento de `audit_log` ni la cabecera de
 * criterios del fichero saben describir en una linea, y los tres tienen que
 * decir lo mismo.
 *
 * ## Rechaza lo que no conoce
 *
 * Un `?site_id=3` o un `?department=cocina` ignorados en silencio dejarian a
 * quien atiende el requerimiento convencido de haber acotado la exportacion. En
 * un documento con efectos legales, entregar de mas creyendo que se filtro es
 * peor que fallar.
 */
final class GenerateLegalExportRequest extends FormRequest
{
    use RejectsUnknownInput;

    public function authorize(): bool
    {
        return Gate::allows('generate', LegalExport::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'string', 'date_format:Y-m-d'],
            'to' => ['required', 'string', 'date_format:Y-m-d', 'after_or_equal:from'],
            'employee_uuid' => ['nullable', 'string', 'uuid'],
        ];
    }

    public function toCommand(string $destinationPath): GenerateLegalExportCommand
    {
        return new GenerateLegalExportCommand(
            period: LegalExportPeriod::between($this->stringInput('from'), $this->stringInput('to')),
            scope: $this->scope(),
            destinationPath: $destinationPath,
        );
    }

    /**
     * Fragmento estable para el nombre del temporal. Sin datos personales y sin
     * nada que un sistema de ficheros pueda interpretar.
     */
    public function temporaryFileSuffix(): string
    {
        return Str::of($this->stringInput('from').'_'.$this->stringInput('to'))
            ->replaceMatches('/[^0-9\-_]/', '')
            ->toString();
    }

    private function scope(): LegalExportScope
    {
        $employee = $this->stringInput('employee_uuid');

        return $employee === '' ? LegalExportScope::everyone() : LegalExportScope::employee($employee);
    }

    private function stringInput(string $field): string
    {
        $value = $this->input($field);

        return is_string($value) ? trim($value) : '';
    }
}

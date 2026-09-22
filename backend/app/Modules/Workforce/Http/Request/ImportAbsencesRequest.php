<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Workforce\Application\Command\ImportAbsencesCommand;
use App\Modules\Workforce\Domain\Model\Absence;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * `POST /api/v1/absences/import` (contrato `AbsenceImportRequest`).
 *
 * **Calcado de {@see ImportEmployeesRequest}**, incluidos sus dos criterios, que
 * estan explicados alli y valen igual aqui:
 *
 * - **El limite de tamaño se comprueba en la aplicacion** y no solo en Nginx,
 *   que corta en 8 MB con un `413` sin cuerpo que quien lo recibe lee como
 *   «error de red».
 * - **Las extensiones se comprueban, el tipo MIME no.** Un CSV exportado por
 *   Excel llega unas veces como `text/csv`, otras como `text/plain` y otras como
 *   `application/vnd.ms-excel`, segun el navegador y el sistema.
 *
 * Comparte los dos limites de `config/workforce.php` con la carga de plantilla y
 * no tiene los suyos: son el mismo lector, el mismo informe en memoria y el
 * mismo tamaño de instalacion, y dos parametros para lo mismo serian dos cosas
 * que se separan.
 */
final class ImportAbsencesRequest extends FormRequest
{
    use RejectsUnknownInput;

    public function authorize(): bool
    {
        return Gate::allows('import', Absence::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $maxKilobytes = max(1, config()->integer('workforce.import.max_file_kilobytes'));

        return [
            'file' => ['required', 'file', 'extensions:csv,txt,xlsx', 'max:'.$maxKilobytes],
            'mode' => ['required', 'string', 'in:validate,apply'],
            // `required_if` y no `required`: la validacion no confirma nada.
            'confirm_checksum' => ['required_if:mode,apply', 'nullable', 'string', 'regex:/^[0-9a-f]{64}$/'],
        ];
    }

    public function toCommand(): ImportAbsencesCommand
    {
        $file = $this->file('file');

        if (! $file instanceof UploadedFile) {
            // Imposible tras `required|file`, pero el tipo lo permite y PHPStan 9
            // no admite darlo por hecho.
            throw new RuntimeException('La peticion de carga de ausencias no trae fichero.');
        }

        $path = $file->getRealPath();

        if ($path === false) {
            throw new RuntimeException('El fichero subido no esta accesible en disco.');
        }

        return new ImportAbsencesCommand(
            // La ruta del temporal de la peticion: se lee en streaming desde ahi
            // y PHP lo borra al terminar. El producto NO lo guarda entre las dos
            // fases.
            path: $path,
            apply: $this->string('mode')->value() === 'apply',
            confirmChecksum: $this->optionalString('confirm_checksum'),
            importedByUserId: $this->managementUserId(),
        );
    }

    private function optionalString(string $key): ?string
    {
        $value = $this->input($key);

        return \is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function managementUserId(): ?int
    {
        $identifier = $this->user()?->getAuthIdentifier();

        return is_numeric($identifier) ? (int) $identifier : null;
    }
}

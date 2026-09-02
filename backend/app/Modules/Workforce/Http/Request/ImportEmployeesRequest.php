<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Workforce\Application\Command\ImportEmployeesCommand;
use App\Modules\Workforce\Domain\Model\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * `POST /api/v1/employees/import` (contrato `EmployeeImportRequest`).
 *
 * ## El limite de tamano se comprueba aqui y no solo en el borde
 *
 * Nginx corta en 8 MB y devuelve un `413` **sin cuerpo**: quien lo recibe ve un
 * «error de red» y no tiene forma de saber que su fichero era demasiado grande.
 * Este limite es mas bajo, se comprueba en la aplicacion y produce un `422` que
 * dice que hacer. Es configuracion (`WORKFORCE_IMPORT_MAX_FILE_KILOBYTES`,
 * 4096 KB de serie) porque el peor fallo posible aqui seria rechazar un fichero
 * legitimo.
 *
 * ## Las extensiones se comprueban, el tipo MIME no
 *
 * `mimes:csv,txt,xlsx` mira la extension **y** el tipo detectado por PHP, y ese
 * segundo es justo el que falla: un CSV exportado por Excel llega unas veces
 * como `text/csv`, otras como `text/plain` y otras como
 * `application/vnd.ms-excel`, segun el navegador y el sistema. Rechazar por eso
 * un fichero valido seria un fallo peor que el que se evita, asi que se acota
 * por extension —`extensions:`— y quien decide de verdad si el contenido se
 * puede leer es el lector, que responde `422` con su propio mensaje.
 *
 * ## `confirm_checksum` es obligatorio con `mode: apply`
 *
 * Y su ausencia se caza aqui, no en el caso de uso, para que el `422` señale el
 * campo. Que **coincida** con el fichero enviado ya no es validacion de forma:
 * eso es un `409` del caso de uso, porque lo que no encaja es el estado.
 */
final class ImportEmployeesRequest extends FormRequest
{
    use RejectsUnknownInput;

    public function authorize(): bool
    {
        return Gate::allows('import', Employee::class);
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

    public function toCommand(): ImportEmployeesCommand
    {
        $file = $this->file('file');

        if (! $file instanceof UploadedFile) {
            // Imposible tras `required|file`, pero el tipo lo permite y PHPStan 9
            // no admite darlo por hecho.
            throw new RuntimeException('La peticion de importacion no trae fichero.');
        }

        $path = $file->getRealPath();

        if ($path === false) {
            throw new RuntimeException('El fichero subido no esta accesible en disco.');
        }

        return new ImportEmployeesCommand(
            // La ruta del temporal de la peticion: se lee en streaming desde ahi
            // y PHP lo borra al terminar. El producto NO lo guarda entre las dos
            // fases — un almacen con nombres y documentos de identidad de la
            // plantilla es superficie de datos personales en reposo.
            path: $path,
            apply: $this->string('mode')->value() === 'apply',
            confirmChecksum: $this->optionalString('confirm_checksum'),
        );
    }

    private function optionalString(string $key): ?string
    {
        $value = $this->input($key);

        return \is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Identity\Application\Command\PrintCredentialBatchCommand;
use App\Modules\Identity\Domain\Model\Credential;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * `POST /api/v1/credentials/print-batch` — la hoja A4 del centro (RF-QR-04).
 *
 * **Un solo campo opcional, `site_id`, y ninguno mas.** La ausencia del resto es
 * la parte importante: no hay `reprint`, no hay `force` y no hay
 * `include_printed`, porque no existe la reimpresion (ADR-034). El trait de
 * campos desconocidos hace que enviar cualquiera de ellos sea un `422` en vez de
 * un silencio, que es lo que evita que alguien se vaya convencido de haber
 * reimpreso un lote.
 *
 * **`--pending` no aparece como campo y no es un descuido.** En consola es una
 * bandera obligatoria porque deja constancia en el historial del interprete de
 * que quien la escribio sabia que eso no reimprime; por la API, el unico
 * comportamiento posible **es** ese, asi que un booleano que solo admite `true`
 * seria un campo que no decide nada.
 */
final class PrintCredentialBatchRequest extends FormRequest
{
    use RejectsUnknownInput;

    public function authorize(): bool
    {
        return Gate::allows('print', Credential::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            // `exists` y no solo `integer`: pedir el lote de un centro que no
            // existe devolveria un PDF vacio y quien lo pidio se quedaria
            // pensando que ya estaba todo impreso.
            'site_id' => ['sometimes', 'integer', 'min:1', 'exists:sites,id'],
        ];
    }

    public function toCommand(?int $actorUserId): PrintCredentialBatchCommand
    {
        $siteId = $this->input('site_id');

        return new PrintCredentialBatchCommand(
            siteId: \is_numeric($siteId) ? (int) $siteId : null,
            actorUserId: $actorUserId,
        );
    }
}

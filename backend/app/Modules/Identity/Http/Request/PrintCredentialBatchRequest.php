<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Identity\Application\Command\PrintCredentialBatchCommand;
use App\Modules\Identity\Domain\Model\Credential;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * `POST /api/v1/credentials/print-batch` (RF-QR-04).
 *
 * Sin campos: el lote son todas las pendientes de la instalacion (ADR-040) y
 * no existe la reimpresion (ADR-034). `RejectsUnknownInput` se queda para que
 * un `site_id` o un `force` inventados fallen en voz alta.
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
        return [];
    }

    public function toCommand(?int $actorUserId): PrintCredentialBatchCommand
    {
        return new PrintCredentialBatchCommand(actorUserId: $actorUserId);
    }
}

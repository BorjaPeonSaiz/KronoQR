<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Workforce\Application\Command\UpdateSiteCommand;
use App\Modules\Workforce\Domain\Model\Site;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * `PATCH /api/v1/site` (contrato `UpdateSiteRequest`).
 */
final class UpdateSiteRequest extends FormRequest
{
    use RejectsUnknownInput;

    public function authorize(): bool
    {
        return Gate::allows('update', Site::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'timezone' => ['sometimes', 'required', 'string', 'max:64', 'timezone'],
        ];
    }

    public function toCommand(): UpdateSiteCommand
    {
        return new UpdateSiteCommand(
            name: $this->has('name') ? $this->string('name')->trim()->value() : null,
            timezone: $this->has('timezone') ? $this->string('timezone')->trim()->value() : null,
        );
    }
}

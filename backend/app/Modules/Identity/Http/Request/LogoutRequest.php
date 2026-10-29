<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Request;

use App\Modules\Identity\Http\Policy\SessionPolicy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/v1/auth/logout`: sin cuerpo, y con su policy (regla dura 18).
 *
 * Lo que autoriza es {@see SessionPolicy}: una cuenta de gestion o un acceso de
 * soporte; un quiosco o una sesion del portal reciben `403`.
 */
final class LogoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (new SessionPolicy)->logOut($this->user());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}

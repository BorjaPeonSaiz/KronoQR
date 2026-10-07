<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Request;

use App\Modules\Identity\Application\Command\ActorProof;
use App\Modules\Shared\Application\Port\ManagementActor;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Los dos campos de reautenticacion de quien actua sobre otra cuenta
 * (RF-ID-10, ADR-051 §7): **exactamente uno** de `actor_totp_code` (seis
 * cifras) y `actor_current_password`. Cual de los dos vale lo decide el caso de
 * uso segun la cuenta que actua tenga o no segundo factor confirmado.
 *
 * @phpstan-require-extends FormRequest
 */
trait ReauthenticatesActor
{
    /**
     * @return array<string, list<string>>
     */
    protected function actorProofRules(): array
    {
        return [
            'actor_totp_code' => [
                'required_without:actor_current_password',
                'prohibits:actor_current_password',
                'string',
                'regex:/^[0-9]{6}$/',
            ],
            'actor_current_password' => [
                'required_without:actor_totp_code',
                'string',
                'min:1',
                'max:200',
            ],
        ];
    }

    public function actorProof(): ActorProof
    {
        $code = $this->input('actor_totp_code');
        $password = $this->input('actor_current_password');

        return new ActorProof(
            \is_string($code) ? $code : null,
            \is_string($password) ? $password : null,
        );
    }

    public function actorUuid(): string
    {
        // La policy ya ha exigido una cuenta `admin` del cliente: aqui siempre
        // hay un actor de gestion.
        $actor = $this->user();

        return $actor instanceof ManagementActor ? $actor->actorUuid() : '';
    }
}

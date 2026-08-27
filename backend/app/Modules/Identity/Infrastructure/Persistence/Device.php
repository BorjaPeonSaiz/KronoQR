<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Fila de `devices` (doc 01 §5.5, RF-ID-04). **Detalle de persistencia**: el
 * modelo de dominio es {@see \App\Modules\Identity\Domain\Model\Device}.
 *
 * **Es un `tokenable` de Sanctum, y no una cuenta de `users`.** La alternativa
 * —dar de alta un usuario por tablet— habria metido a los quioscos en la tabla
 * de personas: politica de contrasenas, 2FA de la Fase 2, bloqueo por intentos y
 * un correo obligatorio para algo que no lee correo. Colgando el token del
 * propio dispositivo, la baja de un quiosco es su fila y no una cuenta fantasma.
 *
 * `token_hash` duplica a proposito el hash que Sanctum guarda en
 * `personal_access_tokens.token`: **la fila del dispositivo tiene que ser
 * autoexplicativa**. El panel de quioscos (RF-KI-06) y el diagnostico responden
 * «¿esta emparejado?» mirando aqui, sin unirse a la tabla de tokens ni conocer
 * como esta montada la sesion. El token en claro no esta en ninguno de los dos
 * sitios.
 *
 * @property int $id
 * @property string $uuid
 * @property int $site_id
 * @property string $name
 * @property string|null $token_hash
 * @property string|null $app_version
 * @property Carbon|null $last_seen_at
 * @property int $pending_queue_size
 * @property string $status
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> query()
 */
final class Device extends Model
{
    /** @use HasApiTokens<PersonalAccessToken> */
    use HasApiTokens;

    protected $table = 'devices';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'site_id',
        'name',
        'app_version',
        'last_seen_at',
        'pending_queue_size',
        'status',
    ];

    /**
     * No es `fillable`: se escribe por el repositorio y solo por el, con el hash
     * que acaba de calcular el emisor. Un `create()` masivo no debe poder fijar
     * el token de una tablet.
     *
     * @var list<string>
     */
    protected $hidden = [
        'token_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
            'pending_queue_size' => 'integer',
            'last_seen_at' => 'datetime',
        ];
    }
}

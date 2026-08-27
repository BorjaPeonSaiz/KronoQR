<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

/**
 * Fila de `sites` (doc 01 §5.5). Detalle de persistencia; el modelo de dominio
 * es {@see \App\Modules\Workforce\Domain\Model\Site}.
 *
 * `compliance_profile_id` y `settings` existen en la tabla y **no son
 * `fillable`**: los gobierna `Product` (perfil de cumplimiento y configuracion
 * de instalacion), y este modulo no tiene por que poder escribirlos.
 *
 * Sin `updated_at`: la tabla solo tiene `created_at` (doc 01 §5.5).
 *
 * @property int $id
 * @property string $name
 * @property string $timezone
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> query()
 */
final class Site extends Model
{
    public $timestamps = false;

    protected $table = 'sites';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'timezone',
    ];
}

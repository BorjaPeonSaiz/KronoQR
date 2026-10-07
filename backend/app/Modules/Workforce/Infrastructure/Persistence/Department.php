<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

/**
 * Fila de `departments` (doc 01 §5.5). Detalle de persistencia; el modelo de
 * dominio es {@see \App\Modules\Workforce\Domain\Model\Department}.
 *
 * `manager_user_id` no es `fillable`: apunta a `users`, que es tabla de otro
 * modulo, y concede alcance sobre personas (RF-ID-03). Solo lo escribe
 * `EloquentDepartmentRepository::assignManager()`, al que llega una cuenta ya
 * comprobada por `UpdateDepartmentHandler` y con su asiento (RF-ID-10, ADR-051
 * §5); un `fill()` con el cuerpo de una peticion no puede tocarlo.
 *
 * La tabla no tiene marcas de tiempo (doc 01 §5.5).
 *
 * @property int $id
 * @property int $site_id
 * @property string $name
 * @property int|null $manager_user_id
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> query()
 */
final class Department extends Model
{
    public $timestamps = false;

    protected $table = 'departments';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'site_id',
        'name',
    ];
}

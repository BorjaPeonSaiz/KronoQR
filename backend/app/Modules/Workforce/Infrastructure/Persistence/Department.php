<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

/**
 * Fila de `departments` (doc 01 §5.5). Detalle de persistencia; el modelo de
 * dominio es {@see \App\Modules\Workforce\Domain\Model\Department}.
 *
 * `manager_user_id` no es `fillable`: apunta a `users`, que es tabla de otro
 * modulo, y solo tiene efecto con el ambito por departamento de RF-ID-03 (tarea
 * 2.1). Escribirlo desde aqui seria prometer un control de acceso que todavia no
 * se aplica.
 *
 * La tabla no tiene marcas de tiempo (doc 01 §5.5).
 *
 * @property int $id
 * @property int $site_id
 * @property string $name
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

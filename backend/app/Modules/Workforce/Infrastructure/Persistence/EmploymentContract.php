<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Fila de `employment_contracts` (doc 01 §5.5, RF-GP-02). **Detalle de
 * persistencia**, no el modelo de dominio: ese es
 * {@see \App\Modules\Workforce\Domain\Model\EmploymentContract} y es el unico
 * que sale de este modulo hacia arriba.
 *
 * Los dos se llaman igual a proposito, por lo mismo que `Employee`: es la
 * convencion de Laravel para el modelo y la del dominio para la entidad, y el
 * repositorio los distingue con un alias. `EmploymentContractModel` meteria el
 * patron en el nombre, que es lo que el §3.5 pide no hacer.
 *
 * **Sin `updated_at`.** Un contrato no se edita: se cierra, y cerrarlo es fijar
 * `valid_to`, que antes no se sabia (regla dura 5). `$timestamps = false` porque
 * `created_at` lo escribe el repositorio con el instante del puerto `Clock`, no
 * Eloquent con el reloj del proceso.
 *
 * @property int $id
 * @property int $employee_id
 * @property string $weekly_hours
 * @property string|null $annual_hours
 * @property string $schedule_type
 * @property Carbon $valid_from
 * @property Carbon|null $valid_to
 * @property Carbon $created_at
 * @property int|null $created_by_user_id
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> query()
 */
final class EmploymentContract extends Model
{
    protected $table = 'employment_contracts';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'employee_id',
        'weekly_hours',
        'annual_hours',
        'schedule_type',
        'valid_from',
        'valid_to',
        'created_at',
        'created_by_user_id',
    ];

    /**
     * `weekly_hours` y `annual_hours` **no se convierten a `float` aqui** y es
     * deliberado: PostgreSQL devuelve `numeric` como cadena, y dejarla pasar tal
     * cual permite que el repositorio decida donde entra la coma flotante. Las
     * fechas si, porque una vigencia se compara y no se imprime.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valid_from' => 'immutable_date',
            'valid_to' => 'immutable_date',
            'created_at' => 'immutable_datetime',
        ];
    }
}

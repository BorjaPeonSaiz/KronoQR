<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Fila de `absences` (doc 01 §5.5, RF-GP-04). **Detalle de persistencia**, no el
 * modelo de dominio: ese es {@see \App\Modules\Workforce\Domain\Model\Absence} y
 * es el unico que sale de este modulo hacia arriba.
 *
 * Los dos se llaman igual a proposito, por lo mismo que `Employee` y
 * `EmploymentContract`: es la convencion de Laravel para el modelo y la del
 * dominio para la entidad, y el repositorio los distingue con un alias.
 * `AbsenceModel` meteria el patron en el nombre, que es lo que el §3.5 pide no
 * hacer.
 *
 * **Sin `updated_at`.** Una ausencia no se edita: se corrige creando una version
 * nueva, o se anula (regla dura 5). `$timestamps = false` porque `created_at` lo
 * escribe el repositorio con el instante del puerto `Clock`, no Eloquent con el
 * reloj del proceso: sin eso, una prueba con el reloj congelado escribiria en la
 * fila una fecha distinta de la del asiento que la acompaña.
 *
 * **Sin `SoftDeletes` y sin `delete()` en ningun sitio.** No hay borrado en
 * ninguna capa de este producto.
 *
 * @property int $id
 * @property string $uuid
 * @property int $employee_id
 * @property string $type
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property string|null $note
 * @property string $status
 * @property int $version
 * @property int|null $supersedes_id
 * @property int|null $superseded_by_id
 * @property string|null $change_reason
 * @property Carbon|null $voided_at
 * @property int|null $voided_by_user_id
 * @property string|null $void_reason
 * @property Carbon $created_at
 * @property int|null $created_by_user_id
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> query()
 */
final class Absence extends Model
{
    protected $table = 'absences';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'employee_id',
        'type',
        'starts_on',
        'ends_on',
        'note',
        'status',
        'version',
        'supersedes_id',
        'superseded_by_id',
        'change_reason',
        'voided_at',
        'voided_by_user_id',
        'void_reason',
        'created_at',
        'created_by_user_id',
    ];

    /**
     * Las fechas se convierten porque una ausencia se compara y no se imprime;
     * `version` a entero porque PostgreSQL devuelve `smallint` como cadena y el
     * dominio lo quiere tipado.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'voided_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }
}

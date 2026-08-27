<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Fila de `credentials` (doc 01 §5.5). **Detalle de persistencia**, no el modelo
 * de dominio: ese es {@see \App\Modules\Identity\Domain\Model\Credential} y es el
 * unico que sale de este modulo hacia arriba.
 *
 * **`key_id` y `secret_hash` son nulos hasta que se imprime** (ADR-034): el
 * token se acuña dentro del acto que dibuja el PDF de la tarjeta, no al emitir.
 * Una fila con las dos en `NULL` es una credencial pendiente de imprimir, y no
 * la resuelve ninguna busqueda por hash.
 *
 * **`secret_hash` esta en `hidden` y no es casualidad.** Aunque sea un hash y no
 * el token, es lo que la verificacion usa como llave de busqueda: publicarlo en
 * una respuesta, en un `dd()` o en un `toArray()` de depuracion daria a quien lo
 * viera la mitad del trabajo hecho para reconocer una tarjeta concreta. Lo que
 * no aparece por defecto no se filtra por descuido.
 *
 * @property int $id
 * @property string $uuid
 * @property int $employee_id
 * @property string|null $key_id
 * @property string|null $secret_hash
 * @property Carbon $issued_at
 * @property Carbon|null $printed_at
 * @property Carbon|null $delivered_at
 * @property int|null $delivered_by_user_id
 * @property Carbon|null $revoked_at
 * @property string|null $revoked_reason
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> query()
 */
final class Credential extends Model
{
    protected $table = 'credentials';

    /**
     * `credentials` no tiene `created_at` ni `updated_at`: su linea de tiempo son
     * las cinco marcas del ciclo de vida —emision, impresion, entrega,
     * revocacion— y anadir dos columnas mas seria tener dos versiones del mismo
     * hecho.
     */
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'employee_id',
        'key_id',
        'secret_hash',
        'issued_at',
        'printed_at',
        'delivered_at',
        'delivered_by_user_id',
        'revoked_at',
        'revoked_reason',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'secret_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'delivered_by_user_id' => 'integer',
            // `datetime` y no `immutable_datetime` por coherencia con el resto de
            // modelos del producto: el repositorio convierte a
            // `DateTimeImmutable` al cruzar hacia el dominio, que es donde la
            // inmutabilidad importa.
            'issued_at' => 'datetime',
            'printed_at' => 'datetime',
            'delivered_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}

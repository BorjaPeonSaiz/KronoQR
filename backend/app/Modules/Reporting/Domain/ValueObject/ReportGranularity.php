<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

/**
 * Con que grano se agrupan las jornadas del informe por periodo (**RF-IN-01**).
 *
 * ## Se agrupa por `work_date`, nunca por la hora de las marcas
 *
 * Los cuatro valores parten de `daily_totals.work_date`, que es una **fecha
 * civil en la zona del centro** y ya lleva aplicada RN-05: el turno 22:00 →
 * 06:00 esta atribuido entero al dia en que empezo. Agrupar por
 * `date_trunc('day', clocked_in_at)` en UTC —el error que la skill
 * `/informe-nuevo` señala por escrito— partiria ese turno entre dos dias en
 * invierno y lo movería de dia en verano, segun el desplazamiento horario.
 *
 * Como consecuencia, **aqui no hay ninguna conversion de zona horaria**, y esa
 * ausencia es la garantia: no hay ningun `AT TIME ZONE` que pueda estar mal
 * puesto porque no hay ningun instante que convertir.
 *
 * ## `Week` es la semana ISO, y eso es una decision
 *
 * `date_trunc('week', ...)` de PostgreSQL empieza en lunes, que es la semana ISO
 * 8601 y la que usa el convenio de hosteleria español. El perfil de cumplimiento
 * tiene un `week_starts_on` (doc 01 §5.5) que **este informe todavia no
 * consulta**: hacerlo bien exige un puerto que lo entregue resuelto (regla dura
 * 14) y afecta ademas a RN-11, que compara horas semanales. Queda anotado como
 * deuda explicita en lugar de resuelto a medias, y mientras tanto la semana que
 * usa el informe sale escrita en `meta.criteria` para que nadie tenga que
 * suponerla.
 *
 * ## `Range` no es «sin agrupar»
 *
 * Es **una sola fila por sujeto** con el rango entero: la pregunta «¿cuantas
 * horas hizo esta persona entre el 1 y el 17?». Sin este valor, esa pregunta
 * obligaria a pedir la granularidad diaria y sumar en el cliente, que es como se
 * acaba teniendo dos formas distintas de calcular el mismo total.
 */
enum ReportGranularity: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Range = 'range';

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $granularity): string => $granularity->value, self::cases());
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

/**
 * Que grupos se piden: los abiertos, los resueltos o todos (RF-PD-15,
 * parametro `status` del contrato).
 *
 * **No es un estado de la fila y por eso no vive en ella.** En la tabla solo hay
 * `resolved_at`: un grupo esta abierto si es nulo. Esto es un **filtro**, y
 * tenerlo como enum en vez de como tres cadenas sueltas es lo que permite que la
 * validacion del `FormRequest`, la consulta y el comando de consola compartan el
 * mismo catalogo — que es como se evita que `--status=abierto` devuelva la tabla
 * entera en silencio.
 *
 * **`Open` por omision**, que es la pregunta de quien abre la pantalla: «¿que
 * tengo pendiente?». Un historico sin filtro de situacion serian noventa dias de
 * todo con una columna de estado, que no es la pantalla que RF-PD-15 describe.
 */
enum ErrorEventStatusFilter: string
{
    case Open = 'open';

    case Resolved = 'resolved';

    case All = 'all';

    /** Lo que se pide sin decir nada. Ver el docblock. */
    public static function default(): self
    {
        return self::Open;
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}

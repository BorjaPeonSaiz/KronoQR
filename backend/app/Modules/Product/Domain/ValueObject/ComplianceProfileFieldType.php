<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

/**
 * La forma del valor de un campo del perfil de cumplimiento.
 *
 * Tres y no mas, porque son las tres que el perfil tiene. Un tipo por si acaso
 * es superficie que documentar, validar y traducir.
 */
enum ComplianceProfileFieldType: string
{
    /** Horas o años enteros, con su minimo y su maximo. */
    case Integer = 'integer';

    /** El nombre del convenio. */
    case Text = 'text';

    /** Lista de fechas ISO `AAAA-MM-DD`, sin repetidos. */
    case DateList = 'date_list';
}

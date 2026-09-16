<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Port;

use App\Modules\Reporting\Domain\Exception\ReportTooLargeForSynchronousDelivery;
use App\Modules\Reporting\Domain\ValueObject\ComplianceFacts;
use App\Modules\Reporting\Domain\ValueObject\ComplianceSummaryQuery;

/**
 * Las jornadas del alcance sobre las que se evalua el cumplimiento
 * (**RF-PA-06**).
 *
 * ## Trae hechos, no veredictos
 *
 * Ni un `CASE WHEN … > 12` en el SQL. Las cuatro comparaciones viven en el
 * dominio (reglas duras 1 y 2), y la razon practica es la del paso 4 de la ficha:
 * si el umbral entrara en la consulta, la vista y la bandeja compararian en dos
 * sitios distintos y algun dia dejarian de contar lo mismo.
 *
 * ## El rango que se carga es MAS ANCHO que el pedido, y a proposito
 *
 * Dos veces:
 *
 *   - **Una jornada antes de `from` por persona**, para que la primera del rango
 *     tenga su `previousLastOutAt` y RN-10 se evalue en ella igual que en las
 *     demas. Sin eso, el primer dia de cualquier ventana nunca alertaria de
 *     descanso, y nadie lo notaria: el aviso simplemente no saldria.
 *   - **Los dias de fuera que completan las semanas del borde**, porque toda
 *     semana que toque el rango se evalua sobre sus siete dias (decision 6). Una
 *     semana recortada daria un total que no suma lo que la persona ve en su
 *     registro.
 *
 * Quien decide cuanto se amplia es el adaptador, que conoce `week_starts_on`; el
 * caso de uso pide el rango que le pidieron.
 *
 * ## El alcance entra en el `WHERE`
 *
 * RF-ID-03. Nunca se filtra una lista ya traida: los recuentos de `meta.totals`
 * se calculan sobre lo que devuelve esto, asi que un filtro posterior describiria
 * a personas que quien pregunta no puede ver.
 */
interface ComplianceFactsReader
{
    /**
     * Una fila por jornada con actividad, de todas las personas del alcance,
     * ordenadas por persona y fecha.
     *
     * @return list<ComplianceFacts>
     *
     * @throws ReportTooLargeForSynchronousDelivery cuando PostgreSQL cancela la consulta por
     *                                              `statement_timeout`: el mismo `422` del
     *                                              informe por periodo
     */
    public function factsFor(ComplianceSummaryQuery $query, int $weekStartsOn): array;
}

<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

/**
 * Sobre que sujeto se agregan las horas del informe (**RF-IN-02**).
 *
 * ## Los tres agregados que pide RF-IN-02
 *
 * Por persona, por departamento y por centro. Son tres preguntas distintas y no
 * tres vistas de la misma: «cuanto ha trabajado Lucia» se responde con su
 * registro, «cuanto ha trabajado Cocina» con la suma de su gente —incluida la
 * que ya no esta (RN-14)— y «cuanto ha trabajado el hotel» con todo.
 *
 * ## El alcance se aplica antes de agregar, no despues
 *
 * RF-ID-03 y regla dura 18: un responsable de Cocina no obtiene datos de
 * Recepcion **ni agregados**. Un total por centro calculado sobre todas las
 * filas y servido despues a quien solo alcanza un departamento seria una fuga
 * por agregacion —de un total y una plantilla se deduce una media—, asi que el
 * filtro entra en el `WHERE` de la consulta y los agregados se calculan ya
 * acotados. Para quien tiene un solo departamento, `Site` y `Department`
 * devuelven la misma cifra, y eso es correcto: es todo lo que alcanza.
 *
 * ## `Site` existe aunque haya un solo centro
 *
 * ADR-040: una instalacion tiene exactamente un centro. El valor no sirve para
 * elegir cual, sirve para pedir **el total sin desglosar**, que es la cifra de
 * portada del informe. Si algun dia hubiera mas de uno, este es el enumerado
 * donde crecerian los ejes, no cada consulta.
 */
enum ReportGrouping: string
{
    case Employee = 'employee';
    case Department = 'department';
    case Site = 'site';

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $grouping): string => $grouping->value, self::cases());
    }
}

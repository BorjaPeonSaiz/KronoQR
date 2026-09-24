<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

use DateTimeImmutable;

/**
 * El cuadro de impacto y adopcion terminado (**RF-IN-08**).
 *
 * ## Lo que hay aqui dentro, y lo que deliberadamente no
 *
 * Hay doce indicadores, un reparto por origen, dos periodos, un instante, una zona
 * y una lista de criterios **sin traducir**. No hay ni un `employee_uuid`, ni un
 * nombre, ni un `department_id`, y esa ausencia es una decision de privacidad y no
 * una limitacion: con desglose por departamento, un departamento de una persona
 * convertiria «horas trabajadas» en su dato individual, servido en una pantalla
 * cuya finalidad es medir la adopcion del sistema y no evaluar a nadie (regla dura
 * 21, decision 12 de la ficha 3.13).
 *
 * Es tambien la razon por la que **leer este cuadro no escribe asiento de
 * divulgacion** en `audit_log`: no hay dato personal que divulgar. Exportarlo si
 * lo escribe, porque produce un documento que sale del sistema.
 *
 * ## Los criterios viajan como claves
 *
 * El dominio no tiene idioma. Los traduce el `Resource` con el idioma de la
 * peticion y la exportacion con el de la **instalacion** —un documento lo abre un
 * tercero—, exactamente igual que en el informe por periodo y en la vista de
 * cumplimiento.
 *
 * ## `generatedAt` va en UTC
 *
 * Regla dura 3. La conversion a la hora que vivio quien lee el papel ocurre en la
 * presentacion, y la zona del centro viaja al lado en `timeZone` para que ninguna
 * pantalla la adivine.
 */
final readonly class AdoptionReport
{
    /**
     * @param  list<AdoptionIndicator>  $indicators  Los doce, en orden de lectura.
     * @param  list<AdoptionOriginShare>  $originBreakdown  Los cuatro origenes, sumando 100.
     * @param  list<string>  $criteria  Claves de `lang/*\/reports.php`, sin traducir.
     */
    public function __construct(
        public array $indicators,
        public array $originBreakdown,
        public DateRange $range,
        public DateRange $previousRange,
        public string $timeZone,
        public DateTimeImmutable $generatedAt,
        public array $criteria,
    ) {}

    /**
     * Cuantas filas de datos lleva el cuadro: un indicador, una fila.
     *
     * Es lo que va en `X-Kronoqr-Report-Rows`, y sale de aqui en lugar de contarse
     * en cada escritor por lo mismo que la huella: tres recuentos son tres formas
     * de equivocarse en un numero que existe para comprobar que la descarga llego
     * entera.
     */
    public function rowCount(): int
    {
        return \count($this->indicators);
    }

    /**
     * El indicador de una clave, o `null` si no esta.
     *
     * Existe para las pruebas y para los escritores: recorrer la lista buscando una
     * clave es lo que se acaba escribiendo tres veces con tres `?:` distintos.
     */
    public function indicator(AdoptionIndicatorKey $key): ?AdoptionIndicator
    {
        foreach ($this->indicators as $indicator) {
            if ($indicator->key === $key) {
                return $indicator;
            }
        }

        return null;
    }
}

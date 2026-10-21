<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Exception;

/**
 * Marca una excepcion como **rechazo de negocio**: una regla que dice que no, un
 * recurso que no existe, unas credenciales que no valen, un estado que no admite
 * la operacion. Es el producto funcionando, no una averia (doc 02 §8).
 *
 * ## Para que sirve
 *
 * `UseCaseInstrumentation` cuenta cada caso de uso como `ok`, `rejected` o
 * `error`, y solo `error` es una averia. Hasta la 2.3.0 lo decidia por la
 * carpeta donde vivia la excepcion, y de las 129 de los modulos clasificaba
 * 124 como `rejected`: entre ellas el disco lleno de una exportacion
 * (`DataExportWriteFailed`), el SMTP que no responde
 * (`WeeklySummaryNotDelivered`) o un instante que llega sin UTC
 * (`InstantIsNotUtc`), que es un fallo del programa (V21-1-AR-1).
 *
 * Ahora **se declara**: una excepcion es un rechazo si implementa esta interfaz,
 * y si no, es una averia. Lo que no se ha pensado cuenta como averia, que es el
 * error barato: una alerta de mas se mira y se corrige; una averia contada como
 * rechazo no la ve nadie.
 *
 * ## Por que en `Shared\Domain` y en cada hoja
 *
 * En `Shared\Domain` porque es lo unico que alcanzan a la vez el dominio y la
 * aplicacion de todos los modulos sin romper la regla dura 1 (Deptrac). Es una
 * interfaz sin metodos: el dominio no gana ninguna dependencia de
 * infraestructura.
 *
 * En cada clase final y no en las bases de modulo (`AttendanceDomainException`,
 * `ProductDomainException`…) porque bajo una misma base conviven rechazos y
 * averias, y heredar el marcador clasificaria mal a las segundas.
 * `BusinessRejectionClassificationTest` recorre todas las excepciones del
 * producto y exige que cada una este en uno de los dos lados a proposito.
 */
interface BusinessRejection {}

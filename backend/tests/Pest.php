<?php

declare(strict_types=1);

use Tests\Support\Database\TestDatabase;
use Tests\Support\Product\ErrorHistoryConnection;
use Tests\TestCase;

/*
 * Configuracion de Pest — doc 02 §2 (las cinco suites) y §9.1 (la piramide).
 *
 * Solo Feature, Integration y Contract extienden el TestCase de Laravel. Unit y
 * Architecture corren sobre PHPUnit puro, sin arrancar el framework ni tocar la
 * base de datos: es lo que mantiene la suite Unit por debajo de 2 s (CLAUDE.md)
 * y lo que hace que una prueba de dominio siga valiendo si algun dia cambia el
 * framework. Si una prueba de Unit necesita el contenedor de servicios, esta en
 * la suite equivocada, no le falta configuracion.
 */
pest()->extend(TestCase::class)->in('Feature', 'Integration', 'Contract');

/*
 * La suite de integracion corre contra PostgreSQL de verdad —es su razon de
 * ser: las restricciones declarativas de RN-01..03 no existen en SQLite— y
 * contra una base propia, `fichaje_test`, que nunca es la de desarrollo.
 *
 * La comprobacion va en `beforeAll` y no en `beforeEach` porque
 * `RefreshDatabase` migra en el `setUp` de cada prueba: cuando `beforeEach`
 * corre, ya seria tarde. El porque de la base aparte esta en
 * Tests\Support\Database\TestDatabase.
 */
/*
 * Feature entra en la misma comprobacion desde la tarea 1.6: sus pruebas llaman
 * a endpoints que leen y escriben de verdad —alta de empleado, acceso al panel—
 * y necesitan la misma base `fichaje_test`. Antes no la necesitaban porque no
 * habia endpoints.
 */
pest()->beforeAll(fn () => TestDatabase::ensureExists())->in('Integration', 'Feature');

/*
 * El historico de errores comparte la sesion de la prueba y empieza vacio
 * (RF-PD-15, tarea 5.12).
 *
 * POR QUE ES GLOBAL Y NO FICHERO A FICHERO. `error_events` se escribe por una
 * conexion propia -otra sesion de PostgreSQL- para que guardar un error no
 * dependa de la transaccion que acaba de fallar, que es justo su razon de ser
 * (decision 6). El precio en pruebas es que **cualquier** prueba que provoque un
 * `500` -las de captacion, las de autorizacion negativa, las que rompen algo a
 * proposito- confirma filas que sobreviven a su propio `RefreshDatabase` y
 * contaminan a las siguientes. Con el enganche aqui, toda la suite `Feature`
 * empieza con la tabla vacia y lo que escriba se revierte con ella.
 *
 * Lo hace {@see ErrorHistoryConnection::shareTestTransaction()}, que no toca
 * nada si no hay transaccion envolvente.
 *
 * NO SE APLICA A `Integration` a proposito: alli estan las pruebas de
 * concurrencia y la que comprueba que **la escritura sobrevive a la transaccion
 * que fallo**, y esa afirmacion solo se puede hacer con las dos sesiones de
 * verdad, como corre el producto. Las de integracion que si lo necesitan lo
 * llaman a mano.
 */
pest()->beforeEach(fn () => ErrorHistoryConnection::shareTestTransaction())->in('Feature');

/*
 * Y SE DESHACE AL TERMINAR CADA PRUEBA, que es la otra mitad y la que costo
 * encontrar.
 *
 * El puente deja la conexion `error_events` agarrada al PDO de la conexion por
 * defecto de esa prueba. Cuando `RefreshDatabase` revierte y desconecta al
 * terminar, ese descriptor muere y el objeto se queda contaminado dentro del
 * `DatabaseManager` **para el resto del proceso**: la siguiente prueba que use
 * `CommittedDatabase` -que recrea el esquema y no envuelve en transaccion-
 * fallaba con `relation "error_events" does not exist`, y arrastraba a las de
 * detras. En aislado pasaban todas.
 *
 * `purge()` saca la conexion del gestor; la proxima prueba construye una limpia.
 */
pest()->afterEach(fn () => ErrorHistoryConnection::release())->in('Feature');

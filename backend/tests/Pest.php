<?php

declare(strict_types=1);

use Tests\Support\Database\TestDatabase;
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

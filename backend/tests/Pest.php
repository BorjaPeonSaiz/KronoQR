<?php

declare(strict_types=1);

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

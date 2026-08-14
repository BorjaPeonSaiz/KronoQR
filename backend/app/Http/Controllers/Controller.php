<?php

declare(strict_types=1);

namespace App\Http\Controllers;

/**
 * Controlador base del que cuelgan los de cada modulo (Modules/{Modulo}/Http).
 *
 * Sin logica de negocio en controladores (doc 02 §3.5): un controlador valida
 * con FormRequest, autoriza con Policy, invoca un caso de uso y serializa con
 * Resource. Nada mas.
 */
abstract class Controller {}

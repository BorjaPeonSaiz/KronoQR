<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Exception;

/**
 * Raiz de los choques con el estado actual: un nombre o un correo ya usados.
 *
 * Se traducen a `409` y no a `422` porque la peticion es valida en si misma; lo
 * que no encaja es el estado del sistema. Al cliente le importa la diferencia:
 * ante un `409` no sirve corregir el formulario, hay que releer el recurso.
 *
 * **Se lanzan desde el repositorio, al chocar con el UNIQUE, y no desde una
 * consulta previa.** Comprobar antes con un `SELECT` es una condicion de carrera
 * con aspecto de comprobacion: dos altas simultaneas la pasan las dos.
 *
 * **Vive en `Domain/Exception` y no en `Application/`** porque la unicidad del
 * codigo de empleado, del correo y del nombre de centro son reglas del dominio
 * —la base de datos es donde se hacen cumplir, no donde se deciden— y porque un
 * puerto solo puede hablar en tipos del dominio propio, de Shared o escalares
 * (ADR-025, restriccion 2): los repositorios las declaran en su `@throws`.
 */
abstract class WorkforceConflict extends WorkforceDomainException {}

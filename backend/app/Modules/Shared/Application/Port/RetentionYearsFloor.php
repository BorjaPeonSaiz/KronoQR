<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

/**
 * El minimo de años de conservacion del registro que el perfil de cumplimiento
 * admite (RL-02, RF-PD-07, regla dura 14).
 *
 * Lo pide la conciliacion del registro con su auditoria (ADR-057 §4) para
 * descartar un asiento de purga que dice haber conservado menos de lo que el
 * producto permite configurar. Es el **suelo** del campo, no el plazo vigente:
 * un plazo subido despues de una purga legitima no puede convertirla en
 * sospechosa.
 *
 * Puerto en `Shared` y adaptador en `Product`, que es quien define los limites
 * del perfil y a quien `Compliance` no puede importar (doc 02 §1.6).
 */
interface RetentionYearsFloor
{
    public function minimumRetentionYears(): int;
}

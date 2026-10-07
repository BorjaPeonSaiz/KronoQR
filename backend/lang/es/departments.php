<?php

declare(strict_types=1);

/*
 * Textos de los departamentos que ve una persona del panel (RF-GP-01,
 * RF-ID-10).
 *
 * El del responsable es UNO para tres causas —la cuenta no existe, está de
 * baja o tiene otro rol— a propósito (ADR-051 §5): distinguirlas diría si una
 * cuenta existe. No nombra a nadie (regla dura 21).
 */

return [

    'errors' => [

        'manager_not_eligible' => 'Elige una cuenta de gestión activa con el rol de responsable de departamento.',
    ],

];

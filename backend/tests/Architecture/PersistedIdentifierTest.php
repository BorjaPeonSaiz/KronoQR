<?php

declare(strict_types=1);

use Tests\Architecture\Support\ModuleTree;

/*
 * Un identificador nulo falla, no se convierte en cero (R1-AR-03).
 *
 * `$entidad->id ?? 0` al publicar un hecho dejaba en `audit_log` un asiento
 * sobre el sujeto 0, y en el verificador de la cadena una rotura en la fila 0:
 * dos cosas que no existen y que nadie ve hasta que hace falta encontrarlas.
 * La forma correcta es `persistedId()`, que lanza si la fila no se ha guardado,
 * o un identificador que el tipo ya garantiza.
 *
 * Se vigilan las cuatro capas: un adaptador que rellena un `siteId ?? 0` en una
 * instantanea que acaba en un asiento es el mismo fallo un piso mas abajo. Y no
 * solo `->id`: tambien `->siteId`, `->deviceId` y cualquier `…Id`.
 */

/**
 * Los `…Id ?? 0` que **no** son un identificador perdido, con su motivo. Cada
 * uno se comprueba: si el codigo cambia y la excepcion deja de existir, la
 * guarda falla para que se quite de aqui.
 *
 * @var array<string, string> fragmento de la linea => motivo
 */
const PERSISTED_IDENTIFIER_EXCEPTIONS = [
    // Es el filtro del panel, no una entidad: sin filtro el payload canonico de
    // la divulgacion lleva `0` en vez de una clave ausente.
    "Compliance/Application/UseCase/ReadIncidentBoard.php: 'department_id' => \$query->departmentId ?? 0," => 'filtro opcional',
    // A proposito: la consulta va tambien contra el dispositivo 0, que no
    // existe, para que los tres rechazos del emparejamiento cuesten lo mismo
    // (RS-03, `PairingRejectionTest`).
    'Kiosk/Application/UseCase/ClaimPairing.php: $used = $this->devices->hasUsedAccessToken($request->deviceId ?? 0);' => 'tiempo constante',
];

it('no convierte en cero el identificador de una entidad en ninguna capa', function (): void {
    $offenders = [];
    $seenExceptions = [];
    $files = [];

    foreach (['Domain', 'Application', 'Infrastructure', 'Http'] as $layer) {
        $files = [...$files, ...ModuleTree::filesInLayer(ModuleTree::MODULES, $layer)];
    }

    foreach ($files as $file) {
        $relative = ModuleTree::relative($file);

        foreach (file($file) ?: [] as $number => $line) {
            if (preg_match('/->(?:id|\w+Id)\s*\?\?\s*0(?!\d)/', $line) !== 1) {
                continue;
            }

            $key = $relative.': '.trim($line);

            if (isset(PERSISTED_IDENTIFIER_EXCEPTIONS[$key])) {
                $seenExceptions[] = $key;

                continue;
            }

            $offenders[] = $relative.':'.($number + 1);
        }
    }

    expect(count($files))->toBeGreaterThan(1000)
        ->and($offenders)->toBe([])
        ->and($seenExceptions)->toEqualCanonicalizing(array_keys(PERSISTED_IDENTIFIER_EXCEPTIONS));
})->group('RL-04', 'RNF-M-03');

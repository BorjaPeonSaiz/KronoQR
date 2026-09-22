<?php

declare(strict_types=1);

use Tests\Architecture\Support\ClientDocs;
use Tests\Architecture\Support\SecurityReview;

/*
 * RS-11 —«revisión de seguridad externa antes de la primera versión comercial y
 * con periodicidad anual»— sostenido por la evidencia del proceso (§6 del
 * informe, H-11, decision 7 de la ficha 3.8).
 *
 * ## LO QUE ESTA PRUEBA NO AFIRMA, Y HAY QUE LEERLO PRIMERO
 *
 * **No afirma que la revision externa se hiciera.** Ni que la hiciera alguien
 * competente, ni que encontrara nada, ni que sus hallazgos se cerraran. Nada de
 * eso lo puede demostrar una prueba: son artefactos humanos —el informe del
 * tercero y su cierre— y lo unico que los demuestra es leerlos.
 *
 * Tampoco afirma que el producto sea seguro, ni que los once capitulos ASVS
 * esten cumplidos: solo que el informe **dictamina sobre los once**.
 *
 * ## Lo que si afirma, y por que vale la pena
 *
 * Que los artefactos del proceso existen, estan completos, no han caducado y no
 * filtran nada. Es el mismo razonamiento de `ClientDocumentationTest` con RL-21:
 * la parte automatizable de un requisito de proceso es su rastro.
 *
 * Y una de las cinco cosas que comprueba es la que de verdad trabaja sola: la
 * **fecha limite de la siguiente revision**. RS-11 dice «anual», y «anual» es
 * justo el tipo de compromiso que se incumple sin que nadie tome una decision de
 * incumplirlo. Esta prueba se pondra en rojo el dia que la fecha pase, en la CI,
 * sin que nadie tenga que acordarse.
 *
 * ## Por que puede leer el reloj del sistema
 *
 * Porque no es dominio. La regla dura 2 prohibe leer la hora en
 * `app/Modules/*\/Domain` —y ahi lo vigilan `DomainPurityTest` y la regla
 * `kronoqr-domain-lee-el-reloj-del-sistema` de Semgrep, cuyo `paths.include` es
 * exactamente ese arbol—. Aqui el reloj **es el sujeto**: una caducidad que se
 * comprobara contra una fecha congelada no caducaria nunca, que es la unica
 * forma de escribir mal esta prueba.
 *
 * Aun asi se comprueba dos veces y por separado, porque son dos preguntas
 * distintas: que el plazo declarado **respete la periodicidad** (contra una
 * referencia fija leida del propio informe, sin reloj) y que el plazo **no haya
 * vencido** (contra el reloj, que es lo que lo convierte en un aviso).
 */

/** Los once capitulos ASVS del doc 02 §7.6, como abren su fila en la tabla del §3. */
const CAPITULOS_ASVS = [
    '**V1 ', '**V2 ', '**V3 ', '**V4 ', '**V5 ', '**V7 ',
    '**V8 ', '**V9 ', '**V12 ', '**V13 ', '**V14 ',
];

it('deja escrito un informe de revision interna con fecha en el nombre', function (): void {
    // El control que impide que todo lo de abajo pase por vacio: sin informe, la
    // lectura de los apartados no tendria nada que recorrer y una prueba sobre
    // una lista vacia da verde.
    expect(SecurityReview::reports())->not->toBe(
        [],
        'No hay ningun `docs/seguridad/revision-interna-asvs-AAAA-MM.md`. La fecha va en el NOMBRE y no solo '
        .'dentro: es lo que permite saber cuando fue la ultima sin abrir el fichero.',
    );
})->group('RS-11', 'RQ-13');

it('exige que el informe diga sobre que commit dictamina y a que nivel', function (): void {
    // Un informe de seguridad sin commit es una opinion sobre un producto que ya
    // no existe: entre la revision y la publicacion hay semanas de cambios, y sin
    // el ancla nadie puede decir si un hallazgo sigue vivo. El nivel objetivo
    // —ASVS 2, doc 02 §7.6— es la otra mitad: sin el, «cumple» no significa nada.
    $faltan = SecurityReview::missingFrom(['Commit revisado', 'Nivel objetivo'], SecurityReview::latestReport());

    expect($faltan)->toBe([], 'El informe no declara: '.implode(', ', $faltan).'.');
})->group('RS-11', 'RQ-13');

it('exige que el informe dictamine sobre los once capitulos ASVS del nivel objetivo', function (): void {
    // Los once del doc 02 §7.6, literal. No se comprueba el veredicto —eso es
    // criterio, y lo emite quien revisa— sino que ninguno se haya quedado sin
    // mirar: un capitulo ausente se lee igual que un capitulo en verde.
    $faltan = SecurityReview::missingFrom(CAPITULOS_ASVS, SecurityReview::latestReport());

    expect($faltan)->toBe(
        [],
        \count($faltan).' capitulo(s) ASVS sin dictamen en el informe: '.implode(', ', $faltan)
        .'. Los once del doc 02 §7.6 son V1, V2, V3, V4, V5, V7, V8, V9, V12, V13 y V14.',
    );
})->group('RS-11', 'RQ-13');

it('deja el indice del paquete del revisor con todos sus enlaces resueltos', function (): void {
    // El paquete del paso 3 de la ficha —arquitectura, diseño de seguridad,
    // contrato, trazabilidad y ADRs— no es una lista de nombres: es una lista de
    // enlaces, y se entrega a alguien de fuera. Un enlace roto en ese indice es
    // un entregable que el revisor no recibe y que nadie echa de menos hasta la
    // reunion de cierre.
    expect(ClientDocs::exists(SecurityReview::PACKAGE_INDEX))
        ->toBeTrue('Falta '.SecurityReview::PACKAGE_INDEX.', el indice de lo que se entrega al revisor externo.');

    $rotos = SecurityReview::brokenLinks(SecurityReview::PACKAGE_INDEX);

    expect($rotos)->toBe([], \count($rotos).' enlace(s) del paquete no resuelven: '.implode('; ', $rotos));
})->group('RS-11', 'RQ-13');

it('declara una fecha limite de la siguiente revision que respeta la periodicidad anual', function (): void {
    // La mitad SIN reloj, y la que comprueba la regla: el plazo declarado tiene
    // que estar al menos 365 dias despues del informe que lo motiva. Si alguien
    // renovara la fecha limite copiandola de la anterior —o poniendo «el mes que
    // viene» para quitarse el rojo de encima— esta prueba lo veria; la de abajo,
    // no.
    //
    // La referencia se lee del propio informe (`| **Fecha** | AAAA-MM-DD |`), no
    // del reloj: asi el veredicto es el mismo hoy, en la CI y dentro de un año.
    $informe = SecurityReview::latestReport();
    $fechaDelInforme = SecurityReview::reportDate($informe);

    expect($fechaDelInforme)->not->toBe('', $informe.' no declara `| **Fecha** | AAAA-MM-DD |` en su cabecera.');

    $limite = SecurityReview::nextReviewDeadline();

    expect($limite)->not->toBe(
        '',
        SecurityReview::PACKAGE_INDEX.' no declara la fila «Siguiente revisión anual» con su «límite: AAAA-MM-DD».',
    );

    $minimo = (new DateTimeImmutable($fechaDelInforme))
        ->add(new DateInterval('P'.SecurityReview::ANNUAL_PERIOD_DAYS.'D'))
        ->format('Y-m-d');

    expect($limite)->toBeGreaterThanOrEqual(
        $minimo,
        'El paquete declara como limite '.$limite.', antes de los '.SecurityReview::ANNUAL_PERIOD_DAYS
        .' dias que RS-11 concede desde el informe del '.$fechaDelInforme.' (lo antes que puede ser: '.$minimo.').',
    );
})->group('RS-11', 'RQ-13');

it('no deja que venza la fecha limite de la siguiente revision', function (): void {
    // La mitad CON reloj, y la unica asercion de todo el repositorio que se pone
    // en rojo sola por el paso del tiempo. Es deliberado: «periodicidad anual» no
    // se incumple por una decision, se incumple por olvido, y un compromiso que
    // solo vive en un documento no avisa cuando caduca.
    //
    // Cuando falle, la correccion NO es mover la fecha: es encargar la revision.
    // Mover la fecha sin informe nuevo rompe la prueba de arriba.
    $limite = SecurityReview::nextReviewDeadline();
    $hoy = (new DateTimeImmutable('now'))->format('Y-m-d');

    expect($limite)->toBeGreaterThanOrEqual(
        $hoy,
        'La fecha limite de la siguiente revision de seguridad (RS-11) fue el '.$limite.' y hoy es '.$hoy
        .'. No se arregla cambiando la fecha de '.SecurityReview::PACKAGE_INDEX.': se arregla encargando la revision.',
    );
})->group('RS-11', 'RQ-13');

it('mantiene los informes de seguridad fuera del paquete que se entrega al cliente', function (): void {
    // Regla dura 16 y decision 2 de la ficha: `package.sh` copia `docs/cliente` y
    // `docs/runbooks`, y nada mas. Aqui dentro esta el detalle explotable de cada
    // hallazgo y el mapa de por donde entrar; entregarselo a cada cliente con su
    // instalacion seria repartir el plano de todas las demas.
    expect(SecurityReview::contents('infra/scripts/package.sh'))->not->toContain(
        SecurityReview::ROOT,
        'infra/scripts/package.sh cita `'.SecurityReview::ROOT.'`: el detalle de los hallazgos no viaja al cliente.',
    );
})->group('RS-11', 'RQ-13');

it('no publica en el informe ni en el paquete nada con forma de secreto real', function (): void {
    // RS-08 y regla dura 16, con el mismo criterio que `ClientDocumentationTest`:
    // la FORMA. Un valor largo y sin espacios detras de una variable que se llama
    // clave, secreto, contraseña o token.
    //
    // Aqui el riesgo es mayor que en las guias del cliente y de signo contrario:
    // estos documentos citan configuracion real del commit revisado y se entregan
    // **a alguien de fuera de la organizacion**. Un `.env` pegado «para que se
    // entienda el hallazgo» sale del repositorio dentro del paquete del revisor.
    $sospechosos = ClientDocs::secretLikeAssignments([
        SecurityReview::latestReport(),
        SecurityReview::PACKAGE_INDEX,
    ]);

    expect($sospechosos)->toBe(
        [],
        \count($sospechosos).' asignacion(es) con valor de aspecto real en la evidencia de seguridad: '
        .implode('; ', $sospechosos),
    );
})->group('RS-11', 'RQ-13');

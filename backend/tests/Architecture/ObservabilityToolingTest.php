<?php

declare(strict_types=1);

use Tests\Architecture\Support\AlertRules;
use Tests\Architecture\Support\Repo;

/*
 * LA PUERTA QUE VALIDA LA OBSERVABILIDAD (tarea 3.2, decision 2).
 *
 * ## Por que un fichero aparte y no dentro de `QualityGatesTest`
 *
 * `QualityGatesTest` afirma sobre la cadena de herramientas de la Fase 0 —Pint,
 * PHPStan, Deptrac, cobertura, mutacion—, que es una unidad con historia propia.
 * Lo de aqui es la cadena de la Fase 3 y se lee entera de un vistazo: reglas de
 * Prometheus, configuracion de Alertmanager y las pruebas de umbral de las
 * reglas. Mezclarlas dejaria un fichero de 500 lineas donde nadie encuentra nada.
 *
 * ## Lo que se erosiona sin estas pruebas
 *
 * Una regla de Prometheus es codigo que **solo se ejecuta el dia del incidente**.
 * Un `>` donde iba `<`, una unidad en segundos donde iba en minutos o un
 * parentesis mal cerrado no rompen nada: Prometheus rechaza el fichero entero al
 * arrancar —y entonces NINGUNA alerta se evalua— o evalua una condicion que no
 * se cumple nunca. Las dos formas terminan igual: el sistema parece vigilado y
 * no lo esta.
 *
 * `promtool test rules` es la unica forma de comprobar que una regla dispara al
 * cruzar su umbral y **no** justo por debajo, con series sinteticas y sin montar
 * nada. La norma que sale de ahi es la misma que rige el resto del repositorio:
 * **una regla sin prueba de umbral no entra**.
 *
 * ## Que NO se hace aqui
 *
 * No se ejecuta `promtool`: exige la imagen de Prometheus y esta suite corre sin
 * Docker. Lo que se comprueba es que la puerta existe, que esta atada al
 * `Makefile` y a la CI, y que ninguna regla se ha quedado sin caso de prueba —
 * que es lo que se erosiona en silencio cuando alguien añade la regla numero
 * veinte con prisa.
 */

/**
 * Los ficheros de pruebas de umbral, en rutas absolutas.
 *
 * Con `glob` y nunca con un iterador recursivo: sobre el montaje de Docker
 * Desktop el iterador omite ficheros sin avisar, y aqui un fichero omitido seria
 * un grupo de reglas que figura probado y no lo esta.
 *
 * @return list<string>
 */
function ficherosDePruebaDeReglas(): array
{
    $absolutos = glob(Repo::file('infra/observability/prometheus/tests').'/*.test.yml') ?: [];

    sort($absolutos);

    return $absolutos;
}

it('ata la validacion de reglas y de Alertmanager a un objetivo del Makefile', function (): void {
    // Una comprobacion que solo esta en la cabeza de quien la ejecuto no es una
    // puerta. `promtool check rules` caza el fichero que no parsea;
    // `promtool test rules` caza la regla que parsea y no dispara; `amtool
    // check-config`, el enrutado que deja un destinatario sin receptor.
    $makefile = Repo::contents('Makefile');

    expect($makefile)->toMatch('/^observability-check:/m');
    expect($makefile)->toContain('promtool');
    expect($makefile)->toContain('check rules');
    expect($makefile)->toContain('test rules');
    expect($makefile)->toContain('amtool');
    expect($makefile)->toContain('check-config');
})->group('RQ-14', 'RNF-M-06');

it('ejecuta esa validacion en la integracion continua', function (): void {
    // Un umbral que solo corre en el portatil de quien lo escribio no bloquea
    // nada. Mismo criterio que `make mutate` en la etapa ③: si no esta en la CI,
    // no es una puerta.
    expect(Repo::contents('.github/workflows/ci.yml'))->toContain('make observability-check');
})->group('RQ-14');

it('mantiene al menos un fichero de pruebas de umbral para las reglas', function (): void {
    // La red de seguridad de la prueba de abajo: sin ningun `.test.yml`, la
    // comprobacion «cada regla aparece nombrada» se quedaria comparando contra
    // el vacio y fallaria por el motivo correcto — pero este mensaje lo explica.
    expect(ficherosDePruebaDeReglas())->not->toBeEmpty(
        'No hay ningun infra/observability/prometheus/tests/*.test.yml. '
        .'Sin ellos, `promtool test rules` no comprueba ningun umbral.'
    );
})->group('RQ-14');

it('no deja ninguna regla de alerta sin un caso que compruebe su umbral', function (): void {
    // «Una regla sin prueba de umbral no entra.» Es la misma norma que el §9.5
    // aplica al codigo del producto, aplicada a la configuracion que decide si
    // alguien se entera de una averia. Se comprueba por NOMBRE de alerta: es lo
    // que `promtool` usa en `exp_alerts`/`alertname` y lo unico que ata un caso
    // a su regla.
    $casos = '';

    foreach (ficherosDePruebaDeReglas() as $fichero) {
        $casos .= (string) file_get_contents($fichero);
    }

    $sinPrueba = array_values(array_filter(
        AlertRules::names(),
        static fn (string $alerta): bool => ! str_contains($casos, $alerta),
    ));

    expect($sinPrueba)->toBe([], 'Estas alertas no aparecen en ninguna prueba de umbral.');
})->group('RQ-14', 'RF-PR-04');

it('declara la ventana de mantenimiento desde el actualizador, tambien cuando deshace', function (): void {
    // DECISION 6(c). `update.sh` corre en el anfitrion y Alertmanager no publica
    // puerto: el canal es el fichero `.prom` que ya sirve node-exporter. Las dos
    // series importan igual — la marca enciende la alerta informativa que
    // inhibe, y la marca de tiempo es lo que impide que una actualizacion que
    // muriera a medias dejara los quioscos silenciados para siempre.
    $actualizador = Repo::contents('infra/scripts/update.sh');

    expect($actualizador)
        ->toContain('kronoqr_maintenance_active')
        ->toContain('kronoqr_maintenance_since_timestamp_seconds');
})->group('RF-PD-10');

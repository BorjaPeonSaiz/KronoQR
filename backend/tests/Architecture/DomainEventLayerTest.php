<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\Event\DomainEvent;
use Tests\Architecture\Support\ModuleTree;

/*
 * **`Domain/Event/` es el vocabulario publico de un modulo, y por eso se vigila
 * quien vive ahi** (RF-PR-02, doc 02 §1.6, ADR-025).
 *
 * ## Por que este directorio es distinto de todos los demas
 *
 * Es el unico rincon del dominio de un modulo que **otro modulo puede
 * alcanzar**: Deptrac concede `ComplianceInfrastructure -> AttendanceDomainEvent`
 * —los listeners que sellan `audit_log` viven en `Compliance` y el nucleo no
 * puede importarlo— y no concede ninguna otra arista hacia `Domain`. Todo lo que
 * se deje caer aqui queda, por construccion, al alcance de los otros siete
 * modulos: mover una clase a esta carpeta es **publicarla**, y eso no puede ser
 * una decision que se tome al arrastrar un fichero.
 *
 * ## Que se exige y por que hay una lista
 *
 * Lo normal es que una clase de aqui **sea un evento** e implemente
 * `Shared\Domain\Event\DomainEvent`, que es lo que le da nombre estable
 * (`audit_log.action`, RS-07) e instante del hecho. Pero un evento tambien puede
 * necesitar **carga util tipada**: `DailyTotalsReconciled` lleva los seis campos
 * de la fila antes y despues (RL-04, tarea 3.6), y el objeto que los transporta
 * tiene que vivir aqui porque si viviera en `Domain/ValueObject` el listener de
 * `Compliance` no podria nombrarlo — Deptrac no concede esa arista, y abrirla
 * seria abrir el dominio entero de `Attendance` a otro modulo.
 *
 * Esas cargas utiles son legitimas y son **pocas**, asi que se enumeran una a
 * una. La lista no es burocracia: es **el punto de decision**. Publicar un tipo
 * nuevo del dominio hacia los demas modulos deja de ser un descuido silencioso y
 * pasa a exigir una linea aqui, que es exactamente el momento en el que conviene
 * preguntarse si ese tipo deberia viajar en un evento o si lo que hace falta es
 * un escalar. Sin lista, el directorio se convierte poco a poco en la puerta
 * trasera del dominio.
 *
 * ## Como se recorre
 *
 * Con `scandir` a traves de {@see ModuleTree}, **nunca** con
 * `RecursiveDirectoryIterator`: sobre el *bind mount* de Docker Desktop el
 * iterador pierde ficheros sin avisar, y una prueba de arquitectura que no ve un
 * fichero **pasa en verde** — no hay nada que denunciar en un fichero que no
 * existe.
 */

/**
 * Las cargas utiles de evento admitidas, por su nombre completo de clase.
 *
 * Una entrada aqui significa: «este tipo del dominio es parte del vocabulario
 * publico del modulo y lo pueden nombrar los listeners de otros modulos».
 *
 * @return list<string>
 */
function allowedEventPayloads(): array
{
    return [
        // La fila de `daily_totals` antes y despues de una correccion de la
        // reconciliacion (RF-PR-02, RL-04, tarea 3.6). Vive aqui —y no en
        // `Domain/ValueObject`— porque quien la lee es
        // `Compliance\Infrastructure\Listener\RecordProjectionReconciliationAudit`,
        // que solo alcanza `AttendanceDomainEvent`. Ratificado por
        // `arquitecto-dominio`.
        'App\Modules\Attendance\Domain\Event\DailyTotalsSnapshot',
    ];
}

/**
 * Las clases declaradas bajo `Domain/Event/` de todos los modulos.
 *
 * @return array<string, string> nombre completo de clase => ruta relativa
 */
function domainEventClasses(): array
{
    $classes = [];

    foreach (ModuleTree::MODULES as $module) {
        foreach (ModuleTree::filesIn($module.'/Domain/Event') as $file) {
            $source = (string) file_get_contents($file);

            if (preg_match('/^\s*namespace\s+([^;]+);/m', $source, $namespace) !== 1) {
                continue;
            }

            if (preg_match('/^\s*(?:final\s+|abstract\s+|readonly\s+)*(?:class|enum|interface)\s+(\w+)/m', $source, $name) !== 1) {
                continue;
            }

            $classes[trim($namespace[1]).'\\'.$name[1]] = ModuleTree::relative($file);
        }
    }

    return $classes;
}

it('exige que todo lo que vive en Domain/Event sea un evento o una carga util declarada', function (): void {
    $clases = domainEventClasses();

    // Sin esta guarda la prueba no podria fallar: si el recorrido devolviera
    // cero ficheros —el sintoma del *bind mount*— la lista de intrusos saldria
    // vacia y esto pasaria en verde sin haber mirado nada.
    expect(\count($clases))->toBeGreaterThan(10);

    $intrusos = [];

    foreach ($clases as $class => $file) {
        if (\in_array($class, allowedEventPayloads(), true)) {
            continue;
        }

        if (is_a($class, DomainEvent::class, true)) {
            continue;
        }

        $intrusos[] = $file;
    }

    expect($intrusos)->toBe([], implode("\n", [
        'Estas clases viven en Domain/Event sin implementar DomainEvent: '.implode(', ', $intrusos).'.',
        'Ese directorio es el vocabulario PUBLICO del modulo —es lo unico que Deptrac deja alcanzar a',
        'otro modulo—, asi que dejar ahi un tipo cualquiera lo publica hacia los otros siete sin que',
        'nadie lo decida. Si de verdad es carga util de un evento, anadelo a allowedEventPayloads() con',
        'el motivo escrito; si no, su sitio es Domain/ValueObject.',
    ]));
})->group('RF-PR-02');

it('no deja en la lista de cargas utiles ninguna clase que ya no exista', function (): void {
    // Una lista de excepciones que no se limpia deja de ser un punto de decision
    // y pasa a ser decorado: la entrada de una clase borrada sobrevive al
    // refactor que la borro y nadie vuelve a leerla.
    $declaradas = array_keys(domainEventClasses());

    foreach (allowedEventPayloads() as $payload) {
        expect(\in_array($payload, $declaradas, true))->toBeTrue(
            $payload.' figura como carga util admitida y ya no esta en ningun Domain/Event: quitala de la lista.'
        );
    }
})->group('RF-PR-02');

it('mantiene las cargas utiles sin nombre de evento, que es lo que las distingue', function (): void {
    // La comprobacion que impide que la lista se use como atajo: lo que se
    // admite ahi es **carga util**, no un evento al que se le olvido el
    // contrato. Un `eventName()` en una de estas clases significa que queria ser
    // un evento, y entonces lo que le falta es implementar la interfaz.
    foreach (allowedEventPayloads() as $payload) {
        expect(method_exists($payload, 'eventName'))->toBeFalse(
            $payload.' declara eventName(): si es un evento, que implemente DomainEvent en vez de entrar por la lista.'
        );
    }
})->group('RF-PR-02');

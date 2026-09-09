<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\SettingKey;
use App\Support\Version\DeployedVersion;
use Tests\Architecture\Support\ClientDocs;
use Tests\Architecture\Support\Repo;

/*
 * La documentacion entregada al cliente, comprobada como se comprueba el codigo
 * (tarea 5.11 — RF-PD-02, RL-21).
 *
 * POR QUE ESTAS PRUEBAS EXISTEN. RF-PD-02 dice que el IT del cliente despliega
 * el sistema «siguiendo una guia, sin intervencion del fabricante», y RL-21 que
 * la documentacion entregada indica «con claridad que obligaciones asume el
 * cliente». Ninguno de los dos es un endpoint ni un calculo: son texto. La
 * tabla del doc 02 §9.5 no tiene fila para documentacion porque no hay nivel al
 * que subirla —no hay estado que preparar ni respuesta que inspeccionar—, asi
 * que lo que queda es leer el arbol, que es lo que se hace aqui.
 *
 * Y POR QUE MERECEN PRUEBA. Porque la documentacion se erosiona sin que falle
 * nada. Se anade una variable al `.env.example` y nadie la documenta; se sube la
 * version menor y las capturas siguen siendo las de la anterior; alguien traduce
 * la guia inglesa resumiendo dos apartados en uno y el cliente ingles se queda
 * sin el aviso de `KIOSK_VLAN_CIDR`. Nada de eso rompe una prueba de dominio, y
 * todo eso se paga en horas de soporte con cada instalacion —que es el motivo
 * por el que el doc 02 §11 llama a la 5.11 «la tarea mas subestimada».
 *
 * LO QUE ESTAS PRUEBAS NO AFIRMAN, dicho para que nadie lea de mas en la matriz
 * de trazabilidad: no afirman que la guia SE ENTIENDA. Eso solo lo demuestra una
 * instalacion limpia hecha por alguien que no la escribio (decision 7 de la
 * ficha, pendiente del usuario). Aqui se comprueba que la guia esta completa,
 * que es coherente con el codigo y que no enlaza al vacio: lo que si puede
 * verificar una herramienta y lo que, por eso mismo, no debe verificar una
 * persona en cada version.
 */

/*
 * Las cinco guias del paquete y su par en ingles (decision 2 de la ficha 5.11).
 *
 * Es un dataset y no un bucle dentro de cada prueba porque el nombre del par
 * tiene que salir en el informe: «entrega cada guia en las dos lenguas
 * (endurecimiento)» dice cual falta sin abrir nada; un bucle diria solo que una
 * de cinco fallo.
 */
dataset('las cinco guias del paquete', [
    'instalacion' => ['docs/cliente/instalacion.md', 'docs/cliente/en/installation.md'],
    'operacion' => ['docs/cliente/operacion.md', 'docs/cliente/en/operation.md'],
    'configuracion' => ['docs/cliente/configuracion.md', 'docs/cliente/en/configuration.md'],
    'obligaciones legales' => ['docs/cliente/obligaciones-legales.md', 'docs/cliente/en/legal-obligations.md'],
    'endurecimiento' => ['docs/cliente/endurecimiento.md', 'docs/cliente/en/hardening.md'],
]);

dataset('la referencia de configuracion en las dos lenguas', [
    'configuracion' => ['docs/cliente/configuracion.md'],
    'configuration' => ['docs/cliente/en/configuration.md'],
]);

dataset('las obligaciones legales en las dos lenguas', [
    'obligaciones legales' => ['docs/cliente/obligaciones-legales.md'],
    'legal obligations' => ['docs/cliente/en/legal-obligations.md'],
]);

it('documenta en la guia de configuracion cada variable que declara el fichero de entorno de ejemplo', function (string $guide): void {
    // RF-PD-02 y decision 4 de la ficha. El §11.6.1 titula este documento «Todos
    // los parametros y que hace cada uno», y antes del cierre de la tarea
    // faltaban 100 de 162: la guia se escribio una vez y el `.env.example` siguio
    // creciendo tarea a tarea. Una variable sin documentar no falla en ningun
    // sitio; simplemente el IT del cliente no sabe que existe, la deja en su
    // valor de serie y lo descubre cuando algo va lento a las 06:00.
    //
    // Se leen tambien las lineas comentadas (`# NOMBRE=`) porque asi declara el
    // fichero las que solo se activan en algunos despliegues, que son justo las
    // que nadie documenta.
    // Contra la TABLA del apartado de referencia, no contra la prosa: una
    // mencion de pasada en mitad de una frase no es documentar la variable.
    $reference = ClientDocs::section($guide, "\n## 6.");

    expect($reference)->not->toBe('', $guide.' no tiene el apartado "## 6." con la referencia del .env.');

    $undocumented = array_values(array_diff(ClientDocs::environmentKeys(), ClientDocs::tableKeys($reference)));

    expect($undocumented)->toBe([], count($undocumented)
        .' variable(s) de .env.example no aparecen en '.$guide.': '
        .implode(', ', $undocumented));
})->with('la referencia de configuracion en las dos lenguas')->group('RF-PD-02', 'RL-21');

it('no inventa en la referencia del entorno ninguna variable que la instalacion no lea', function (string $guide): void {
    // La otra direccion de la comprobacion cruzada, y la que de verdad enganna:
    // una variable documentada que el codigo no lee nunca. El IT la escribe en su
    // `.env`, no pasa nada, y lo que creia haber configurado —un limite, una
    // ruta, un plazo— sigue en su valor de serie. Es peor que no documentarla,
    // porque el cliente cree que esta hecho.
    //
    // Solo se miran las filas de tabla del apartado de referencia: la prosa cita
    // variables en mitad de una frase y, con ejemplos hipoteticos, daria falsos
    // positivos. La tabla es el catalogo, y el catalogo tiene que ser exacto.
    $reference = ClientDocs::section($guide, "\n## 6.");

    expect($reference)->not->toBe('', $guide.' no tiene el apartado "## 6." con la referencia del .env.');

    $invented = array_values(array_diff(
        ClientDocs::tableKeys($reference),
        ClientDocs::environmentKeys(),
        array_map(static fn (SettingKey $key): string => $key->value, SettingKey::cases()),
    ));

    expect($invented)->toBe([], 'La referencia documenta variable(s) que no existen ni en .env.example ni en SettingKey: '
        .implode(', ', $invented));
})->with('la referencia de configuracion en las dos lenguas')->group('RF-PD-02');

it('documenta cada clave de configuracion que el panel deja editar', function (string $guide): void {
    // RF-PD-01 y RF-PD-02. `installation_settings` es la capa que MANDA sobre el
    // `.env` (tarea 5.1), asi que una clave nueva ahi cambia el comportamiento de
    // la instalacion desde el panel, sin tocar ningun fichero y sin reiniciar
    // nada. El propio catalogo lo dice en su docblock: la convencion de nombres
    // existe para permitir este contraste cruzado.
    //
    // `SettingKey::cases()` es la fuente: el dia que alguien anada una clave, esta
    // prueba se pone roja antes de que se entregue una guia incompleta.
    $undocumented = ClientDocs::namesMissingFrom(
        array_map(static fn (SettingKey $key): string => $key->value, SettingKey::cases()),
        $guide,
    );

    expect($undocumented)->toBe([], count($undocumented)
        .' clave(s) de installation_settings sin documentar en '.$guide.': '
        .implode(', ', $undocumented));
})->with('la referencia de configuracion en las dos lenguas')->group('RF-PD-01', 'RF-PD-02');

it('cita uno a uno los seis requisitos legales que el cliente asume', function (string $guide): void {
    // Decision 5 de la ficha. El lector del hotel no conoce los identificadores
    // —y no tiene por que—, pero el fabricante si, y son la unica forma de
    // comprobar que ningun requisito legal se ha quedado sin parrafo el dia que
    // alguien resuma el documento.
    //
    // RL-16 responsable del tratamiento, RL-17 el fabricante no es encargado,
    // RL-18 encargo acotado a soporte, RL-19 diagnostico sin datos personales,
    // RL-20 continuidad, RL-21 la propia obligacion de documentarlo. Seis
    // parrafos, seis identificadores: si falta uno, el cliente no sabe que esa
    // obligacion es suya, y RL-21 exige exactamente eso.
    $uncited = ClientDocs::literalsMissingFrom(
        ['RL-16', 'RL-17', 'RL-18', 'RL-19', 'RL-20', 'RL-21'],
        $guide,
    );

    expect($uncited)->toBe([], $guide.' no cita: '.implode(', ', $uncited));
})->with('las obligaciones legales en las dos lenguas')->group('RL-16', 'RL-17', 'RL-18', 'RL-19', 'RL-20', 'RL-21');

it('entrega cada guia del paquete en las dos lenguas', function (string $spanish, string $english): void {
    // DoD §10.3: «textos en espanol e ingles». Y decision 2 de la ficha: la
    // version inglesa vive en `docs/cliente/en/` con nombre en ingles. Que el par
    // exista es lo minimo, y es lo que se rompe cuando se anade un documento
    // nuevo —la guia de endurecimiento, sin ir mas lejos— y se traduce «luego».
    expect(ClientDocs::exists($spanish))->toBeTrue($spanish.' no existe: es una de las cinco guias del paquete.');
    expect(ClientDocs::exists($english))->toBeTrue($english.' no existe: la guia inglesa del par no se ha escrito.');
})->with('las cinco guias del paquete')->group('RF-PD-02', 'RL-21');

it('pide exactamente los mismos comandos en las dos lenguas', function (string $spanish, string $english): void {
    // Decision 2 de la ficha. Traducir un texto es correcto; traducir un comando
    // es un fallo de produccion en casa del cliente. El modo de fallo real no es
    // que alguien traduzca `docker compose up`: es que la version inglesa se
    // quede en una revision anterior y siga enseñando el flag que ya no existe,
    // o que se le caiga un paso entero al reordenar parrafos.
    //
    // Se comparan las lineas EJECUTABLES: fuera los comentarios —que si se
    // traducen— y las lineas en blanco. Y se comparan ordenadas, porque la
    // traduccion puede mover un parrafo de sitio sin cambiar una sola orden.
    $commands = ClientDocs::bashLines($spanish);
    $translated = ClientDocs::bashLines($english);

    $onlyInSpanish = array_slice(array_values(array_diff($commands, $translated)), 0, 5);
    $onlyInEnglish = array_slice(array_values(array_diff($translated, $commands)), 0, 5);

    expect($translated)->toBe($commands, 'Los comandos de las dos guias no coinciden. Solo en '
        .$spanish.': '.implode(' | ', $onlyInSpanish).'. Solo en '
        .$english.': '.implode(' | ', $onlyInEnglish).'.');
})->with('las cinco guias del paquete')->group('RF-PD-02');

it('conserva en la traduccion todos los apartados del original', function (string $spanish, string $english): void {
    // Decision 2 de la ficha: «traducir no es resumir». Una guia inglesa con tres
    // apartados menos pasa desapercibida —se lee bien, esta en su idioma— y deja
    // al cliente ingles sin el «que hacer si...» que le habria ahorrado la
    // llamada. Contar cabeceras no garantiza que digan lo mismo, pero si detecta
    // el resumen, que es lo que pasa de verdad.
    //
    // Los bloques de codigo se quitan antes de contar: un comentario de shell a
    // columna cero se parece demasiado a una cabecera.
    $headings = ClientDocs::headingCount($spanish);
    $translated = ClientDocs::headingCount($english);

    expect($translated)->toBe($headings, $spanish.' tiene '.$headings.' apartados y '
        .$english.' tiene '.$translated.': la traduccion ha perdido o inventado apartados.');
})->with('las cinco guias del paquete')->group('RF-PD-02');

it('enlaza solo imagenes que viajan dentro del paquete', function (): void {
    // Regla del paquete (decision 3 de la ficha): el cliente NO tiene el
    // repositorio y su instalacion puede estar en una red sin internet. Una
    // captura enlazada que no viaja en `docs/cliente/img/` es un hueco en la
    // pagina justo en el paso del asistente que el lector no sabe hacer.
    //
    // Esto lo comprueba tambien `check-package-links.sh` sobre el paquete ya
    // construido, en la etapa 8 de la CI. Aqui se comprueba sobre el arbol para
    // que el fallo salga al escribir la guia y no al empaquetarla.
    $broken = ClientDocs::brokenImages(ClientDocs::markdownFiles());

    expect($broken)->toBe([], count($broken).' imagen(es) enlazada(s) no existen: '.implode('; ', $broken));
})->group('RF-PD-02');

it('ensena una captura de cada paso del asistente en las dos lenguas', function (): void {
    // RF-PD-03: el asistente de puesta en marcha tiene OCHO pasos, y el paso 6 de
    // la ficha pide «capturas de lo que debe verse» en cada uno. El numero no es
    // decorativo: sin captura, el lector no sabe si la pantalla que tiene delante
    // es la que la guia describe, y esa duda es exactamente la llamada de soporte
    // que esta tarea existe para evitar.
    //
    // Ocho es el minimo, no el objetivo: la vinculacion del primer quiosco (§7 de
    // la guia) trae las suyas.
    expect(count(ClientDocs::imageTargets('docs/cliente/instalacion.md')))
        ->toBeGreaterThanOrEqual(8, 'instalacion.md enlaza menos de ocho capturas: el asistente tiene ocho pasos.');

    expect(count(ClientDocs::imageTargets('docs/cliente/en/installation.md')))
        ->toBeGreaterThanOrEqual(8, 'en/installation.md enlaza menos de ocho capturas: la guia inglesa no puede llevar menos.');
})->group('RF-PD-02', 'RF-PD-03');

it('sella las capturas con la version menor del producto', function (): void {
    // «Las capturas corresponden a la version — revision en cada version menor»
    // (tabla de pruebas exigidas de la ficha). Una captura desfasada desorienta
    // mas que su ausencia: el lector busca un boton que ya no esta y concluye que
    // ha hecho algo mal.
    //
    // «Revision en cada version menor» como buena intencion no se hace nunca, asi
    // que la verifica una herramienta: el generador de capturas escribe el sello
    // y esta prueba lo ata al `VERSION` de la raiz. Al subir de 2.1 a 2.2 la CI
    // cae hasta que alguien ejecuta `npm run docs:screenshots` (decision 3 de la
    // ficha). Solo `mayor.menor`: un parche no cambia ninguna pantalla.
    expect(ClientDocs::exists('docs/cliente/img/VERSION'))
        ->toBeTrue('Falta docs/cliente/img/VERSION: el sello que ata las capturas a la version del producto.');

    $product = ClientDocs::minorSeries(DeployedVersion::resolve([], [Repo::file('VERSION')]));
    $screenshots = ClientDocs::minorSeries(ClientDocs::contents('docs/cliente/img/VERSION'));

    expect($screenshots)->toBe($product, 'Las capturas estan selladas para la serie '.$screenshots
        .' y el producto va por la '.$product.': hay que regenerarlas con `npm run docs:screenshots`.');
})->group('RF-PD-02');

it('deja la guia de endurecimiento escrita y alcanzable desde donde se busca', function (): void {
    // Decision 1 de la ficha: la guia de endurecimiento es un quinto fichero,
    // anexo de la instalacion, y es la respuesta escrita a cuatro riesgos
    // aceptados del doc 07 §6 marcados «Revision en 5.11». Un anexo que existe y
    // que nadie enlaza es un anexo que no lee nadie: se busca al instalar
    // —`instalacion.md`— y al operar —`operacion.md`—, y por eso se exige el
    // enlace desde los dos y desde la guia inglesa.
    expect(ClientDocs::exists('docs/cliente/endurecimiento.md'))
        ->toBeTrue('Falta docs/cliente/endurecimiento.md (decision 1 de la ficha 5.11).');

    expect(str_contains(ClientDocs::contents('docs/cliente/instalacion.md'), 'endurecimiento.md'))
        ->toBeTrue('instalacion.md no enlaza la guia de endurecimiento.');

    expect(str_contains(ClientDocs::contents('docs/cliente/operacion.md'), 'endurecimiento.md'))
        ->toBeTrue('operacion.md no enlaza la guia de endurecimiento.');

    expect(str_contains(ClientDocs::contents('docs/cliente/en/installation.md'), 'hardening.md'))
        ->toBeTrue('en/installation.md no enlaza en/hardening.md.');
})->group('RF-PD-02');

it('no deja en la guia inglesa ningun enlace a un documento que no viaja en el paquete', function (): void {
    // La version inglesa es la que mas facil se rompe: al traducir se copian los
    // enlaces del original y las rutas relativas cambian de nivel. Los runbooks
    // siguen solo en espanol (decision 2), asi que `../../runbooks/x.md` es
    // legitimo desde `docs/cliente/en/` —resuelve a `docs/runbooks/x.md`, que
    // `package.sh` copia— y `../runbooks/x.md`, que seria lo copiado del
    // original, no resuelve a nada.
    $broken = ClientDocs::brokenDocumentLinks(ClientDocs::markdownFiles('en'));

    expect($broken)->toBe([], count($broken).' enlace(s) de la guia inglesa no resuelven: '.implode('; ', $broken));
})->group('RF-PD-02');

it('no publica en ninguna guia nada con forma de secreto real', function (): void {
    // Regla dura 16 y RS-08. Los ejemplos de la guia se copian y se pegan tal
    // cual —para eso son «comandos completos y copiables»—, asi que una clave de
    // aspecto real acaba siendo la clave de una instalacion, identica en todos
    // los clientes que siguieron la guia. Y una salida de comando pegada con un
    // secreto dentro filtra el de la instalacion de referencia.
    //
    // El criterio es la FORMA: un valor largo y sin espacios detras de una
    // variable que se llama clave, secreto, contrasena o token. Un marcador de
    // posicion legible —con espacios o entre angulos— no lo cumple, que es como
    // debe escribirse.
    $suspicious = ClientDocs::secretLikeAssignments(ClientDocs::markdownFiles());

    expect($suspicious)->toBe([], count($suspicious)
        .' asignacion(es) con valor de aspecto real en la documentacion entregada: '
        .implode('; ', $suspicious));
})->group('RS-08', 'RL-21');

it('no cita en ninguna guia ni runbook un comando de consola que no exista', function (): void {
    // La ficha exige que los comandos de la documentacion se verifiquen, no se
    // copien a mano. La etapa 8 de la CI ejecuta el PROCEDIMIENTO de instalacion,
    // pero no lee los bloques de las guias; y comparar la guia inglesa con la
    // espanola solo demuestra que las dos dicen lo mismo, no que sea verdad. Por
    // ese hueco entro `kiosk:health`: citado en tres runbooks y en una lista de
    // comprobacion trimestral antes de existir. Un comando inexistente en una
    // lista de comprobacion ensena al lector a ignorar el resto de la lista.
    //
    // Los comandos propios se leen del arbol (`$signature` y `Artisan::command`);
    // los de Laravel que las guias usan van en una lista explicita, para que
    // anadir uno sea una decision y no un descuido.
    $laravel = ['key:generate', 'migrate:status', 'schedule:list'];

    $nonexistent = array_values(array_diff(
        ClientDocs::citedArtisanCommands([...ClientDocs::markdownFiles(), ...ClientDocs::runbookFiles()]),
        ClientDocs::declaredArtisanCommands(),
        $laravel,
    ));

    expect($nonexistent)->toBe([], count($nonexistent)
        .' comando(s) citado(s) en la documentacion entregada que el producto no tiene: '
        .implode(', ', $nonexistent));
})->group('RF-PD-02');

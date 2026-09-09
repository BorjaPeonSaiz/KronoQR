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
 * Las ocho guias del paquete y su par en ingles (decision 2 de la ficha 5.11 y
 * decision 3 de la 5.11b).
 *
 * Es un dataset y no un bucle dentro de cada prueba porque el nombre del par
 * tiene que salir en el informe: «entrega cada guia en las dos lenguas
 * (endurecimiento)» dice cual falta sin abrir nada; un bucle diria solo que una
 * de ocho fallo.
 *
 * Las cinco primeras son para el IT del cliente (5.11); las tres ultimas, para
 * quien opera el producto y para quien ficha (5.11b). Estan en el mismo dataset
 * porque las reglas del PAQUETE —par completo, mismos comandos, mismos
 * apartados, imagenes que viajan, sin secretos— no dependen de quien lea.
 */
dataset('las ocho guias del paquete', [
    'instalacion' => ['docs/cliente/instalacion.md', 'docs/cliente/en/installation.md'],
    'operacion' => ['docs/cliente/operacion.md', 'docs/cliente/en/operation.md'],
    'configuracion' => ['docs/cliente/configuracion.md', 'docs/cliente/en/configuration.md'],
    'obligaciones legales' => ['docs/cliente/obligaciones-legales.md', 'docs/cliente/en/legal-obligations.md'],
    'endurecimiento' => ['docs/cliente/endurecimiento.md', 'docs/cliente/en/hardening.md'],
    'guia de RRHH' => ['docs/cliente/guia-rrhh.md', 'docs/cliente/en/hr-guide.md'],
    'guia del portal del empleado' => ['docs/cliente/guia-portal-empleado.md', 'docs/cliente/en/employee-portal-guide.md'],
    'hoja del empleado' => ['docs/cliente/hoja-empleado.md', 'docs/cliente/en/employee-sheet.md'],
]);

/*
 * Las tres guias de negocio de la 5.11b, en las dos lenguas.
 *
 * Se separan de las cinco de IT porque lo que se les exige es distinto: hablan
 * el idioma del hotel, no el del sistema, y las lee quien no ha instalado nada.
 */
dataset('las seis guias de negocio', [
    'RRHH' => ['docs/cliente/guia-rrhh.md'],
    'HR guide' => ['docs/cliente/en/hr-guide.md'],
    'portal del empleado' => ['docs/cliente/guia-portal-empleado.md'],
    'employee portal guide' => ['docs/cliente/en/employee-portal-guide.md'],
    'hoja del empleado' => ['docs/cliente/hoja-empleado.md'],
    'employee sheet' => ['docs/cliente/en/employee-sheet.md'],
]);

/*
 * Cada guia de negocio con su minimo de capturas (decision 4 de la ficha 5.11b).
 *
 * Los numeros van escritos, no calculados a partir del directorio de imagenes:
 * un minimo que se deduce de lo que hay no es un minimo, es un espejo.
 */
dataset('las guias de negocio con su minimo de capturas', [
    'RRHH' => ['docs/cliente/guia-rrhh.md', 12],
    'HR guide' => ['docs/cliente/en/hr-guide.md', 12],
    'portal del empleado' => ['docs/cliente/guia-portal-empleado.md', 4],
    'employee portal guide' => ['docs/cliente/en/employee-portal-guide.md', 4],
    'hoja del empleado' => ['docs/cliente/hoja-empleado.md', 2],
    'employee sheet' => ['docs/cliente/en/employee-sheet.md', 2],
]);

dataset('la guia de RRHH con el idioma del panel', [
    'es' => ['docs/cliente/guia-rrhh.md', 'es'],
    'en' => ['docs/cliente/en/hr-guide.md', 'en'],
]);

dataset('la hoja con su fichero de textos', [
    'es' => ['docs/cliente/hoja-empleado.md', 'es'],
    'en' => ['docs/cliente/en/employee-sheet.md', 'en'],
]);

/*
 * Cada guia nueva con los documentos que tiene que enlazar (decision 8 de 5.11b).
 *
 * La referencia cruzada es unica: lo que ya esta escrito en otro sitio se
 * enlaza y no se repite. Una guia que no enlaza obliga a repetir, y dos copias
 * de un procedimiento divergen en la primera version.
 */
dataset('los enlaces obligatorios de las guias nuevas', [
    'RRHH remite a la tarjeta perdida o rota' => ['docs/cliente/guia-rrhh.md', '../runbooks/tarjeta-perdida-o-rota.md'],
    'RRHH remite al requerimiento de inspeccion' => ['docs/cliente/guia-rrhh.md', '../runbooks/requerimiento-inspeccion.md'],
    'RRHH remite a la solicitud de derechos' => ['docs/cliente/guia-rrhh.md', '../runbooks/solicitud-derechos-rgpd.md'],
    'el portal remite a la configuracion' => ['docs/cliente/guia-portal-empleado.md', 'configuracion.md'],
    'la hoja remite a la guia de RRHH' => ['docs/cliente/hoja-empleado.md', 'guia-rrhh.md'],
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
    expect(ClientDocs::exists($spanish))->toBeTrue($spanish.' no existe: es una de las ocho guias del paquete.');
    expect(ClientDocs::exists($english))->toBeTrue($english.' no existe: la guia inglesa del par no se ha escrito.');
})->with('las ocho guias del paquete')->group('RF-PD-02', 'RL-21');

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
})->with('las ocho guias del paquete')->group('RF-PD-02');

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
})->with('las ocho guias del paquete')->group('RF-PD-02');

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

/*
 * ---------------------------------------------------------------------------
 * Las tres guias de negocio (tarea 5.11b — RL-05, RF-PA-04, RF-PD-02).
 *
 * POR QUE NECESITAN PRUEBAS PROPIAS. Las cinco guias de la 5.11 las lee el IT
 * del cliente, que conoce el sistema; estas tres las leen RRHH y el empleado,
 * que no lo conocen y no tienen por que. Lo que se rompe aqui no es un enlace:
 * es que la guia hable del sistema en vez de hablar del trabajo, que prometa
 * algo que el producto no hace —una tarjeta en el movil, un PIN por correo— o
 * que se quede atras cuando el panel o la hoja cambien de texto. Nada de eso
 * falla en ningun sitio; se paga en la primera inspeccion o en la primera
 * llamada de un empleado que hizo lo que ponia en la hoja.
 * ---------------------------------------------------------------------------
 */

it('no usa en las guias de negocio ningun identificador del codigo', function (string $guide): void {
    // Decision 5 de la ficha 5.11b y paso 2 de la ficha: «lenguaje de negocio,
    // no de sistema». El glosario del doc 01 §13 es el puente —jornada, tramo,
    // credencial—, y quien escribe la guia trabaja todo el dia con el otro lado
    // del glosario: el nombre de la clase se cuela solo.
    //
    // SOLO SOBRE LAS SEIS GUIAS DE NEGOCIO, a proposito. Las cinco de la 5.11
    // usan `daily_totals`, `audit_log` y `occurred_at` de forma LEGITIMA: su
    // lector es quien mira una tabla con `psql`, restaura una copia o ejecuta
    // `attendance:reconcile`, y ahi el nombre exacto de la tabla es justo lo que
    // necesita. Extender la prohibicion a esas cinco no mejoraria ninguna guia:
    // obligaria a escribir «la proyeccion de totales diarios» donde el IT
    // necesita leer el nombre que va a teclear.
    $identifiers = ClientDocs::literalsFoundIn(
        ['WorkDay', 'ShiftEntry', 'ScanEvent', 'daily_totals', 'audit_log', 'occurred_at', 'recorded_at', 'employee_uuid'],
        $guide,
    );

    expect($identifiers)->toBe([], $guide.' habla de sistema y no de negocio: '
        .implode(', ', $identifiers).'. El glosario del doc 01 §13 tiene la palabra del hotel para cada uno.');
})->with('las seis guias de negocio')->group('RF-PD-02', 'RL-05');

it('no promete en las guias de negocio nada que el producto no haga', function (string $guide): void {
    // Decision 6 de la ficha 5.11b, y reglas duras 11 y 20: no hay biometria y
    // la credencial es una tarjeta fisica impresa. Una guia que insinue lo
    // contrario crea una expectativa que nadie puede cumplir y, con la
    // biometria, sugiere un tratamiento de datos que el producto no hace y que
    // el cliente tendria que declarar.
    //
    // Se prohiben FRASES y no palabras: «movil» es legitimo —el portal se abre
    // desde el movil, y la guia del portal lo dice—; «tarjeta en el movil» no
    // existe. La comparacion es insensible a mayusculas, acentos y tipografia
    // (ver `ClientDocs::forbiddenPhrasesFoundIn`), porque la frase se cuela
    // escrita como se escriba.
    $promises = ClientDocs::forbiddenPhrasesFoundIn([
        'biometr',
        'huella dactilar',
        'fingerprint',
        'credencial en el movil',
        'tarjeta en el movil',
        'QR en el movil',
        'credential on the phone',
        'card on the phone',
        'QR on the phone',
    ], $guide);

    expect($promises)->toBe([], $guide.' menciona algo que el producto no tiene: '
        .implode(', ', $promises).'. ADR-009 (cero biometria) y ADR-014 (tarjeta fisica).');
})->with('las seis guias de negocio')->group('RF-PD-02', 'RL-05');

it('no ofrece en ninguna guia de negocio recuperar el PIN por correo', function (string $guide): void {
    // ADR-015 y regla dura 12: el producto no depende del correo del empleado y
    // el PIN se entrega en mano; quien lo olvida se lo pide a RRHH. Esta es la
    // promesa mas facil de escribir sin darse cuenta, porque es lo que hace
    // cualquier otra aplicacion, y la unica que ademas describe un mecanismo
    // que no existe: el empleado lo intentaria y se quedaria sin fichar.
    //
    // NO se puede prohibir la frase entera: la frase CORRECTA la contiene. La
    // hoja dice «se entrega en mano y nunca por correo» y la guia la reproduce
    // literalmente. El criterio es la negacion pegada al marcador: «nunca por
    // correo» y «never by email» pasan; «recupera tu PIN por correo» y «si no
    // recuerdas tu PIN, te lo enviamos por correo» —cuyo `no` queda lejos— no.
    $claims = ClientDocs::pinByEmailClaims($guide);

    expect($claims)->toBe([], $guide.' da a entender que el PIN llega por correo: '
        .implode(' | ', $claims).'. Se pide a RRHH y se entrega en mano (ADR-015).');
})->with('las seis guias de negocio')->group('RF-PD-02', 'RL-05');

it('nombra los nueve motivos de correccion con la etiqueta que ensena el panel', function (string $guide, string $locale): void {
    // RF-PA-04 y decision 5 de la ficha 5.11b. El §5 de la guia de RRHH es lo
    // que hay que saber explicar ante una inspeccion, y el motivo que se
    // escogio sale impreso en la exportacion legal. Si la guia llama «olvido de
    // entrada» a lo que el desplegable llama «Olvido de fichaje de entrada»,
    // quien la lee busca una opcion que no encuentra; si un motivo falta del
    // todo, se acaba eligiendo «Otro motivo» para casos que tenian el suyo, y
    // eso empobrece el catalogo justo donde importa.
    //
    // La fuente es el fichero de idioma DEL PANEL, no una lista copiada aqui:
    // el dia que alguien cambie una etiqueta, la guia queda desalineada y esta
    // prueba lo dice. Se exige la etiqueta y no la descripcion: la etiqueta es
    // lo que se ve en el desplegable.
    $labels = ClientDocs::panelTexts($locale, 'corrections.reasons');

    expect($labels)->toHaveCount(9, 'El panel ya no ensena nueve motivos de correccion sino '
        .count($labels).': hay que revisar el Anexo C del doc 01 y la guia de RRHH.');

    $missing = ClientDocs::phrasesMissingFrom($labels, $guide);

    expect($missing)->toBe([], $guide.' no nombra '.count($missing)
        .' motivo(s) con la etiqueta del panel: '.implode(' | ', $missing));
})->with('la guia de RRHH con el idioma del panel')->group('RF-PA-04', 'RF-PD-02');

it('reproduce en la guia de la hoja el texto que el producto imprime', function (string $guide, string $locale): void {
    // Decision 2 de la ficha 5.11b. La hoja la produce el producto
    // (`GET /credentials/instructions-sheet`), y la guia la reproduce para que
    // RRHH sepa que esta entregando sin abrir el PDF. Son dos copias del mismo
    // texto en dos sitios distintos: sin esta prueba, se cambia una frase de la
    // hoja —una confirmacion del quiosco, el modo de pedir un PIN nuevo— y la
    // guia sigue diciendo lo de antes durante versiones, que es peor que no
    // reproducirla, porque RRHH explica al empleado algo que ya no ocurre.
    //
    // Se comparan los trozos ENTRE marcadores: la guia escribe «[nombre de la
    // instalacion]» donde el producto pone `:app_name`, asi que la frase entera
    // nunca coincidiria. Y con los espacios colapsados, porque el Markdown
    // parte las frases largas en varias lineas y eso es formato, no otro texto.
    $missing = ClientDocs::phrasesMissingFrom(ClientDocs::instructionsSheetPhrases($locale), $guide);

    expect($missing)->toBe([], $guide.' no reproduce '.count($missing)
        .' frase(s) de lang/'.$locale.'/instructions-sheet.php: '.implode(' | ', $missing)
        .'. Se copian literales, sin marcas de formato dentro de la frase.');
})->with('la hoja con su fichero de textos')->group('RL-05', 'RF-PD-02');

it('ensena en cada guia de negocio las capturas que su recorrido necesita', function (string $guide, int $minimum): void {
    // Decision 4 de la ficha 5.11b. Los minimos no son decorativos: doce en la
    // guia de RRHH son el alta, la tarjeta, el PIN de una sola vez, la
    // presencia, la jornada, la correccion, la bandeja, el informe y la
    // exportacion legal —el recorrido completo que el paso 3 de la ficha exige
    // en lugar de una referencia por pantalla—; cuatro en el portal son entrar,
    // ver, entender una correccion y descargar; dos en la hoja son las
    // confirmaciones del quiosco que el empleado tiene que reconocer.
    //
    // Una guia de recorrido sin capturas se lee y no se sigue: el lector no
    // sabe si la pantalla que tiene delante es la que se le esta describiendo.
    // Y el minimo se exige en las DOS lenguas porque la version inglesa es la
    // que se traduce con prisa y se queda sin imagenes.
    expect(count(ClientDocs::imageTargets($guide)))->toBeGreaterThanOrEqual($minimum, $guide
        .' enlaza menos de '.$minimum.' capturas: el recorrido que describe no se puede seguir sin verlas.');
})->with('las guias de negocio con su minimo de capturas')->group('RF-PD-02', 'RL-05');

it('remite desde cada guia nueva a donde ya esta escrito lo que no repite', function (string $guide, string $link): void {
    // Decision 8 de la ficha 5.11b: referencia cruzada unica. La guia de RRHH no
    // explica como se atiende un requerimiento de Inspeccion, ni una solicitud
    // de derechos, ni una tarjeta perdida: eso son runbooks, y duplicarlos
    // garantiza que las dos copias divergan. Pero un procedimiento que existe y
    // que la guia no enlaza es un procedimiento que RRHH no encuentra el dia
    // que lo necesita, que es siempre un dia con prisa.
    //
    // Se comprueba la RUTA relativa tal cual y no el nombre del fichero suelto:
    // una mencion sin enlace no lleva a ningun sitio. Que la ruta resuelva lo
    // verifica la prueba de enlaces del paquete. Un enlace por caso del dataset
    // para que el informe diga CUAL falta sin abrir la guia.
    expect(ClientDocs::literalsMissingFrom([$link], $guide))
        ->toBe([], $guide.' no enlaza '.$link.'.');
})->with('los enlaces obligatorios de las guias nuevas')->group('RF-PD-02', 'RL-05');

it('indexa en los runbooks el de la tarjeta perdida o rota', function (): void {
    // Decision 7 de la ficha 5.11b. El runbook lo cita el contrato de
    // `POST /credentials/{uuid}/print` y lo enlaza el §8 de la guia de RRHH,
    // pero quien lo busca de verdad —el IT, o RRHH un sabado— entra por el
    // indice de `docs/runbooks/README.md`. Un runbook que no esta en el indice
    // solo lo encuentra quien ya sabe que existe.
    expect(str_contains(ClientDocs::contents('docs/runbooks/README.md'), 'tarjeta-perdida-o-rota.md'))
        ->toBeTrue('docs/runbooks/README.md no indexa tarjeta-perdida-o-rota.md.');
})->group('RF-PD-02');

<?php

declare(strict_types=1);

use Tests\Architecture\Support\Repo;

/*
 * Las garantias de proteccion de datos que NO son codigo ejecutable
 * (RL-07, RL-10, RL-13, RL-14).
 *
 * POR QUE ESTAN AQUI Y NO EN OTRO NIVEL. Estos cuatro requisitos no describen un
 * calculo ni un endpoint: describen lo que el producto DECLARA y lo que el
 * producto NO HACE. La tabla del doc 02 §9.5 no manda ningun nivel para eso, y
 * subirlo a integracion o a E2E no añadiria una sola comprobacion: no hay estado
 * que preparar ni respuesta que inspeccionar. Lo que hay es texto —el aviso del
 * quiosco, el procedimiento de derechos, el documento del cliente— y
 * configuracion —que difusor se ofrece—, y las dos cosas se leen del arbol.
 *
 * Y POR QUE MERECEN PRUEBA. Porque son justo lo que se erosiona sin que falle
 * nada: nadie se entera de que el aviso ha dejado de citar la base juridica, ni
 * de que alguien ha vuelto a poner `pusher` en las conexiones de difusion, hasta
 * que lo pregunta un inspector o un cliente. Una prueba que lee el texto no
 * garantiza que el texto sea juridicamente correcto —eso lo decide quien
 * redacta—, pero si garantiza que no desaparezca en silencio.
 *
 * Lo que estas pruebas NO afirman, dicho para que nadie lea de mas en la matriz:
 * no comprueban que el cliente haya hecho su EIPD ni que haya inscrito el
 * tratamiento en su registro de actividades. Eso es del responsable del
 * tratamiento (ADR-020) y el fabricante no puede verificarlo. Lo que se
 * comprueba es que el producto se lo DICE, que es la parte que si depende de
 * este repositorio.
 */

/**
 * El bloque `privacy` del catalogo del quiosco en un idioma.
 *
 * @return array<string, string>
 */
function avisoDePrivacidadDelQuiosco(string $locale): array
{
    /** @var mixed $catalogo */
    $catalogo = json_decode(
        Repo::contents('frontend-kiosk/src/shared/i18n/locales/'.$locale.'.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($catalogo)->toBeArray();

    /** @var array<string, mixed> $catalogo */
    $aviso = $catalogo['privacy'] ?? null;

    expect($aviso)->toBeArray('El catalogo "'.$locale.'" no tiene bloque `privacy`.');

    /** @var array<string, string> $aviso */
    return $aviso;
}

it('declara la obligacion legal como base juridica en el aviso del quiosco, en los dos idiomas', function (): void {
    // RL-07. El aviso de capa 1 es el unico sitio donde quien ficha lee POR QUE
    // se trata su jornada (art. 13.1.c RGPD), y que ponga «obligacion legal» y
    // no «consentimiento» no es un matiz de redaccion: si la base fuera el
    // consentimiento, cualquiera podria retirarlo y quedarse sin registro, y el
    // deber del art. 34.9 ET dejaria de poder cumplirse para esa persona.
    //
    // Se comprueba el TEXTO y no la pantalla a proposito: que el aviso se VE
    // —siempre, y tambien mientras se confirma un fichaje— ya lo prueba el E2E
    // del quiosco (RL-09, RF-KI-09). Lo que ese E2E no nota es que la redaccion
    // cambie a una base juridica que no es la nuestra.
    $es = avisoDePrivacidadDelQuiosco('es');
    $en = avisoDePrivacidadDelQuiosco('en');

    expect($es['basis'] ?? '')->toContain('obligación legal');
    expect($es['purpose'] ?? '')->toContain('art. 34.9');
    expect($en['basis'] ?? '')->toContain('legal obligation');
    expect($en['purpose'] ?? '')->toContain('art. 34.9');

    // Y el consentimiento no aparece en ninguno de los dos: ni como base ni como
    // casilla que alguien tuviera que aceptar antes de poder fichar.
    expect(mb_strtolower(implode(' ', $es)))->not->toContain('consentimiento');
    expect(mb_strtolower(implode(' ', $en)))->not->toContain('consent');
})->group('RL-07', 'RF-KI-09');

it('deja escrita la base juridica en el documento que el cliente lleva a su registro de actividades', function (): void {
    // RL-07 exige que la base este «documentada en el Registro de Actividades de
    // Tratamiento del cliente» (doc 01 §8). Ese registro lo redacta el hotel, no
    // el fabricante; lo que si depende de este repositorio es entregarle la
    // frase exacta que tiene que copiar, con su articulo. Sin esta prueba, el
    // dia que alguien resuma el documento del cliente se pierde el unico sitio
    // donde el producto dice cual es su base juridica.
    $obligaciones = Repo::contents('docs/cliente/obligaciones-legales.md');

    expect($obligaciones)
        ->toContain('art. 6.1.c RGPD')
        ->toContain('art. 34.9 ET')
        ->toContain('No es consentimiento');
})->group('RL-07');

it('deja procedimiento escrito para los seis derechos, con la supresion condicionada al deber de conservacion', function (): void {
    // RL-10. «Procedimientos para acceso, rectificacion mediante correccion
    // trazada, limitacion y portabilidad. La supresion queda condicionada al
    // deber legal de conservacion» (doc 01 §8). El producto no puede atender una
    // solicitud por su cuenta —la atiende el responsable del tratamiento
    // (ADR-020)—, asi que lo que RL-10 exige de este repositorio es el
    // procedimiento, y el procedimiento existe o no existe.
    //
    // Los seis derechos, uno a uno: un runbook al que le falte la limitacion
    // deja al hotel sin respuesta el dia que alguien impugne una jornada.
    $runbook = Repo::contents('docs/runbooks/solicitud-derechos-rgpd.md');

    expect($runbook)
        ->toContain('ACCESO')
        ->toContain('PORTABILIDAD')
        ->toContain('RECTIFICACIÓN')
        ->toContain('SUPRESIÓN')
        ->toContain('OPOSICIÓN')
        ->toContain('LIMITACIÓN')
        // El plazo del art. 12.3: sin el, el procedimiento se lee tarde.
        ->toContain('un mes')
        // Y la unica frase que impide que alguien «cumpla» un derecho de
        // supresion borrando un registro horario todavia vigente, que seria
        // incumplir el art. 34.9 ET creyendo que se cumple el RGPD.
        ->toContain('art. 17.3.b')
        ->toContain('cuatro años');

    // Y el cliente tiene que poder llegar hasta el: un runbook que no se enlaza
    // desde el documento que el hotel si lee no lo abre nadie.
    expect(Repo::contents('docs/cliente/obligaciones-legales.md'))
        ->toContain('runbooks/solicitud-derechos-rgpd.md');
})->group('RL-10');

it('no cita en el procedimiento de derechos ningun comando que no exista', function (): void {
    // La otra mitad de RL-10, y la que de verdad se rompe: el procedimiento
    // resuelve el acceso y la portabilidad con `compliance:legal-export` y
    // comprueba el plazo de conservacion con `compliance:apply-retention
    // --dry-run`. El dia que uno de los dos se renombre, el runbook queda
    // enseñando una orden que falla, y eso se descubre con el reloj del art.
    // 12.3 corriendo. Se comprueba contra la firma real del comando.
    $runbook = Repo::contents('docs/runbooks/solicitud-derechos-rgpd.md');

    expect($runbook)
        ->toContain('compliance:legal-export')
        ->toContain('compliance:apply-retention --dry-run');

    expect(Repo::contents('backend/app/Modules/Compliance/Infrastructure/Console/LegalExportCommand.php'))
        ->toContain("\$signature = 'compliance:legal-export")
        // El acotado por persona es lo que convierte la exportacion legal en una
        // respuesta de derecho de acceso: sin `--employee` habria que entregar
        // el registro del centro entero, que seria otra brecha.
        ->toContain('--employee');

    expect(Repo::contents('backend/app/Modules/Compliance/Infrastructure/Console/ApplyRetentionCommand.php'))
        ->toContain("\$signature = 'compliance:apply-retention")
        ->toContain('--dry-run');
})->group('RL-10');

it('advierte al cliente de que este tratamiento recomienda una evaluacion de impacto', function (): void {
    // RL-13: «se recomienda evaluacion de impacto por tratarse de control
    // sistematico de personal trabajadora». La EIPD la hace el responsable del
    // tratamiento —el hotel—, nunca el fabricante, asi que el requisito solo
    // puede cumplirse de una forma desde este repositorio: DICIENDOSELO, en el
    // documento que se le entrega. Si no aparece ahi, el cliente no se entera y
    // el requisito no existe, por mucho que este escrito en el doc 01.
    //
    // Se exige tambien el articulo (35 RGPD) y el motivo (observacion
    // sistematica), porque una mencion suelta de las siglas no le sirve a quien
    // tiene que decidir si le aplica.
    $obligaciones = Repo::contents('docs/cliente/obligaciones-legales.md');

    expect($obligaciones)
        ->toContain('EIPD')
        ->toContain('art. 35 RGPD')
        ->toContain('sistemática');

    // Y el porque de que sea RECOMENDADA y no obligatoria: sin biometria ni
    // geolocalizacion, el tratamiento no es de los que el art. 35.3 obliga
    // (ADR-009). Ese razonamiento es del ADR, y el documento del cliente no
    // puede contradecirlo.
    expect(Repo::contents('docs/adr/ADR-009-sin-biometria.md'))->toContain('RL-13');
})->group('RL-13');

it('no ofrece ningun difusor gestionado por el que la presencia de la plantilla salga del servidor del cliente', function (): void {
    // RL-14: los datos se alojan en la infraestructura del propio cliente. La
    // via mas facil de romperlo no es una migracion ni un endpoint: es volver a
    // pegar el `config/broadcasting.php` de serie de Laravel, que trae `pusher`
    // y `ably`. Con una de esas conexiones activa, la presencia en vivo de la
    // plantilla —quien esta dentro del hotel y desde cuando— viajaria a un
    // tercero fuera del control del responsable del tratamiento, y no fallaria
    // absolutamente nada: el panel funcionaria igual o mejor.
    //
    // ADR-011 los descarta por escrito y por este motivo. Aqui se comprueba que
    // el descarte sigue siendo cierto en el fichero.
    $broadcasting = Repo::contents('backend/config/broadcasting.php');

    expect($broadcasting)->toContain("'reverb' => [");
    expect($broadcasting)->not->toContain("'pusher' => [");
    expect($broadcasting)->not->toContain("'ably' => [");

    // Y las credenciales tampoco: una variable declarada es una invitacion a
    // rellenarla.
    $env = Repo::contents('.env.example');

    expect($env)->not->toContain('PUSHER_');
    expect($env)->not->toContain('ABLY_');
})->group('RL-14', 'RF-PA-01');

it('no usa ninguno de los servicios de terceros que el fichero de credenciales deja declarados', function (): void {
    // La otra puerta por la que saldrian datos personales: `config/services.php`
    // es de serie de Laravel y trae Postmark, Resend, SES y Slack. Que esten
    // declarados no los usa nadie —las claves no existen en `.env.example`—,
    // pero el dia que alguien mande el resumen de incidencias por una API de
    // correo alojada, los nombres y los horarios de la plantilla saldrian de la
    // infraestructura del cliente sin que ninguna prueba dijera nada.
    //
    // El correo del producto sale por el SMTP que declara la instalacion
    // (`config/mail.php`), que es lo que RL-14 admite.
    $codigo = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(Repo::file('backend/app'), FilesystemIterator::SKIP_DOTS),
    );

    $usos = [];

    foreach ($codigo as $fichero) {
        if (! $fichero instanceof SplFileInfo || $fichero->getExtension() !== 'php') {
            continue;
        }

        $contenido = (string) file_get_contents($fichero->getPathname());

        foreach (['services.postmark', 'services.resend', 'services.ses', 'services.slack'] as $servicio) {
            if (str_contains($contenido, $servicio)) {
                $usos[] = $fichero->getFilename().' usa '.$servicio;
            }
        }
    }

    expect($usos)->toBe([]);

    // El transporte por omision no es una API de un tercero: es el log en
    // desarrollo y el SMTP que declare la instalacion.
    expect(Repo::contents('backend/config/mail.php'))->toContain("'default' => env('MAIL_MAILER', 'log')");
})->group('RL-14');

it('dice al cliente que ni los datos ni las copias salen de su infraestructura', function (): void {
    // RL-14 tiene una mitad que no es configuracion: lo que el cliente sabe. El
    // hotel es el responsable del tratamiento y responde de donde estan los
    // datos; si su documentacion no dice que estan en su servidor y que las
    // copias tampoco viajan, no puede contestar a esa pregunta en una auditoria.
    // La regla dura 16 y ADR-020 lo dicen del fabricante; esto comprueba que se
    // le ha dicho a quien tiene que declararlo.
    expect(Repo::contents('docs/cliente/instalacion.md'))
        ->toContain('RL-14')
        ->toContain('no las recibe ni las custodia');
})->group('RL-14');

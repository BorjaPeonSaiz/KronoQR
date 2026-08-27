<?php

declare(strict_types=1);

/*
 * Trazabilidad requisito -> prueba (RQ-13, doc 02 §9.6) y coherencia documental.
 *
 * Configuracion de las herramientas del REPOSITORIO, no del producto. Los
 * comandos que la leen (`qa:traceability`) no se ejecutan nunca en la
 * instalacion de un cliente: viven en app/Console/Commands/Quality/ y solo
 * tienen sentido con el arbol de fuentes delante.
 */

/*
 * La raiz del repositorio, resuelta UNA vez y usada por las dos claves que la
 * necesitan (`repo_path` y `test_paths.playwright`).
 *
 * Dentro del contenedor `app` llega por un montaje aparte de solo lectura
 * (/var/www/repo); en la CI —que corre sobre el arbol completo sin contenedor—
 * es el directorio padre de backend/.
 */
$repoPath = env('REPO_PATH', is_dir('/var/www/repo') ? '/var/www/repo' : base_path('..'));

return [

    /*
     * Fase del plan (doc 02 §11) cuyo trabajo ya se ha ejecutado. Es el alcance
     * del bloqueo de `qa:traceability --check`: los requisitos de esa fase y de
     * las anteriores EN EL ORDEN REAL DE EJECUCION deben tener prueba; los
     * posteriores no bloquean (§9.6).
     *
     * Se actualiza al cerrar cada fase, como parte del procedimiento de cierre
     * (doc 03 §6.6). Este literal ES el registro de que la fase se cerro.
     *
     * LITERAL Y NO `env()`, a proposito. Esto no es configuracion de despliegue:
     * es ESTADO DEL REPOSITORIO. Leerlo del entorno tenia un fallo silencioso y
     * permanente: la variable no existia en .env.example, ni en el workflow, ni
     * en compose, asi que en la CI —donde backend/.env esta en .gitignore— el
     * valor era siempre el de por defecto. Cerrada la Fase 1, el portatil habria
     * exigido prueba a sus requisitos y la CI no, que es la forma mas cara de
     * tener una puerta: la que solo salta en la maquina de quien la mira.
     *
     * La regla general, y vale para cualquier valor que se añada aqui: si el
     * valor tiene que ser el mismo en todas las maquinas, no puede venir del
     * entorno. QualityGatesTest lo verifica.
     */
    'current_phase' => 1,

    /*
     * El orden REAL de ejecucion de las fases, literal del Anexo A del doc 01:
     * «Orden de ejecucion: 0 -> 1 -> 2 -> 5 -> 3 -> 4».
     *
     * Es la trampa de CURRENT_PHASE y por eso esta escrito como lista y no como
     * comparacion numerica: con `fase <= CURRENT_PHASE`, un CURRENT_PHASE=5
     * daria por exigibles los requisitos de las fases 3 y 4, que todavia no se
     * han ejecutado. La lista se compara con la del documento en la prueba de
     * divergencia, asi que si alguien reordena el plan y no lo actualiza aqui,
     * la suite lo dice.
     */
    'phase_execution_order' => [0, 1, 2, 5, 3, 4],

    /*
     * docs/ vive FUERA de backend/ y a proposito (mismo motivo que el contrato
     * OpenAPI en config/spectator.php): manda sobre los cuatro artefactos, no
     * es propiedad de ninguno. Una sola ruta relativa vale en los dos sitios
     * donde se ejecutan estos comandos:
     *
     *   contenedor  /var/www/html/../docs  ->  /var/www/docs  (montado :ro)
     *   CI          <repo>/backend/../docs ->  <repo>/docs
     */
    'docs_path' => env('DOCS_PATH', base_path('../docs')),

    /*
     * La raiz del repositorio, que NO es la del backend.
     *
     * `docs:consistency` compara los documentos que mandan con los ficheros que
     * los ejecutan, y el plan de implementacion vive fuera de docs/. Se resuelve
     * igual que en tests/Architecture/QualityGatesTest.php y por el mismo
     * motivo: dentro del contenedor la raiz llega por un montaje aparte de solo
     * lectura (/var/www/repo), y en la CI —que corre sobre el arbol completo sin
     * contenedor— es el directorio padre de backend/. Una comprobacion que solo
     * funciona en uno de los dos sitios no comprueba nada, solo enseña donde se
     * ejecuto.
     */
    'repo_path' => $repoPath,

    /* Fuente legible por maquina del Anexo A del doc 01. Relativa a docs_path. */
    'requirements_file' => 'requisitos.yaml',

    /* Matriz generada por `qa:traceability`. Relativa a docs_path. */
    'traceability_file' => 'trazabilidad-pruebas.md',

    /* El documento que manda sobre QUE hace el producto. Relativa a docs_path. */
    'specification_file' => '01-especificaciones-proyecto.md',

    /*
     * El documento que manda sobre COMO se construye. Su §4 es la tabla de ADR,
     * y cada fila tiene que tener su fichero en docs/adr/: la tabla es autoridad
     * #4 y el ADR es autoridad #1 (CLAUDE.md). Relativa a docs_path.
     */
    'stack_file' => '02-stack-tecnologico-y-plan-implementacion.md',

    /* Decisiones de arquitectura, una por fichero ADR-NNN-*.md. Relativa a docs_path. */
    'adr_path' => 'adr',

    /*
     * El plan de implementacion desarrollado tarea por tarea. Relativa a
     * repo_path, y con espacio en el nombre porque asi se llama el directorio.
     *
     * De aqui sale la respuesta a «¿quien construye este requisito?»: una tarea
     * `### Tarea N.M` de un fichero que declara su fase en la cabecera.
     */
    'plan_path' => 'plan implementacion',

    /*
     * Donde se buscan las etiquetas. Dos herramientas y dos formatos (§9.6):
     * Pest/PHPUnit con `->group('RN-05')` y Playwright con `{ tag: ['@RN-05'] }`.
     *
     * LOS FRONTENDS SE RESUELVEN DESDE `$repoPath`, NO DESDE `base_path('..')`,
     * y esa diferencia es justo la que hacia que la puerta comprobara cosas
     * distintas segun quien la ejecutara:
     *
     *   CI          base_path('..') = <repo>          -> los ve
     *   contenedor  base_path('..') = /var/www        -> NO los ve
     *               $repoPath       = /var/www/repo   -> si los ve
     *
     * Con la ruta antigua, `qa:traceability --check` daba 12 requisitos sin
     * prueba en el portatil y 4 en la CI, para el mismo arbol: ocho de ellos
     * —RF-KI-01/02/05/06/09, RF-QR-05, RQ-05, RL-09— si tienen su prueba E2E
     * etiquetada en `frontend-kiosk/tests/e2e`, solo que el contenedor no podia
     * verla. Es el mismo fallo que documenta `current_phase` mas arriba, con los
     * papeles cambiados: una puerta que solo salta donde nadie va a mirar acaba
     * ignorada, y con ella los avisos que si eran ciertos.
     *
     * Si aun asi falta algun directorio, el comando lo DICE en vez de dar por
     * hecho que no hay pruebas E2E. La exploracion parcial solo puede añadir
     * requisitos sin prueba, nunca quitarlos: falla cerrado.
     */
    'test_paths' => [
        'pest' => [
            base_path('tests'),
        ],
        'playwright' => [
            $repoPath.'/frontend-kiosk/tests/e2e',
            $repoPath.'/frontend-admin/tests/e2e',
            $repoPath.'/frontend-portal/tests/e2e',
        ],
    ],

];

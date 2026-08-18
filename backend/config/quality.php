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
    'current_phase' => 0,

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
    'repo_path' => env('REPO_PATH', is_dir('/var/www/repo') ? '/var/www/repo' : base_path('..')),

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
     * Los frontends NO estan montados en el contenedor `app`, asi que ahi la
     * exploracion de Playwright es parcial y el comando lo dice en vez de dar
     * por hecho que no hay pruebas E2E. En la CI, que corre sobre el arbol
     * completo, se exploran los seis directorios. La exploracion parcial solo
     * puede añadir requisitos sin prueba, nunca quitarlos: falla cerrado.
     */
    'test_paths' => [
        'pest' => [
            base_path('tests'),
        ],
        'playwright' => [
            base_path('../frontend-kiosk/tests/e2e'),
            base_path('../frontend-admin/tests/e2e'),
            base_path('../frontend-portal/tests/e2e'),
        ],
    ],

];

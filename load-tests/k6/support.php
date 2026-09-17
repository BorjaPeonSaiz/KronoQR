<?php

declare(strict_types=1);

/*
 * Lo que comparten las tres herramientas de la prueba de carga que corren
 * DENTRO del contenedor `app` (`provision-fixtures.php`, `verify-after-load.php`
 * y `cleanup-after-load.php`).
 *
 * Existe por una razon concreta y no por gusto de factorizar: **quien pertenece
 * a la carga** y **como se lee el contador de divergencias** estaban escritos
 * dos veces, y los dos son criterios de los que depende que esta herramienta no
 * toque datos de nadie. Dos copias de un criterio de pertenencia acaban
 * divergiendo en la primera correccion, y la copia que se quede corta borra
 * tarjetas de personas reales.
 */

/**
 * El departamento al que pertenece TODO lo que crea la prueba de carga.
 *
 * Es la unica marca de pertenencia que vale. El prefijo del codigo por si solo
 * no sirve: una base restaurada de produccion puede tener codigos importados que
 * empiecen por `K6` —son opacos y aleatorios por diseno (doc 01 §5.5)— y
 * tratarlos como sinteticos revocaria la tarjeta de una persona real, le emitiria
 * otra que no tiene en la mano y le escribiria fichajes que no hizo.
 */
const K6_DEPARTMENT_NAME = 'Carga k6';

/** Prefijo del codigo de los empleados que crea la carga, DENTRO de su departamento. */
const K6_EMPLOYEE_CODE_PREFIX = 'K6';

/** Nombre del dispositivo que firma el historico importado. */
const K6_HISTORY_DEVICE = 'k6-history';

/** Prefijo de los quioscos sinteticos. */
const K6_DEVICE_PREFIX = 'k6-load-';

/** Cuenta de gestion desde la que se lee la vista de cumplimiento. */
const K6_MANAGER_EMAIL = 'responsable-carga-k6@kronoqr.test';

/** Donde dejan los tres scripts sus artefactos dentro del contenedor. */
const K6_WORK_DIR = '/tmp/k6';

/**
 * Dos puertas antes de escribir nada, y las dos tienen que estar abiertas.
 *
 * La primera —`isProduction()`— la puede burlar un `.env` mal copiado, que es
 * justo el escenario en el que alguien apunta esto a la base equivocada. La
 * segunda es un consentimiento explicito que no viaja en ningun `.env` del
 * producto: lo pone quien lanza la prueba.
 */
function k6_assert_test_database(): void
{
    if (app()->isProduction()) {
        throw new RuntimeException(
            'Este script crea empleados, credenciales y quioscos de prueba: jamas contra produccion.'
        );
    }

    if (getenv('K6_ACKNOWLEDGE_TEST_DATABASE') !== 'yes') {
        throw new RuntimeException(
            "La prueba de carga escribe en la base de datos a la que apunte este contenedor.\n"
            ."QUE HACER: si de verdad es un entorno de pruebas, vuelve a lanzarla con\n"
            ."K6_ACKNOWLEDGE_TEST_DATABASE=yes. Si no estas seguro de a que base apunta,\n"
            .'no la lances: mira DB_DATABASE y DB_HOST del contenedor primero.'
        );
    }
}

/**
 * El departamento de la carga, o `null` si todavia no existe.
 */
function k6_department_id(int $siteId): ?int
{
    $id = \Illuminate\Support\Facades\DB::table('departments')
        ->where('site_id', $siteId)
        ->where('name', K6_DEPARTMENT_NAME)
        ->value('id');

    return $id === null ? null : (int) $id;
}

/**
 * Se planta si hay algun empleado con el prefijo de la carga FUERA de su
 * departamento.
 *
 * Es la comprobacion que evita el peor accidente posible de esta herramienta:
 * confundir a una persona real con una fila sintetica. Ante la duda no se
 * decide, se para y se cuenta que hacer.
 */
function k6_assert_no_foreign_codes(?int $departmentId): void
{
    $foreign = \Illuminate\Support\Facades\DB::table('employees')
        ->where('employee_code', 'like', K6_EMPLOYEE_CODE_PREFIX.'%')
        ->when($departmentId !== null, static fn ($query) => $query->where(
            static fn ($inner) => $inner->whereNull('department_id')->orWhere('department_id', '<>', $departmentId)
        ))
        ->count();

    if ($foreign === 0) {
        return;
    }

    throw new RuntimeException(
        'Hay '.$foreign.' empleados cuyo codigo empieza por '.K6_EMPLOYEE_CODE_PREFIX
        ." y que NO estan en el departamento «".K6_DEPARTMENT_NAME."».\n"
        ."Los codigos de empleado son opacos y aleatorios, asi que pueden empezar por\n"
        ."cualquier cosa: esas filas pueden ser personas reales de una base restaurada.\n"
        ."QUE HACER: no se toca ninguna. Mueve la prueba a un entorno donde no existan,\n"
        .'o cambia el prefijo de la carga si de verdad son suyas y alguien las saco de su departamento.'
    );
}

/**
 * Los empleados de la carga: los de SU departamento y con SU prefijo, por orden
 * de creacion.
 *
 * Las dos condiciones a la vez, y no una: el departamento dice que los creo esta
 * herramienta y el prefijo lo confirma. Si alguien mueve a una persona real al
 * departamento de la carga —que no deberia pasar— su codigo no la dejaria entrar.
 *
 * @return \Illuminate\Support\Collection<int, object{id: int, uuid: string}>
 */
function k6_load_employees(int $departmentId, ?int $limit = null): \Illuminate\Support\Collection
{
    /** @var \Illuminate\Support\Collection<int, object{id: int, uuid: string}> $rows */
    $rows = \Illuminate\Support\Facades\DB::table('employees')
        ->where('department_id', $departmentId)
        ->where('employee_code', 'like', K6_EMPLOYEE_CODE_PREFIX.'%')
        ->where('status', 'active')
        ->orderBy('id')
        ->when($limit !== null, static fn ($query) => $query->limit($limit))
        ->get(['id', 'uuid']);

    return $rows;
}

/**
 * El fichero `.prom` del colector *textfile* donde vive
 * `projection_divergence_total`.
 */
function k6_projection_prom_file(): string
{
    return rtrim(\Illuminate\Support\Facades\Config::string('observability.metrics.textfile_path'), '/')
        .'/kronoqr_projection.prom';
}

/**
 * El valor de una serie del colector *textfile*, o `null` si no hay fichero.
 *
 * **`null` y `0` no son lo mismo y por eso no se colapsan.** Un cero significa
 * «se reconcilio y no habia divergencias»; la ausencia de fichero significa «no
 * ha corrido nunca la reconciliacion o el colector no escribe aqui», que es una
 * pregunta sin responder y no una respuesta tranquilizadora.
 */
function k6_textfile_metric(string $file, string $metric): ?int
{
    if (! is_readable($file)) {
        return null;
    }

    $value = null;

    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (str_starts_with($line, $metric.' ')) {
            $value = (int) trim(substr($line, strlen($metric) + 1));
        }
    }

    return $value;
}

/**
 * Lee el JSON que dejo el aprovisionamiento, o un array vacio si no esta.
 *
 * @return array<string, mixed>
 */
function k6_read_json(string $path): array
{
    if (! is_readable($path)) {
        return [];
    }

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    return $decoded;
}

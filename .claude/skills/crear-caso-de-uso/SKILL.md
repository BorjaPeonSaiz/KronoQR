---
name: crear-caso-de-uso
description: Genera el andamiaje hexagonal completo de un caso de uso nuevo en un módulo del backend — comando, handler, puertos, adaptadores, controlador, ruta, contrato OpenAPI y las pruebas de los cuatro niveles. Úsalo cuando haya que añadir una operación de negocio nueva al sistema (registrar algo, corregir algo, resolver algo), no para un CRUD simple.
---

# Crear un caso de uso

Genera todas las piezas de un caso de uso respetando la arquitectura hexagonal, en el orden correcto: de dentro hacia fuera.

## Antes de empezar

1. Lee `CLAUDE.md`, y del documento 01 el requisito `RF-*` que motiva el caso de uso y las reglas `RN-*` que le aplican.
2. Confirma el módulo destino. Si dudas entre dos, la frontera está mal trazada: consulta con `arquitecto-dominio` antes de seguir.
3. Busca en el repositorio si ya existe un caso de uso parecido y reutiliza sus patrones y sus puertos.

## Orden de generación

**Nunca empieces por el controlador.** Se construye de dentro hacia fuera.

### 1. Dominio (si la operación introduce reglas nuevas)

`Modules/{Módulo}/Domain/` — método en el agregado, objetos de valor necesarios, eventos que emite, excepciones específicas.

Puro: sin `Illuminate`, sin `now()`, sin otro módulo.

### 2. Puertos

`Modules/{Módulo}/Application/Port/` — solo las interfaces que este caso de uso necesita. Métodos mínimos, nombrados en el lenguaje del negocio (`findOpenWorkDayFor`, no `getByEmployeeIdAndDate`).

### 3. Comando y handler

`Application/Command/{Nombre}Command.php` — DTO `readonly` con los datos de entrada ya tipados. Nada de arrays asociativos.

`Application/UseCase/{Nombre}Handler.php` — la orquestación:

```
public function handle(XCommand $command): XResult
{
    // 1. Abrir transacción
    // 2. Cargar el agregado por su repositorio
    // 3. Invocar el método de dominio (aquí viven las reglas, no aquí dentro)
    // 4. Persistir
    // 5. Actualizar proyecciones EN LA MISMA TRANSACCIÓN
    // 6. Escribir auditoría si la acción tiene relevancia legal
    // 7. Publicar eventos de dominio
    // 8. Devolver un resultado tipado
}
```

El handler **orquesta, no decide**. Si estás escribiendo un `if` con una regla de negocio dentro del handler, esa regla pertenece al dominio.

### 4. Adaptadores

`Infrastructure/Persistence/` — implementación del repositorio con Eloquent, con el mapeo entre modelo de persistencia y modelo de dominio.
`Infrastructure/Adapter/` — el resto de puertos.
Registro de los enlaces en el `ServiceProvider` del módulo.

### 5. Contrato OpenAPI

**Antes que el controlador.** Actualiza `docs/api/openapi.yaml`: petición, respuestas de éxito, errores en `application/problem+json`, ámbito de token requerido, y `Idempotency-Key` si es una escritura del quiosco.

### 6. HTTP

`Http/Request/` — FormRequest con validación estricta.
`Http/Controller/` — delgado: valida, construye el comando, invoca el handler, devuelve el Resource. Sin lógica.
`Http/Resource/` — solo los campos que ese rol debe ver.
`Http/Policy/` — la autorización. **Obligatoria, sin excepciones.**
Ruta en `routes/api_v1.php` con su middleware de ámbito y su rate limiting.

### 7. Instrumentación

Métrica del contador y del histograma de duración, span de traza, log estructurado con `trace_id` (sin nombres de empleados).

### 8. Pruebas — los cuatro niveles

| Nivel | Ruta | Qué cubre |
|---|---|---|
| Unitaria | `tests/Unit/{Módulo}/` | El método de dominio y cada regla `RN-*`. Sin BD. |
| Integración | `tests/Integration/{Módulo}/` | El repositorio contra PostgreSQL real, incluidas las restricciones de la BD. |
| Feature | `tests/Feature/{Módulo}/` | El endpoint completo, **más una prueba negativa por cada rol no autorizado**. |
| Contrato | `tests/Contract/` | La respuesta valida contra `openapi.yaml`. |

Si la operación es una escritura del quiosco, añade la prueba de **idempotencia bajo concurrencia**: N peticiones paralelas con el mismo `scan_id`, un solo efecto.

## Verificación final

```bash
make quality && make test
```

## Lista de comprobación de entrega

- [ ] Las reglas de negocio están en `Domain/`, no en el handler
- [ ] `Domain/` no importa nada de infraestructura
- [ ] El reloj se inyecta, no se llama a `now()`
- [ ] La proyección se actualiza en la misma transacción
- [ ] Hay entrada de auditoría si la acción tiene relevancia legal
- [ ] `openapi.yaml` actualizado
- [ ] Policy registrada y **prueba negativa por rol**
- [ ] Métrica, traza y log añadidos
- [ ] Pruebas en los cuatro niveles
- [ ] `make quality && make test` en verde

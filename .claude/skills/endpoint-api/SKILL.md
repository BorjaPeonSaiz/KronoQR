---
name: endpoint-api
description: Añade o modifica un endpoint de la API partiendo del contrato OpenAPI, con validación, autorización por policy y ámbito de token, rate limiting, instrumentación, cliente TypeScript regenerado y las pruebas de contrato y de autorización negativa. Úsalo siempre que se toque la superficie HTTP del backend.
---

# Añadir o modificar un endpoint

El contrato manda. `docs/api/openapi.yaml` es la fuente de verdad y se modifica **antes** que el código: de él se generan los clientes TypeScript de los dos frontends y contra él se validan las pruebas.

## Paso 1 — Contrato primero

Edita `docs/api/openapi.yaml`:

- Ruta bajo `/api/v1/`. Un cambio incompatible exige `/v2`, no romper `/v1`: hay quioscos ahí fuera que no se actualizan a la vez (ADR-012).
- Esquema de petición con tipos y formatos precisos (`date-time` en UTC con `Z`, `uuid`, patrones donde aplique).
- Respuesta de éxito con **solo los campos que el rol autorizado debe ver**.
- Errores en `application/problem+json`.
- `security` con el ámbito de token requerido.
- Cabecera `Idempotency-Key` si es una escritura del quiosco.
- Ejemplos reales en petición y respuesta. Sirven de documentación y de datos de prueba.

## Paso 2 — Ruta y protección

En `routes/api_v1.php`:

```php
Route::post('/scan', ScanController::class)
    ->middleware(['auth:sanctum', 'ability:scan:write', 'throttle:scan']);
```

Rate limiting según el documento 02 §7.1. El limitador se define por `device_id` o por credencial, no solo por IP: en un hotel todos los quioscos comparten salida.

## Paso 3 — Validación

FormRequest con reglas estrictas. Rechaza lo desconocido en lugar de ignorarlo. Valida formato de fecha, rangos y longitudes. La validación no es autorización: no la confundas.

## Paso 4 — Autorización

Policy registrada y comprobada explícitamente. Dos comprobaciones, no una:

1. **Ámbito del token** (`ability:*`) — qué puede hacer este cliente
2. **Rol y alcance** (Policy) — sobre qué datos, incluido el filtro por departamento o centro

Un responsable de Cocina que pide un empleado de Recepción recibe 403 **y queda registrado en auditoría**.

## Paso 5 — Controlador

Delgado. Valida, construye el comando, invoca el handler, devuelve el Resource. Si tiene un `if` con una regla de negocio, está en el sitio equivocado.

## Paso 6 — Instrumentación

Contador por resultado, histograma de duración, span de traza que propaga el `trace_id` recibido, log estructurado. Sin nombres de empleados.

## Paso 7 — Cliente TypeScript

```bash
npm run api:generate     # en frontend-kiosk y en frontend-admin
```

Si el generador produce cambios de tipo que rompen el compilado, ese es exactamente el aviso que querías tener: arréglalo ahora, no en producción.

## Paso 8 — Pruebas

Obligatorias, las cuatro:

1. **Camino feliz** — respuesta y código de estado correctos
2. **Contrato** — Spectator valida la respuesta contra `openapi.yaml`
3. **Autorización negativa** — una prueba **por cada rol que no debe acceder**, verificando 403 y su registro en auditoría
4. **Validación** — entradas malformadas devuelven 422 con detalle útil

Si es escritura del quiosco, añade además la de idempotencia con peticiones concurrentes.

## Endpoints del camino de fichaje: reglas adicionales

`/api/v1/scan` y `/api/v1/scan/batch` son especiales:

- **Respuesta de tiempo constante** en los rechazos. Un atacante no puede distinguir "código inexistente" de "credencial revocada" de "firma inválida" midiendo la latencia.
- **Mensaje genérico** al cliente. El detalle va a `scan_events.result` y al log del servidor.
- **Idempotencia real** vía el UNIQUE de `scan_events.scan_id`, no un `SELECT` previo (condición de carrera).
- Presupuesto de latencia: p95 < 150 ms. Mide antes de dar por bueno.

## Lista de comprobación de entrega

- [ ] `openapi.yaml` actualizado **antes** que el código, con ejemplos
- [ ] Versionado respetado; sin cambios incompatibles en `/v1`
- [ ] Rate limiting configurado por dispositivo o credencial
- [ ] Validación estricta
- [ ] Ámbito de token **y** policy, ambos comprobados
- [ ] Controlador sin lógica de negocio
- [ ] Métrica, traza y log
- [ ] Cliente TypeScript regenerado en ambos frontends
- [ ] Las cuatro pruebas, incluida la negativa por cada rol
- [ ] Si es del quiosco: tiempo constante, mensaje genérico e idempotencia verificada

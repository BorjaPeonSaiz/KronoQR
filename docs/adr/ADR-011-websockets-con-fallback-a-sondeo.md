# ADR-011 — WebSockets con Reverb y *fallback* a sondeo

| Campo | Valor |
|---|---|
| **Estado** | Aceptada |
| **Fecha** | 11 de agosto de 2026 (redactada el 14 de agosto de 2026, tarea 0.6) |
| **Decide** | `arquitecto-dominio` con `devops-observabilidad` |
| **Afecta a** | Tareas 0.1 y 2.4 · [ADR-023](ADR-023-frontera-registro-legal-y-funcionalidad-accesoria.md) |
| **Requisitos** | RF-PA-01, RNF-P-04, RNF-D-03, RNF-P-06 |

> Procede de la primera tabla del [documento 02](../02-stack-tecnologico-y-plan-implementacion.md) §4, que ya fijaba decisión, contexto y consecuencias. Esta redacción los desarrolla y los enlaza con los requisitos y con las reglas duras; no cambia la decisión.

## Contexto

RF-PA-01 pide una vista **en tiempo real** de quién está fichado, con actualización *push* y no por sondeo. La razón es operativa: el panel de presencia se mira en el cambio de turno, cuando entra y sale gente cada pocos segundos, y una vista que va con quince segundos de retraso induce a error a quien está comprobando si ha llegado el equipo de cocina.

Sondear era la alternativa evidente y no aguanta el cálculo: con 500 empleados y un sondeo cada 5 segundos, el panel abierto en tres puestos genera decenas de miles de consultas por hora contra la misma base de datos que atiende el camino crítico del fichaje (RNF-P-02), y aun así llega tarde. Es caro y además impreciso.

Pero el requisito de disponibilidad tira en la otra dirección: el sistema se instala en el servidor de un hotel, detrás de un proxy que el fabricante no controla, y **los WebSockets son lo primero que se rompe** en una red corporativa mal configurada. Si el panel depende de ellos, la caída del WebSocket se percibe como caída del sistema.

## Decisión

**Laravel Reverb como servidor WebSocket autoalojado, con *fallback* automático a sondeo cada 15 segundos.**

- **Reverb** por ser de primera parte, autoalojado y sin coste por mensaje, coherente con un producto que se despliega completo en el servidor del cliente y funciona sin salida a internet (§6.7). Corre como un proceso más del `docker compose`.
- **El *fallback* no es una mejora futura: es parte del diseño** (RNF-D-03). Si la conexión no se establece o se pierde, el panel pasa a sondear cada 15 s y lo indica en la interfaz. La información sigue estando, con menos frescura.
- **El tiempo real es funcionalidad accesoria** en el sentido de [ADR-023](ADR-023-frontera-registro-legal-y-funcionalidad-accesoria.md), y es la **única degradación parcial** de esa lista: al caducar la licencia degrada a sondeo en lugar de apagarse, porque apagarlo se percibiría como una avería y no como una licencia vencida.

**Nada del registro legal viaja por el WebSocket.** El canal difunde eventos de presencia para una vista de lectura; el fichaje se registra por HTTP y su respuesta no depende de que el canal esté vivo.

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **Sondeo cada 5 s como único mecanismo** | Caro e impreciso a la vez: carga constante sobre la base de datos del camino crítico y, aun así, hasta 5 s de retraso. Con varios paneles abiertos, la carga se multiplica por puesto |
| **Servicio gestionado de WebSockets** (Pusher y equivalentes) | Coste por mensaje y **dependencia de internet** en un producto que debe funcionar en red aislada. Además implicaría que datos de presencia de la plantilla del cliente salgan a un tercero, lo que contradice RL-14 y el modelo de responsabilidad del §7.3 |
| **Server-Sent Events** | Unidireccional y suficiente para este caso, con menos infraestructura; se descarta porque Reverb ya viene resuelto en el stack con autenticación por canal privado, y SSE arrastra límites de conexiones por dominio en algunos navegadores. Es la alternativa más razonable de esta tabla y quedaría como plan B si Reverb resultara costoso de operar |
| **Long polling permanente** | Mantiene ocupados los trabajadores de PHP-FPM, que es justo el recurso que el pico de RNF-P-06 necesita libre |
| **WebSocket sin *fallback*** | Un proxy corporativo mal configurado dejaría el panel congelado sin explicación. La degradación tiene que ser honesta y visible |

## Consecuencias

- **Un proceso más que operar y monitorizar.** El `docker compose` gana el servicio `reverb`, y el panel de salud debe distinguir «WebSocket caído» de «sistema caído», porque no son lo mismo y el segundo es mucho más grave.
- **Nginx necesita configuración de *upgrade*** para el tráfico WSS, y la CSP debe permitir `connect-src 'self' wss:` (§7.2). Es un fallo de configuración que se diagnostica mal y cuesta horas.
- **Los canales son privados y autorizados por rol.** Un responsable de departamento no puede suscribirse a la presencia de otro: la autorización del canal es tan obligatoria como la del endpoint (regla dura 18, RF-ID-03).
- **Hay dos caminos que mantener y probar**, el *push* y el sondeo, y el segundo tiende a pudrirse porque casi nunca se usa. Por eso su prueba es explícita y no opcional.
- **La degradación a sondeo es visible al usuario.** Un indicador dice que la vista se actualiza cada 15 s; sin él, el operador cree que no entra nadie.
- **El WebSocket no es un canal de escritura.** Ningún fichaje, corrección ni acción con relevancia legal viaja por ahí.

## Verificación

- Prueba de *feature*: un fichaje difunde el evento de presencia al canal del centro correspondiente y no a otros.
- Prueba de autorización negativa: un responsable de departamento no puede suscribirse al canal de otro departamento ni de otro centro (RF-ID-03).
- Prueba E2E: con el WebSocket caído, el panel sigue actualizándose por sondeo cada 15 s y lo indica en la interfaz (RNF-D-03).
- Prueba E2E: al restablecerse la conexión, el panel vuelve a *push* sin recargar y sin duplicar filas.
- Prueba de *feature* (ADR-023): con la licencia caducada, la presencia degrada a sondeo y **no** se apaga.
- Carga del panel con 500 empleados por debajo de 1,5 s de LCP (RNF-P-04).

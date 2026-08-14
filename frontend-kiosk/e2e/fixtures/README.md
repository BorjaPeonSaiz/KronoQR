# Fixtures de las pruebas E2E con camara simulada

Chromium sabe hacerse pasar por una camara. La suite E2E del quiosco lo aprovecha (doc 02
§9.4):

```bash
chromium --use-fake-device-for-media-stream \
         --use-file-for-fake-video-capture=e2e/fixtures/qr-video.y4m
```

## `qr-video.y4m` — que debe contener

Un video **sin comprimir en formato YUV4MPEG2** (`.y4m`), que es el unico que acepta
`--use-file-for-fake-video-capture`. Requisitos:

| Propiedad              | Valor                                                                                                                                                  |
| ---------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Formato                | YUV4MPEG2, `yuv420p`                                                                                                                                   |
| Resolucion             | 1280x720, la misma que pide el quiosco al `MediaStream`                                                                                                |
| Fotogramas por segundo | 30                                                                                                                                                     |
| Duracion               | 3–5 s, en bucle                                                                                                                                        |
| Contenido              | Un QR **real** de prueba, con payload `FH1.<key_id>.<token>.<sig>` firmado por la clave de desarrollo, nivel de correccion de errores **Q** (RF-QR-05) |

Y una segunda variante, `qr-video-degraded.y4m`, con el QR **parcialmente ocluido**, para
comprobar que el nivel Q cumple lo que promete (doc 02 §9.4, fila "QR degradado").

## Por que todavia no esta

Porque **necesita una credencial real**, y las credenciales HMAC con `key_id` y revocacion
son de la **tarea 1.5**. Generar el video antes significaria inventar un payload que luego no
validaria el servidor, y una prueba E2E que pasa contra un payload falso no prueba nada.

El video **no lleva datos personales**: el payload de la tarjeta nunca los contiene
(CLAUDE.md, regla dura 10).

## Como se generara (tarea 1.5)

1. `php artisan credential:issue` sobre un empleado de la semilla de desarrollo.
2. Renderizar el QR a PNG con nivel de correccion Q.
3. `ffmpeg -loop 1 -i qr.png -t 4 -r 30 -s 1280x720 -pix_fmt yuv420p e2e/fixtures/qr-video.y4m`
4. Repetir con la imagen parcialmente tapada para la variante degradada.

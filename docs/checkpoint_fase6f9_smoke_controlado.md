# FASE 6F.9 — SMOKE CONTROLADO: UN SOLO ENVÍO

## Estado
- [completado]

## A. PRE-FLIGHT
| Comprobación | Resultado |
|---|---|
| config.modo_entorno | `test` (correcto) |
| config.motor_estado | `pausado` (correcto) |
| Campaña 3 | id=3, estado=PILOT, entorno=test, activo=1 |
| validarCampanaActiva(db,3,'test') | `CAMPANIA_VALIDA` |
| Lead 1810 | existe, TEST (`esLeadTest=true`), no duplicado, sin supresión, email válido |
| Elegibilidad `esElegibleParaEnvio(1810,3)` | `elegible` |
| Idempotencia previa (1810,3) | `LIMPIO` (0 filas) |
| Plantilla 2 | activa, test_ab=1, variantes A/B/C completas |
| Variante `asignarVariante(1810,3)` | `A` |
| Destinatario final previsto | `estudioriobabel@hmail.com` |
| SMTP seleccionado | id=1, rodrigo@getfutprotec.com, límite 50, capacidad 50 |

## B. ENVÍO
- Ejecución realizada: `POST enviar_lote.php` con campaign_id=3, id_club=1810, id_plantilla=2, id_cuenta_smtp=1, modo_test=1, test_email=estudioriobabel@hmail.com — UNA SOLA VEZ.
- Respuesta: `{"ok":true,"envio_exitoso":true,"estado":"enviado","cuenta_smtp":"rodrigo@getfutprotec.com","cuenta_id":1}`
- Destinatario real (SMTP): `estudioriobabel@hmail.com`
- Resultado SMTP: `ACCEPTED`
- message_id: `<fut_6a8111e4_2306cee0a376@getfutprotec.com>`
- envio_id: `6`

## C. TRAZABILIDAD (envios)
| Campo | Valor |
|---|---|
| id | 6 |
| campaign_id | 3 |
| lead_id | 1810 |
| variant | A |
| plantilla_id | 2 |
| smtp_id | 1 |
| message_id | `<fut_6a8111e4_2306cee0a376@getfutprotec.com>` |
| resultado_envio | ACCEPTED |
| estado | enviado |

## D. LOG (comunicaciones_log)
- id=29, lead_id=1810, club_id=1810, tipo_evento=envio_email, plantilla_id=2, id_cuenta_smtp=1, tipo=email, resultado=exito, variante_ab=A.
- Detalles: `Envío a test02@futprotec.local con plantilla Primer Contacto (ABC - Texto Plano)`.

## E. LEAD 1810
| Campo | Antes | Después |
|---|---|---|
| estado_lead | 01 Sin Contactar | 01 Sin Contactar (NO cambió) |
| ultimo_contacto | NULL | 2026-08-16 01:27:01 (cambió) |
| observaciones | (vacío) | nota `[TEST 16/08 01:27] Email de prueba enviado a estudioriobabel@hmail.com ...` |

Comportamiento en modo_test=1: NO se modifica `estado_lead`; se escribe `ultimo_contacto` y nota de observaciones (comportamiento preexistente del flujo, no modificado).

## F. IDEMPOTENCIA (post-envío)
- Exactamente 1 fila lógica para (lead_id=1810, campaign_id=3): envio_id=6.
- Estado final `enviado` (bloqueante).
- Segunda ejecución hipotética → `reservarEnvioLogico()` (INSERT OR IGNORE) no crea fila; la rama de estado final devuelve `dup=true` → NO permitida.

## G. SEGURIDAD
```
otros leads enviados: NO
otros emails enviados: NO (único envio_email hoy = lead 1810)
cron ejecutado: NO
Evolution API ejecutada: NO
campaña distinta utilizada: NO
modo_entorno cambiado durante el smoke: NO (sigue en test)
motor_estado cambiado: NO (sigue pausado)
```
- Envíos campaign_id=3 tras smoke: ids 3,4,5 (previos a esta fase) + 6 (el smoke). Solo el lead 1810 corresponde a este smoke.
- Otros leads TEST (1809,1811,1812,1813) conservan sus estados/ultimo_contacto previos; ninguno fue modificado por este smoke.

## H. VEREDICTO

```text
SMOKE_PASS
```

## Parada obligatoria
No se revierte `modo_entorno` (permanece `test`). No se ejecuta segundo envío. No se ejecuta cron. La siguiente decisión se tomará a partir de este resultado.
# Checkpoint — Auditoría de placeholders comerciales FutProtec

Fecha: 16/08/2026
Fase: SOLO LECTURA — mapa real de placeholders del motor de envío.

## Veredicto

```
COMMERCIAL_PLACEHOLDERS_CONFIRMED
```

## Mapa real por flujo de envío

Hay **tres** caminos que construyen emails, con mapas de sustitución **distintos**:

### 1. `api/enviar_lote.php` (LANZADERA — flujo principal activo)

Mapa completo (líneas 176–188):

```php
$replacements = [
    '{{CLUB}}'         => $club['nombre_club'],
    '{{CONTACTO}}'      => $club['persona_contacto'] ?: 'responsable',
    '{{FEDERACION}}'    => $club['federacion'] ?? '',
    '{{ANIO}}'          => date('Y'),
    '{{EMAIL}}'         => $club['email'],
    '{{SENDER_NAME}}'   => $senderName,   // fallback: ucfirst(parte local del email)
    '{{SENDER_TITLE}}'  => $senderTitle,
    '{{SENDER_EMAIL}}'  => $senderEmail,
];
```

Sustituye en **asunto y cuerpo** (str_replace sobre ambos).

### 2. `cli/cron.php` (P3 — primer contacto automático)

Mapa reducido (líneas 176–196), **solo 4 placeholders**:

```php
['{{CLUB}}', '{{CONTACTO}}', '{{FEDERACION}}', '{{ANIO}}']
```

**NO** sustituye `{{EMAIL}}`, `{{TELEFONO}}`, ni `{{SENDER_*}}`.
En este flujo, si la plantilla lleva `{{EMAIL}}`, queda literal en el email.

### 3. `api/enviar_smtp_random.php` (BLOQUEADO)

La primera línea ejecutable es `die("SISTEMA BLOQUEADO ...")` (línea 7). No opera.
Internamente usaba solo 4 placeholders y `{{CONTACTO}}` hardcodeado a `'responsable'`.

### (Extra) `dashboard.php` → `preview_template`

Endpoint de previsualización (no envía). Sustituye solo
`{{CLUB}}`, `{{CONTACTO}}` (fallback `'responsable'`), `{{FEDERACION}}`, `{{ANIO}}`.

## Origen de datos y cobertura real (BD, solo lectura)

Total leads: **1817**

| Campo | No vacío | Vacío | Cobertura |
| ----- | -------- | ----- | --------- |
| `persona_contacto` | **5** | 1812 | 0.3 % |
| `telefono_movil` | **1732** | 85 | 95.3 % |
| `telefono_fijo` | 365 | 1452 | 20.1 % |
| `federacion` | 1812 | 5 | 99.7 % |

## Tabla final de soporte

| Placeholder | ¿Soportado? | Fuente real | ¿Seguro comercialmente? | Observación |
| ----------- | ----------- | ----------- | ----------------------- | ----------- |
| `{{CLUB}}` | SÍ (todos los flujos) | `clubes_crm.nombre_club` | SÍ | Siempre presente |
| `{{FEDERACION}}` | SÍ (todos los flujos) | `clubes_crm.federacion` | SÍ | Fallback `''`; 5 leads vacíos |
| `{{ANIO}}` | SÍ (todos los flujos) | `date('Y')` | SÍ | Valor calculado, siempre |
| `{{EMAIL}}` | SÍ solo en lanzadera | `clubes_crm.email` | CONDICIONAL | **NO** en `cron.php`; en cron quedaría literal |
| `{{CONTACTO}}` | SÍ (todos los flujos) | `clubes_crm.persona_contacto` | CONDICIONAL | Fallback `'responsable'` → personalizado solo en 5/1817 leads |
| `{{TELEFONO}}` | **NO** | — | **NO USAR** | No existe en ningún mapa; quedaría literal |
| `{{SENDER_NAME}}` | SÍ solo en lanzadera | `cuentas_smtp.nombre_emisor` | CONDICIONAL | Fallback a parte local del email |
| `{{SENDER_TITLE}}` | SÍ solo en lanzadera | `cuentas_smtp.cargo_emisor` | CONDICIONAL | Fallback `''` |
| `{{SENDER_EMAIL}}` | SÍ solo en lanzadera | `cuentas_smtp.email` | CONDICIONAL | Siempre presente |

## URL de baja

Placeholder correcto: `{{EMAIL}}`.

```text
https://getfutprotec.com/outbound/api/baja.php?email={{EMAIL}}
```

- **Lanzadera (`enviar_lote.php`):** `{{EMAIL}}` se sustituye correctamente. OK.
- **Cron (`cron.php`):** `{{EMAIL}}` **NO** se sustituye → el enlace quedaría con
  `{{EMAIL}}` literal. Riesgo alto si la plantilla la consume el cron.

## Riesgo de placeholder no soportado

- `{{TELEFONO}}` NO está en ningún mapa. Una plantilla que lo incluya envía el
  texto literal `{{TELEFONO}}` al destinatario.
- `{{EMAIL}}` y `{{SENDER_*}}` solo se sustituyen en lanzadera; en cron quedarían
  literales.
- `{{CONTACTO}}` nunca queda literal (tiene fallback `'responsable'`), pero en la
  práctica produce `"responsable"` genérico en ~99.7 % de los envíos.

## Recomendación operativa

- **SEGUROS:** `{{CLUB}}`, `{{FEDERACION}}`, `{{ANIO}}`.
- **CONDICIONALES:** `{{CONTACTO}}` (no personaliza de verdad en la base actual),
  y —solo si el envío va por lanzadera— `{{EMAIL}}`, `{{SENDER_NAME}}`,
  `{{SENDER_TITLE}}`, `{{SENDER_EMAIL}}`.
- **NO USAR:** `{{TELEFONO}}`.
- Evitar `{{EMAIL}}` en plantillas que pueda consumir el cron (P3), salvo que se
  garantice el flujo lanzadera.

## Seguridad

- SMTP = NO · POST de envío = NO · cron = NO · Evolution API = NO
- BD modificada = NO · leads/plantillas/campañas = NO · commit/push = NO
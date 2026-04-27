# API ADRES — Consulta de Afiliado por Cédula

## Descripción

Retorna el **historial completo** de consultas realizadas a ADRES para un número de cédula específico, ordenado del registro **más reciente al más antiguo**. Solo se incluyen consultas exitosas.

---

## Endpoint

```
GET /api/consulta/cedula/{cedula}
```

### Parámetros de ruta

| Parámetro | Tipo   | Requerido | Descripción                          |
|-----------|--------|-----------|--------------------------------------|
| `cedula`  | string | Sí        | Número de cédula (solo dígitos)      |

### Autenticación

Requiere token **Bearer** de Sanctum en el header de la petición.

```
Authorization: Bearer <token>
```

---

## Ejemplo de petición

```http
GET /api/consulta/cedula/1234567890
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGc...
Accept: application/json
```

---

## Respuestas

### 200 — Consulta exitosa

```json
{
  "success": true,
  "message": "Consulta exitosa.",
  "total": 3,
  "data": [
    {
      "cedula": "1234567890",
      "tipo_documento": "CC",
      "nombres": "JUAN CARLOS",
      "apellidos": "PÉREZ GÓMEZ",
      "fecha_nacimiento": "1985-06-15",
      "departamento": "ANTIOQUIA",
      "municipio": "MEDELLÍN",
      "estado_afiliacion": "ACTIVO",
      "entidad_eps": "SURA EPS",
      "regimen": "CONTRIBUTIVO",
      "fecha_afiliacion": "2022-01-01",
      "fecha_finalizacion": null,
      "tipo_afiliado": "COTIZANTE",
      "consultado_en": "2026-04-27T10:30:00+00:00"
    },
    {
      "cedula": "1234567890",
      "tipo_documento": "CC",
      "nombres": "JUAN CARLOS",
      "apellidos": "PÉREZ GÓMEZ",
      "fecha_nacimiento": "1985-06-15",
      "departamento": "ANTIOQUIA",
      "municipio": "MEDELLÍN",
      "estado_afiliacion": "ACTIVO",
      "entidad_eps": "NUEVA EPS",
      "regimen": "CONTRIBUTIVO",
      "fecha_afiliacion": "2020-03-10",
      "fecha_finalizacion": "2021-12-31",
      "tipo_afiliado": "COTIZANTE",
      "consultado_en": "2026-03-15T08:00:00+00:00"
    }
  ]
}
```

### 404 — Sin resultados

```json
{
  "success": false,
  "message": "No se encontraron resultados para la cédula proporcionada.",
  "data": null
}
```

---

## Descripción de campos del JSON de respuesta

### Nivel raíz

| Campo     | Tipo    | Descripción                                                     |
|-----------|---------|-----------------------------------------------------------------|
| `success` | boolean | `true` si la operación fue exitosa, `false` en caso contrario  |
| `message` | string  | Mensaje descriptivo del resultado                               |
| `total`   | integer | Cantidad total de registros retornados                          |
| `data`    | array   | Arreglo de objetos con el historial de consultas                |

### Objeto dentro de `data[]`

| Campo               | Tipo            | Descripción                                                                 |
|---------------------|-----------------|-----------------------------------------------------------------------------|
| `cedula`            | string          | Número de documento del afiliado                                            |
| `tipo_documento`    | string          | Tipo de documento (ej. `CC`, `TI`, `CE`, `PA`)                             |
| `nombres`           | string          | Nombres del afiliado tal como aparecen en ADRES                             |
| `apellidos`         | string          | Apellidos del afiliado                                                      |
| `fecha_nacimiento`  | string / null   | Fecha de nacimiento en formato `YYYY-MM-DD`                                 |
| `departamento`      | string / null   | Departamento de residencia registrado en ADRES                              |
| `municipio`         | string / null   | Municipio de residencia registrado en ADRES                                 |
| `estado_afiliacion` | string / null   | Estado actual del afiliado (ej. `ACTIVO`, `RETIRADO`, `SUSPENDIDO`)         |
| `entidad_eps`       | string / null   | Nombre de la EPS a la que está o estuvo afiliado                            |
| `regimen`           | string / null   | Régimen de salud (ej. `CONTRIBUTIVO`, `SUBSIDIADO`)                         |
| `fecha_afiliacion`  | string / null   | Fecha de inicio de afiliación en la EPS (`YYYY-MM-DD`)                      |
| `fecha_finalizacion`| string / null   | Fecha de finalización de la afiliación (`YYYY-MM-DD`). `null` si aún activa |
| `tipo_afiliado`     | string / null   | Tipo de afiliado (ej. `COTIZANTE`, `BENEFICIARIO`)                          |
| `consultado_en`     | string ISO 8601 | Fecha y hora en que se realizó la consulta a ADRES (UTC)                    |

---

## Notas

- Los registros se ordenan de **más reciente a más antiguo** según el campo `consultado_en`.
- Solo se retornan consultas marcadas como **exitosas** (`exitosa = true`).
- Si la cédula no tiene registros exitosos en la base de datos, se retorna HTTP `404`.
- El campo `cedula` en la URL solo acepta dígitos numéricos; cualquier otro carácter retorna `404` automáticamente.

<?php
/**
 * validate_emails.php — Valida emails de CSVs de scraping y carga en el CRM.
 * 
 * Reemplaza a scripts/validate_emails.py con PHP nativo compatible con SiteGround.
 * 
 * Funcionalidad:
 * 1. Lee todos los CSVs output/clubs_nova_*.csv
 * 2. Valida sintaxis (filter_var) + registro MX (checkdnsrr)
 * 3. Email válido   → INSERT en clubes_crm con estado "Sin Contactar"
 * 4. Email inválido → INSERT en clubes_crm con estado "Archivado" + motivo en observaciones
 * 5. También genera output/clean/contactos_sintaxis_ok.csv y contactos_descartados.csv
 * 
 * Uso: php scripts/validate_emails.php
 *      php scripts/validate_emails.php --dry-run    (solo valida, no escribe BD)
 */

declare(strict_types=1);

// ─── Configuración ───
$DRY_RUN = in_array('--dry-run', $argv ?? [], true);

$BASE_DIR   = dirname(__DIR__);
$OUTPUT_DIR = $BASE_DIR . DIRECTORY_SEPARATOR . 'output';
$CLEAN_DIR  = $OUTPUT_DIR . DIRECTORY_SEPARATOR . 'clean';
$DB_PATH    = $BASE_DIR . DIRECTORY_SEPARATOR . 'public_html' . DIRECTORY_SEPARATOR . 'outbound' . DIRECTORY_SEPARATOR . 'stats.db';

@mkdir($CLEAN_DIR, 0755, true);

// ─── Conexión a SQLite ───
if (!file_exists($DB_PATH)) {
    echo "ERROR: stats.db no encontrada en {$DB_PATH}\n";
    echo "Ejecuta primero: php public_html/outbound/init_db.php\n";
    exit(1);
}

$db = new SQLite3($DB_PATH);
$db->enableExceptions(true);
$db->exec('PRAGMA journal_mode=WAL');
$db->exec('PRAGMA busy_timeout=5000');

// ─── Preparar statements para inserción ───
$stmtInsert = $db->prepare(
    "INSERT OR IGNORE INTO clubes_crm (nombre_club, federacion, email, telefono_movil, estado_lead, observaciones, creado_el)
     VALUES (:nombre, :fed, :email, :tel, :estado, :obs, CURRENT_TIMESTAMP)"
);

$stmtCheck = $db->prepare("SELECT id FROM clubes_crm WHERE LOWER(email) = LOWER(:email)");

// ─── Buscar CSVs fuente ───
$sourceFiles = glob($OUTPUT_DIR . DIRECTORY_SEPARATOR . 'clubs_nova_*.csv');
if (empty($sourceFiles)) {
    echo "No se encontraron archivos output/clubs_nova_*.csv\n";
    $db->close();
    exit(1);
}

sort($sourceFiles);

// ─── Abrir archivos de salida ───
$descPath = $CLEAN_DIR . DIRECTORY_SEPARATOR . 'contactos_descartados.csv';
$okPath   = $CLEAN_DIR . DIRECTORY_SEPARATOR . 'contactos_sintaxis_ok.csv';

$fDesc = fopen($descPath, 'w');
$fOk   = fopen($okPath, 'w');

// Cabeceras
fputcsv($fDesc, ['federacion', 'nombre', 'telefono', 'email', 'motivo']);
fputcsv($fOk,   ['federacion', 'nombre', 'telefono', 'email']);

// ─── Contadores ───
$totalRows      = 0;
$insertadosOK   = 0;
$insertadosArch = 0;
$yaExistian     = 0;
$errores        = 0;

echo "══════════════════════════════════════════════\n";
echo "  FutProtec — Validate & Import Emails to CRM\n";
echo "  Modo: " . ($DRY_RUN ? "DRY-RUN (sin escribir BD)" : "PRODUCCIÓN") . "\n";
echo "══════════════════════════════════════════════\n\n";

foreach ($sourceFiles as $filepath) {
    $filename = basename($filepath);
    echo "Procesando: {$filename}...\n";

    $fh = fopen($filepath, 'r');
    if (!$fh) {
        echo "  ERROR: No se pudo abrir {$filename}\n";
        continue;
    }

    // Leer cabecera
    $headers = fgetcsv($fh);
    if (!$headers) {
        fclose($fh);
        continue;
    }
    $headers = array_map('trim', $headers);

    // Mapear índices
    $idxFed   = array_search('federacion', $headers);
    $idxNombre = array_search('nombre', $headers);
    $idxTel   = array_search('telefono', $headers);
    $idxEmail = array_search('email', $headers);

    if ($idxNombre === false || $idxEmail === false) {
        echo "  ERROR: Columnas 'nombre' o 'email' no encontradas\n";
        fclose($fh);
        continue;
    }

    while (($row = fgetcsv($fh)) !== false) {
        $totalRows++;

        $fed    = trim($row[$idxFed] ?? '');
        $nombre = trim($row[$idxNombre] ?? '');
        $tel    = trim($row[$idxTel] ?? '');
        $email  = trim($row[$idxEmail] ?? '');

        // Saltar filas sin nombre
        if ($nombre === '') {
            continue;
        }

        // ─── Validación de email ───
        $motivo = '';
        $esValido = false;

        if ($email === '') {
            $motivo = 'empty email';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $motivo = 'syntax invalid';
        } else {
            $domain = substr($email, strrpos($email, '@') + 1);
            if (!checkdnsrr($domain, 'MX')) {
                $motivo = 'no MX record';
            } else {
                $esValido = true;
            }
        }

        // ─── Determinar estado ───
        $estadoLead = $esValido ? 'Sin Contactar' : 'Archivado';
        $observaciones = $motivo ? "Email inválido: {$motivo}" : '';

        // ─── Escribir CSV de salida ───
        $csvRow = [$fed, $nombre, $tel, $email];
        if ($esValido) {
            fputcsv($fOk, $csvRow);
        } else {
            fputcsv($fDesc, array_merge($csvRow, [$motivo]));
        }

        // ─── Insertar en BD ───
        if (!$DRY_RUN) {
            try {
                // Verificar si ya existe
                $stmtCheck->bindValue(':email', $email, SQLITE3_TEXT);
                $existente = $stmtCheck->execute()->fetchArray(SQLITE3_ASSOC);
                $stmtCheck->reset();

                if ($existente) {
                    $yaExistian++;
                } else {
                    $stmtInsert->bindValue(':nombre', $nombre, SQLITE3_TEXT);
                    $stmtInsert->bindValue(':fed', $fed, SQLITE3_TEXT);
                    $stmtInsert->bindValue(':email', $email, SQLITE3_TEXT);
                    $stmtInsert->bindValue(':tel', $tel, SQLITE3_TEXT);
                    $stmtInsert->bindValue(':estado', $estadoLead, SQLITE3_TEXT);
                    $stmtInsert->bindValue(':obs', $observaciones, SQLITE3_TEXT);
                    $stmtInsert->execute();
                    $stmtInsert->reset();

                    if ($esValido) {
                        $insertadosOK++;
                    } else {
                        $insertadosArch++;
                    }
                }
            } catch (\Exception $e) {
                $errores++;
                if ($errores <= 5) {
                    echo "  ERROR DB: {$e->getMessage()}\n";
                }
            }
        }
    }

    fclose($fh);
    echo "  OK\n";
}

fclose($fDesc);
fclose($fOk);
$db->close();

// ─── Resumen ───
echo "\n══════════════════════════════════════════════\n";
echo "  RESUMEN\n";
echo "══════════════════════════════════════════════\n";
echo "Total filas procesadas:    {$totalRows}\n";
echo "Insertados (Sin Contactar): {$insertadosOK}\n";
echo "Insertados (Archivado):     {$insertadosArch}\n";
echo "Ya existían en BD:         {$yaExistian}\n";
echo "Errores:                   {$errores}\n";
echo "\n";
echo "Archivos generados:\n";
echo "  - {$okPath}\n";
echo "  - {$descPath}\n";

if ($DRY_RUN) {
    echo "\n⚠️  MODO DRY-RUN: No se escribió en la base de datos.\n";
    echo "   Ejecuta sin --dry-run para importar los contactos.\n";
}
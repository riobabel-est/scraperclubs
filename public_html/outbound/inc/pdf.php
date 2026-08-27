<?php
/**
 * pdf.php — Generación de PDF mínimos sin librerías externas (PHP nativo).
 * Compatible con SiteGround (no requiere extensiones PECL). Produce un PDF
 * válido con texto (fuente Helvetica/WinAnsi) a partir de líneas.
 */

if (!function_exists('construirPdfSimple')) {
/**
 * Construye un PDF de 1 página (A4 vertical) con líneas de texto.
 * @param string[] $lineas Texto de cada línea (UTF-8).
 * @return string Contenido binario del PDF.
 */
function construirPdfSimple(array $lineas): string
{
    $texto = "BT\n/F1 12 Tf\n50 800 Td\n";
    $primera = true;
    foreach ($lineas as $l) {
        if (!$primera) $texto .= "0 -18 Td\n";
        $primera = false;
        $esc = @iconv('UTF-8', 'Windows-1252//TRANSLIT', (string)$l);
        if ($esc === false) $esc = (string)$l;
        $esc = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $esc);
        $texto .= "(" . $esc . ") Tj\n";
    }
    $texto .= "ET\n";
    $streamLen = strlen($texto);

    $objects = [];
    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[2] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
    $objects[3] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>';
    $objects[4] = "<< /Length {$streamLen} >>\nstream\n{$texto}endstream";
    $objects[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';

    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $num => $body) {
        $offsets[$num] = strlen($pdf);
        $pdf .= "{$num} 0 obj\n{$body}\nendobj\n";
    }
    $xrefStart = strlen($pdf);
    $totalObj = count($objects) + 1;
    $pdf .= "xref\n0 {$totalObj}\n";
    $pdf .= "0000000000 65535 f \n";
    for ($n = 1; $n <= count($objects); $n++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$n]);
    }
    $pdf .= "trailer\n<< /Size {$totalObj} /Root 1 0 R >>\nstartxref\n{$xrefStart}\n%%EOF\n";
    return $pdf;
}
}

if (!function_exists('generarPdfPresupuesto')) {
/**
 * Genera el PDF de PRESUPUESTO para un club.
 * @param array $d Claves: club, importe, contacto, concepto, validez_dias
 * @return string Contenido binario del PDF.
 */
function generarPdfPresupuesto(array $d): string
{
    $lineas = [
        'PRESUPUESTO FUTPROTEC',
        '===========================',
        '',
        'Club: ' . (string)($d['club'] ?? ''),
        'Fecha: ' . date('d/m/Y'),
        '',
        'Concepto: ' . (string)($d['concepto'] ?? 'Espinilleras personalizadas'),
        'Importe estimado: ' . (string)($d['importe'] ?? 'A convenir'),
        '',
        'Validez: ' . (int)($d['validez_dias'] ?? 15) . ' días',
        '',
        'Contacto: ' . (string)($d['contacto'] ?? 'Atención a Clubes - FutProtec'),
        'Tel / WhatsApp: +34 711 25 90 81 · www.futprotec.com',
    ];
    return construirPdfSimple($lineas);
}
}

if (!function_exists('generarPdfBoceto')) {
/**
 * Genera un PDF de BOCETO (espinilleras personalizadas) para un club.
 * @param array $d Claves: club, colores, diseno, notas
 * @return string Contenido binario del PDF.
 */
function generarPdfBoceto(array $d): string
{
    $lineas = [
        'BOCETO — ESPINILLERAS PERSONALIZADAS',
        '======================================',
        '',
        'Club: ' . (string)($d['club'] ?? ''),
        'Colores: ' . (string)($d['colores'] ?? 'Por confirmar'),
        'Diseño: ' . (string)($d['diseno'] ?? 'Escudo del club + colores de la equipación'),
        '',
        'El boceto final se confirmará por WhatsApp con el responsable.',
        '',
        'Atención a Clubes - FutProtec · +34 711 25 90 81 · www.futprotec.com',
    ];
    return construirPdfSimple($lineas);
}
}

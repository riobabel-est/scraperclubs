<?php
/**
 * helpers.php — Funciones auxiliares de presentación del CRM Outbound.
 * Extraídas de dashboard.php para modularizar el monolito.
 * PHP 8.x nativo — SiteGround compatible. Sin dependencias externas.
 */

declare(strict_types=1);

/**
 * Calcula precio B2B y margen según el volumen de pares.
 * @param int $volumen pares estimados
 * @param int $pvp     precio de venta al público por par
 */
function calcularPrecioYMargenLocal(int $volumen, int $pvp = 15): array
{
    if ($volumen <= 0) {
        return ['precio_b2b' => null, 'facturacion' => null, 'margen_par' => null, 'margen_total' => null, 'tramo' => 'Desconocido'];
    }
    if ($volumen >= 200) {
        [$precio, $tramo] = [7, '200+ pares'];
    } elseif ($volumen >= 100) {
        [$precio, $tramo] = [8, '100-199 pares'];
    } elseif ($volumen >= 50) {
        [$precio, $tramo] = [9, '50-99 pares'];
    } else {
        return ['precio_b2b' => null, 'facturacion' => null, 'margen_par' => null, 'margen_total' => null, 'tramo' => '<50 pares'];
    }
    return [
        'precio_b2b'   => $precio,
        'facturacion'  => $volumen * $precio,
        'margen_par'   => $pvp - $precio,
        'margen_total' => $volumen * ($pvp - $precio),
        'tramo'        => $tramo,
    ];
}

/**
 * Devuelve el enlace de WhatsApp (wa.me) para un móvil español (6/7 + 8 dígitos).
 * @param string $m teléfono móvil (puede contener varios separados por coma)
 */
function getWaLink(string $m): string
{
    $n = explode(',', $m);
    $f = trim($n[0] ?? '');
    return ($f !== '' && preg_match('/^[67]\d{8}$/', $f)) ? 'https://wa.me/34' . $f : '';
}

/**
 * Escapa una cadena para salida HTML segura.
 * @param string $s cadena a escapar
 */
function escHtml(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

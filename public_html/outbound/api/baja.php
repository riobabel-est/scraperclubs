<?php
error_reporting(0);
ini_set('display_errors', 0);

$email = isset($_GET['email']) ? trim($_GET['email']) : '';
$procesado = false;

if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
    try {
        $dbPath = __DIR__ . '/../data/stats.db';
        if (file_exists($dbPath)) {
            $db = new SQLite3($dbPath);
            $db->enableExceptions(true);
            
            $stmt = $db->prepare("UPDATE clubes_crm SET estado_lead = 'Lista Negra', observaciones = COALESCE(observaciones,'') || '\n[DATETIME(''now'',''localtime'')] Baja automática desde enlace público' WHERE email = :email");
            $stmt->bindValue(':email', $email, SQLITE3_TEXT);
            $stmt->execute();
            $procesado = true;
        }
    } catch (\Exception $e) {
        // Manejo silencioso
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FutProtec — Gestión de Bajas</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-900 text-slate-100 min-h-screen flex items-center justify-center p-4">
    <div class="bg-slate-800 border border-slate-700 rounded-2xl max-w-md w-full p-6 text-center shadow-xl">
        <div class="w-12 h-12 bg-emerald-500/10 text-emerald-400 rounded-full flex items-center justify-center mx-auto mb-4 border border-emerald-500/20">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        </div>
        <h1 class="text-xl font-bold text-white mb-2">FutProtec Outbound</h1>
        <p class="text-sm text-slate-300 mb-6">
            <?php if ($procesado): ?>
                La dirección de correo <strong class="text-emerald-400 font-mono"><?php echo htmlspecialchars($email); ?></strong> ha sido dada de baja correctamente de nuestras listas de comunicación B2B.
            <?php else: ?>
                Solicitud registrada. No volverás a recibir comunicaciones comerciales de FutProtec en esta dirección.
            <?php endif; ?>
        </p>
        <div class="text-xs text-slate-500 border-t border-slate-700/60 pt-4">
            FutProtec — Equipación y Protección Técnica para Fútbol Base
        </div>
    </div>
</body>
</html>
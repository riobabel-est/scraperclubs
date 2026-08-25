<?php
/**
 * inc/login_form.php — Formulario de acceso al panel (partial).
 * Extraído de dashboard.php para separar la vista de login del orquestador.
 * PHP 8.x nativo — SiteGround compatible.
 *
 * Uso: require_once __DIR__ . '/inc/login_form.php'; showLoginForm($error);
 */
declare(strict_types=1);

function showLoginForm(string $error = ''): void {
    ?>
    <!DOCTYPE html>
    <html lang="es" class="dark">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>FutProtec — Acceso Panel</title>
        <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='6' fill='%23f59e0b'/><text x='16' y='23' font-size='22' text-anchor='middle' fill='%230a0f1a' font-family='sans-serif' font-weight='bold'>FP</text></svg>">
        <link rel="stylesheet" href="css/tailwind.min.css">
        <style>body { font-family: 'Inter', system-ui, sans-serif; }</style>
        <script src="https://unpkg.com/lucide@latest"></script>
    </head>
    <body class="bg-slate-950 min-h-screen flex items-center justify-center">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-8 w-full max-w-sm shadow-2xl">
            <div class="text-center mb-6">
                <div class="text-2xl font-bold text-amber-400">FutProtec</div>
                <p class="text-slate-400 text-xs mt-1">Panel CRM Kanban v2.0</p>
            </div>
            <?php if ($error): ?>
                <div class="bg-rose-500/10 border border-rose-500/30 rounded-lg px-3 py-2 text-rose-400 text-xs text-center mb-4"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form method="post">
                <div class="mb-4">
                    <label class="text-xs text-slate-400 uppercase tracking-wider">Contrasena</label>
                    <div class="flex gap-2 mt-1">
                        <input type="password" name="password" data-login-password-input
                            class="flex-1 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 text-center focus:outline-none focus:border-amber-500/50"
                            placeholder="........" required autofocus>
                        <button type="button" data-login-toggle aria-label="Mostrar contraseña" title="Mostrar contraseña"
                            class="shrink-0 px-4 py-2 bg-slate-800 border border-slate-700 rounded-lg text-slate-300 hover:text-amber-400 hover:border-amber-500/40 transition">
                            <i data-lucide="eye" data-eye class="w-4 h-4"></i>
                            <i data-lucide="eye-off" data-eye-off class="w-4 h-4 hidden"></i>
                        </button>
                    </div>
                </div>
                <button type="submit" class="w-full py-2.5 bg-amber-500/20 text-amber-400 border border-amber-500/30 rounded-lg text-sm font-semibold hover:bg-amber-500/30 transition">
                    Acceder al Panel
                </button>
            </form>
        </div>
        <script>
        // Toggle de contraseña del login con JavaScript nativo (sin Alpine)
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-login-toggle]');
            if (!btn) return;
            var input = btn.parentElement ? btn.parentElement.querySelector('input[data-login-password-input]') : null;
            if (!input) return;
            var eye = btn.querySelector('[data-eye]');
            var eyeOff = btn.querySelector('[data-eye-off]');
            var show = (input.type === 'password');
            input.type = show ? 'text' : 'password';
            if (eye) eye.classList.toggle('hidden', show);
            if (eyeOff) eyeOff.classList.toggle('hidden', !show);
            btn.title = show ? 'Ocultar contraseña' : 'Mostrar contraseña';
            btn.setAttribute('aria-label', show ? 'Ocultar contraseña' : 'Mostrar contraseña');
        });
        lucide.createIcons();
        </script>
    </body>
    </html>
    <?php
}

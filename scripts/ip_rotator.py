"""Rotador de IP pública mediante ADB + Modo Avión en Android.

Estrategia validada con Samsung S23:
  1. Desactivar Wi‑Fi (svc wifi disable) → fuerza tráfico por datos móviles
  2. Activar/desactivar Modo Avión → módem 4G/5G renegocia IP pública
  3. Esperar reconexión + verificar nueva IP
  4. Reactivar Wi‑Fi

Soporta ADB por USB (cable de datos) y Wi‑Fi (Depuración inalámbrica).

Uso:
  from ip_rotator import get_current_ip, rotate_ip_adb

  ip_inicial = get_current_ip()
  nueva_ip = rotate_ip_adb()
"""

import json
import platform
import subprocess
import time
from pathlib import Path

import requests

# ---------------------------------------------------------------------------
# Rutas de ADB según sistema operativo
# ---------------------------------------------------------------------------
_ADB_PATHS = [
    Path.home() / "AppData" / "Local" / "Android" / "Sdk" / "platform-tools" / "adb.exe",
    Path.home() / "Android" / "Sdk" / "platform-tools" / "adb",
    "adb",
]

# ---------------------------------------------------------------------------
# Configuración de tiempos (segundos)
# ---------------------------------------------------------------------------
WIFI_DISABLE_WAIT = 1     # esperar tras desactivar Wi‑Fi
AIRPLANE_ON_WAIT = 5      # tiempo en Modo Avión (para que suelte IP)
AIRPLANE_OFF_WAIT = 10    # esperar reconexión de datos móviles
WIFI_RE_ENABLE_WAIT = 2   # esperar tras reactivar Wi‑Fi


def _find_adb():
    """Busca el binario de ADB en las rutas candidatas."""
    for candidate in _ADB_PATHS:
        if isinstance(candidate, Path):
            if candidate.exists():
                return str(candidate)
        else:
            try:
                subprocess.run(
                    ["adb", "--version"],
                    capture_output=True,
                    timeout=5,
                    creationflags=0x08000000 if platform.system() == "Windows" else 0,
                )
                return "adb"
            except (FileNotFoundError, subprocess.TimeoutExpired):
                continue
    raise RuntimeError(
        "ADB no encontrado. Instala Android Platform Tools:\n"
        "  https://developer.android.com/studio/releases/platform-tools"
    )


def _adb_path():
    """Devuelve la ruta a adb (cacheada en memoria)."""
    if not hasattr(_adb_path, "_cached"):
        _adb_path._cached = _find_adb()
    return _adb_path._cached


def _run_adb(cmd_args, timeout=30):
    """Ejecuta un comando ADB y devuelve (returncode, stdout, stderr)."""
    adb = _adb_path()
    full_cmd = [adb] + cmd_args
    creationflags = 0x08000000 if platform.system() == "Windows" else 0
    proc = subprocess.run(
        full_cmd,
        capture_output=True,
        text=True,
        timeout=timeout,
        creationflags=creationflags,
    )
    return proc.returncode, proc.stdout.strip(), proc.stderr.strip()


def _get_device_serial():
    """Obtiene el serial del primer dispositivo conectado (USB o Wi‑Fi)."""
    rc, stdout, stderr = _run_adb(["devices"])
    if rc != 0:
        raise RuntimeError(f"ADB devices falló: {stderr}")
    for line in stdout.splitlines():
        line = line.strip()
        if line and "List of devices" not in line and "\tdevice" in line:
            return line.split("\t")[0]
    raise RuntimeError(
        "No se detectó ningún dispositivo Android. ¿Depuración activada?"
    )


def _is_usb_device(serial):
    """True si el dispositivo está conectado por USB (no tiene ':' en el serial)."""
    return ":" not in serial


# ---------------------------------------------------------------------------
# API pública
# ---------------------------------------------------------------------------

def get_current_ip(timeout=5):
    """Obtiene la IP pública actual.

    Returns:
        str con la IP pública, o None si falla.
    """
    # Intento 1: via ADB (curl en el móvil)
    try:
        serial = _get_device_serial()
        rc, stdout, _ = _run_adb(
            ["-s", serial, "shell", "curl -s --max-time 5 https://api.ipify.org?format=json"],
            timeout=timeout + 5,
        )
        if rc == 0 and stdout:
            import json
            data = json.loads(stdout)
            return data.get("ip")
    except Exception:
        pass

    # Intento 2: petición local (fallback)
    try:
        res = requests.get("https://api.ipify.org?format=json", timeout=timeout)
        res.raise_for_status()
        return res.json().get("ip")
    except Exception:
        return None


def rotate_ip_adb():
    """Desactiva Wi‑Fi, alterna Modo Avión y reactiva Wi‑Fi para forzar nueva IP.

    ADB por USB sobrevive al Modo Avión (el cable mantiene la conexión).
    ADB por Wi‑Fi ejecuta el comando en background antes de cortarse.

    Returns:
        str con la nueva IP pública.

    Raises:
        RuntimeError: si ADB falla o no se detecta dispositivo.
    """
    serial = _get_device_serial()
    is_usb = _is_usb_device(serial)
    conn_type = "USB" if is_usb else f"Wi‑Fi ({serial})"
    print(f"📱 Dispositivo: {serial} ({conn_type})")

    ip_antes = get_current_ip(timeout=5)
    print(f"   IP antes: {ip_antes}")

    # Comando de rotación (sin reactivar Wi‑Fi para verificar IP por datos móviles)
    rotate_cmd = (
        f"svc wifi disable; "
        f"sleep {WIFI_DISABLE_WAIT}; "
        f"cmd connectivity airplane-mode enable; "
        f"sleep {AIRPLANE_ON_WAIT}; "
        f"cmd connectivity airplane-mode disable; "
        f"sleep {AIRPLANE_OFF_WAIT}"
    )
    rotate_wait = WIFI_DISABLE_WAIT + AIRPLANE_ON_WAIT + AIRPLANE_OFF_WAIT

    if is_usb:
        print(f"🔄 Rotando IP (USB, ~{rotate_wait}s)...")
        print(f"   📴 Desactivando Wi‑Fi y activando Modo Avión...")
        rc, _, stderr = _run_adb(["-s", serial, "shell", rotate_cmd], timeout=rotate_wait + 15)
        if rc != 0:
            print(f"   ⚠️ Error: {stderr}")
        print(f"⏳ Verificando IP por datos móviles (Wi‑Fi off)...")
        time.sleep(3)
    else:
        print(f"🔄 Rotando IP (Wi‑Fi, ~{rotate_wait}s) — lanzando en background...")
        print(f"   📴 Desactivando Wi‑Fi y activando Modo Avión...")
        _run_adb(["-s", serial, "shell", f"nohup sh -c '{rotate_cmd}' > /dev/null 2>&1 &"], timeout=10)
        print(f"⏳ Esperando {rotate_wait + 8}s...")
        time.sleep(rotate_wait + 8)
        if ":" in serial:
            print(f"   🔄 Reconectando ADB a {serial}...")
            _run_adb(["connect", serial], timeout=10)

    # Verificar IP mientras el Wi‑Fi sigue apagado (forzando datos móviles)
    new_ip = None
    for attempt in range(1, 4):
        new_ip = get_current_ip(timeout=5)
        if new_ip and new_ip != ip_antes:
            break
        if attempt < 3:
            time.sleep(2)

    # Reactivar Wi‑Fi
    if is_usb:
        _run_adb(["-s", serial, "shell", "svc wifi enable"], timeout=10)
    print(f"   📶 Wi‑Fi reactivado.")
    time.sleep(1)
    if new_ip:
        print(f"✅ Nueva IP: {new_ip}")
        if ip_antes and new_ip != ip_antes:
            print(f"   📈 Cambio: {ip_antes} → {new_ip}")
        else:
            print(f"   ⚠️ La IP no cambió. ¿Estás en una red con IP fija?")
    else:
        print("⚠️ No se pudo verificar la nueva IP.")
    return new_ip


# ---------------------------------------------------------------------------
# Auto-test
# ---------------------------------------------------------------------------
if __name__ == "__main__":
    print(f"ADB detectado en: {_adb_path()}")
    try:
        serial = _get_device_serial()
        print(f"Dispositivo: {serial}")
    except RuntimeError as e:
        print(f"Error: {e}")
        exit(1)

    ip_inicial = get_current_ip()
    print(f"IP Inicial: {ip_inicial}")
    if ip_inicial:
        nueva = rotate_ip_adb()
        if nueva and nueva != ip_inicial:
            print(f"✅ Rotación exitosa: {ip_inicial} → {nueva}")
        elif nueva == ip_inicial:
            print("⚠️ La IP no cambió. Posibles causas:")
            print("   - El operador asigna IP fija (CGNAT estático)")
            print("   - El Wi‑Fi del móvil sigue activo (prioriza Wi‑Fi)")
        else:
            print("⚠️ No se pudo verificar la IP tras rotación.")
    else:
        print("⚠️ No se pudo obtener IP inicial.")
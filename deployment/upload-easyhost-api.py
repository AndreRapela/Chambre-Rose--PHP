from __future__ import annotations

import ftplib
import io
import os
from pathlib import Path, PurePosixPath
import re
import sys
import time
import urllib.request


FTP_HOST = "ftp.chambre-rosecom.webhosting.be"
FTP_ROOT = PurePosixPath("/chambre-rose-api")
HEALTH_URL = "https://chambre-rose.com/api/health"
FILES = (
    "bin/process-notification-outbox.php",
    "database/migrations/023-native-push-devices.mysql.sql",
    "database/migrations/023-native-push-devices.pgsql.sql",
    "src/AdminModerationRoutes.php",
    "src/AdminModerationService.php",
    "src/CompositePushNotificationSender.php",
    "src/Database.php",
    "src/NativePushNotificationService.php",
    "src/NotificationRepository.php",
    "src/NotificationRoutes.php",
    "src/ResponsiveImageProcessor.php",
    "src/App.php",
)


def ensure_parent(ftp: ftplib.FTP, remote: PurePosixPath) -> None:
    current = PurePosixPath("/")
    for part in remote.parent.parts[1:]:
        current /= part
        try:
            ftp.mkd(str(current))
        except ftplib.error_perm as error:
            if not str(error).startswith("550"):
                raise


def read_remote(ftp: ftplib.FTP, remote: PurePosixPath) -> bytes | None:
    data = bytearray()
    try:
        ftp.retrbinary(f"RETR {remote}", data.extend)
    except ftplib.error_perm as error:
        if str(error).startswith("550"):
            return None
        raise
    return bytes(data)


def store(ftp: ftplib.FTP, remote: PurePosixPath, content: bytes) -> None:
    ensure_parent(ftp, remote)
    ftp.storbinary(f"STOR {remote}", io.BytesIO(content), blocksize=128 * 1024)


def remove_if_present(ftp: ftplib.FTP, remote: PurePosixPath) -> None:
    try:
        ftp.delete(str(remote))
    except ftplib.error_perm as error:
        if not str(error).startswith("550"):
            raise


def replace_file(
    ftp: ftplib.FTP,
    remote: PurePosixPath,
    content: bytes,
    release: str,
    backup_root: PurePosixPath | None,
) -> bool:
    previous = read_remote(ftp, remote)
    if previous == content:
        return False

    if previous is not None and backup_root is not None:
        relative = remote.relative_to(FTP_ROOT)
        store(ftp, backup_root / relative, previous)

    temporary = PurePosixPath(f"{remote}.next-{release}")
    remove_if_present(ftp, temporary)
    store(ftp, temporary, content)
    try:
        ftp.rename(str(temporary), str(remote))
    except ftplib.error_perm:
        if previous is None:
            remove_if_present(ftp, temporary)
            raise
        ftp.delete(str(remote))
        try:
            ftp.rename(str(temporary), str(remote))
        except Exception:
            store(ftp, remote, previous)
            remove_if_present(ftp, temporary)
            raise
    return True


def enable_auto_migrate(environment: bytes) -> bytes:
    text = environment.decode("utf-8")
    updated, replacements = re.subn(
        r"(?m)^APP_AUTO_MIGRATE\s*=\s*false\s*$",
        "APP_AUTO_MIGRATE=true",
        text,
        count=1,
    )
    if replacements == 0 and not re.search(r"(?m)^APP_AUTO_MIGRATE\s*=\s*true\s*$", text):
        updated = text.rstrip() + "\nAPP_AUTO_MIGRATE=true\n"
    return updated.encode("utf-8")


def check_health(release: str) -> None:
    request = urllib.request.Request(
        f"{HEALTH_URL}?release={release}",
        headers={"Cache-Control": "no-cache", "User-Agent": "Chambre-Rose-Deploy/1.0"},
    )
    with urllib.request.urlopen(request, timeout=45) as response:
        body = response.read().decode("utf-8", "replace")
        if response.status != 200 or '"status":"UP"' not in body:
            raise RuntimeError(f"Health check inesperado: HTTP {response.status} {body[:200]}")


def main() -> int:
    workspace = Path(__file__).resolve().parent.parent
    user = os.getenv("EASYHOST_FTP_USERNAME", "").strip()
    password = os.getenv("EASYHOST_FTP_PASSWORD", "")
    if not user or not password:
        print("Defina EASYHOST_FTP_USERNAME e EASYHOST_FTP_PASSWORD.", file=sys.stderr)
        return 2

    release = time.strftime("%Y%m%d-%H%M%S", time.gmtime())
    backup_root = PurePosixPath(f"/chambre-rose-api-backup-{release}")
    changed = 0
    environment_path = FTP_ROOT / ".env"

    try:
        with ftplib.FTP() as ftp:
            ftp.connect(os.getenv("EASYHOST_FTP_HOST", FTP_HOST), 21, timeout=35)
            ftp.login(user, password)
            ftp.set_pasv(True)
            ftp.voidcmd("TYPE I")

            for relative_name in FILES:
                local = workspace / relative_name
                if not local.is_file():
                    raise RuntimeError(f"Arquivo local ausente: {relative_name}")
                uploaded = replace_file(
                    ftp,
                    FTP_ROOT / PurePosixPath(relative_name),
                    local.read_bytes(),
                    release,
                    backup_root,
                )
                print(f"{'Enviado' if uploaded else 'Sem alteracao'}: {relative_name}", flush=True)
                changed += int(uploaded)

            original_environment = read_remote(ftp, environment_path)
            if original_environment is None:
                raise RuntimeError("O .env de producao nao foi encontrado.")
            migration_environment = enable_auto_migrate(original_environment)
            try:
                replace_file(ftp, environment_path, migration_environment, release, None)
                check_health(release)
            finally:
                replace_file(ftp, environment_path, original_environment, release + "-restore", None)

            restored = read_remote(ftp, environment_path)
            if restored != original_environment:
                raise RuntimeError("O .env original nao foi restaurado integralmente.")

        check_health(release + "-final")
    except Exception as error:
        print(f"Falha no deploy da API: {error}", file=sys.stderr)
        return 1

    print(f"API publicada: {changed} arquivos alterados; migration 023 aplicada; .env restaurado.")
    print(f"Backup dos arquivos substituidos: {backup_root}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

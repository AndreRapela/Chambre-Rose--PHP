from __future__ import annotations

import argparse
import base64
import ftplib
import io
import os
from pathlib import Path, PurePosixPath
import re
import secrets
import sys
import time
import urllib.request
import xml.etree.ElementTree as ET


FTP_HOST = "ftp.chambre-rosecom.webhosting.be"
FTP_ROOT = PurePosixPath("/chambre-rose-api")
HEALTH_URL = "https://chambre-rose.com/api/health"
FILES = (
    "bin/process-notification-outbox.php",
    "bin/warm-profile-images.php",
    "resources/brand/brand-logo.png",
    "resources/brand/brand-logo-84.webp",
    "resources/seed-images/carousel-basic-black.jpeg",
    "resources/seed-images/carousel-pink-lace-tie.jpeg",
    "resources/seed-images/carousel-pink-ring-thong.jpeg",
    "resources/seed-images/companion-man.jpg",
    "resources/seed-images/companion-trans.jpg",
)


def release_files(workspace: Path) -> tuple[str, ...]:
    source_root = workspace / "src"
    sources = tuple(sorted(path.relative_to(workspace).as_posix() for path in source_root.rglob("*.php")
                           if path.is_file() and path.name != "App.php"))
    migrations = tuple(sorted(path.relative_to(workspace).as_posix()
                              for path in (workspace / "database/migrations").glob("*.sql") if path.is_file()))
    if not sources:
        raise RuntimeError("Nenhuma classe PHP foi encontrada em src.")
    # Existing callers stay active until every new class has been uploaded.
    files = FILES + migrations + sources + ("src/App.php", "bootstrap.php")
    for relative_name in files:
        path = workspace / relative_name
        if not path.is_file():
            raise RuntimeError(f"Arquivo local ausente: {relative_name}")
        if not path.resolve().is_relative_to(workspace.resolve()):
            raise RuntimeError(f"Arquivo fora do projeto: {relative_name}")
    return files


def load_credentials() -> tuple[str, int, str, str]:
    environment_user = os.getenv("EASYHOST_FTP_USERNAME", "").strip()
    environment_password = os.getenv("EASYHOST_FTP_PASSWORD", "")
    if environment_user and environment_password:
        return (
            os.getenv("EASYHOST_FTP_HOST", FTP_HOST).strip() or FTP_HOST,
            int(os.getenv("EASYHOST_FTP_PORT", "21")),
            environment_user,
            environment_password,
        )

    config_root = Path(os.environ["APPDATA"]) / "FileZilla"
    for filename in ("recentservers.xml", "sitemanager.xml"):
        config_path = config_root / filename
        if not config_path.is_file():
            continue
        root = ET.parse(config_path).getroot()
        for server in root.findall(".//Server"):
            host = (server.findtext("Host") or "").strip()
            if host != FTP_HOST:
                continue
            user = (server.findtext("User") or "").strip()
            password_node = server.find("Pass")
            stored_password = (server.findtext("Pass") or "").strip()
            if not user or not stored_password:
                continue
            encoding = password_node.get("encoding", "base64") if password_node is not None else "base64"
            if encoding == "crypt":
                raise RuntimeError(
                    "O perfil do FileZilla exige senha mestra. Configure EASYHOST_FTP_USERNAME e EASYHOST_FTP_PASSWORD."
                )
            if encoding not in ("base64", "plain"):
                raise RuntimeError("Formato de senha do FileZilla nao suportado.")
            password = base64.b64decode(stored_password).decode("utf-8") if encoding == "base64" else stored_password
            port = int((server.findtext("Port") or "21").strip())
            return host, port, user, password

    raise RuntimeError("O perfil FTP da Chambre Rose nao foi encontrado no FileZilla.")


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
    journal: dict[PurePosixPath, tuple[bytes | None, bytes]] | None = None,
) -> bool:
    previous = read_remote(ftp, remote)
    if previous == content:
        return False

    if journal is not None:
        journal.setdefault(remote, (previous, content))

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


def restore_release(
    ftp: ftplib.FTP,
    journal: dict[PurePosixPath, tuple[bytes | None, bytes]],
    release: str,
) -> None:
    environment = FTP_ROOT / ".env"
    paths = ([environment] if environment in journal else []) + [path for path in reversed(journal) if path != environment]
    failures = []
    for remote in paths:
        try:
            if not remote.is_relative_to(FTP_ROOT) or ".." in remote.parts:
                raise RuntimeError("Rollback target outside the API directory.")
            previous, attempted = journal[remote]
            current = read_remote(ftp, remote)
            if current != previous:
                if current is not None and current != attempted:
                    raise RuntimeError("The file was changed by another operation.")
                if previous is None:
                    remove_if_present(ftp, remote)
                else:
                    replace_file(ftp, remote, previous, release + "-rollback", None)
                if read_remote(ftp, remote) != previous:
                    raise RuntimeError("Restored file verification failed.")
            remove_if_present(ftp, PurePosixPath(f"{remote}.next-{release}"))
            if remote == environment:
                remove_if_present(ftp, PurePosixPath(f"{remote}.next-{release}-restore"))
        except Exception:
            valid_target = remote.is_relative_to(FTP_ROOT) and ".." not in remote.parts
            failures.append(str(remote.relative_to(FTP_ROOT)) if valid_target else "invalid target")
    if failures:
        raise RuntimeError("Rollback requires manual recovery for: " + ", ".join(failures))


def enable_auto_migrate(environment: bytes) -> bytes:
    text = environment.decode("utf-8")
    updated, replacements = re.subn(
        r"(?m)^APP_AUTO_MIGRATE[ \t]*=.*$",
        "APP_AUTO_MIGRATE=true",
        text,
    )
    if replacements == 0:
        updated = text.rstrip() + "\nAPP_AUTO_MIGRATE=true\n"
    return updated.encode("utf-8")


def enable_mvp_content_seed(environment: bytes) -> bytes:
    text = environment.decode("utf-8")
    updated, replacements = re.subn(
        r"(?m)^SEED_MVP_CONTENT[ \t]*=[ \t]*(?:false|true)[ \t]*$",
        "SEED_MVP_CONTENT=true",
        text,
        count=1,
    )
    if replacements == 0:
        updated = text.rstrip() + "\nSEED_MVP_CONTENT=true\n"
    return updated.encode("utf-8")


def disable_automatic_seeds(environment: bytes) -> bytes:
    text = environment.decode("utf-8")
    for key in ("SEED_MVP_CONTENT", "SEED_DEMO_USERS"):
        text, replacements = re.subn(r"(?m)^" + key + r"[ \t]*=.*$", key + "=false", text)
        if replacements == 0:
            text = text.rstrip() + "\n" + key + "=false\n"
    return text.encode("utf-8")


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
    parser = argparse.ArgumentParser(description="Publish the API with backups and a health check.")
    parser.add_argument("--skip-migrations", action="store_true", help="Preserve .env unchanged when the release has no new database migrations.")
    parser.add_argument(
        "--seed-mvp-content",
        action="store_true",
        help="Temporarily enable the idempotent marketplace seed so local demonstration profiles are created in production.",
    )
    options = parser.parse_args()
    workspace = Path(__file__).resolve().parent.parent
    try:
        files = release_files(workspace)
        host, port, user, password = load_credentials()
    except Exception as error:
        print(f"Falha ao carregar credenciais de deploy: {error}", file=sys.stderr)
        return 2

    release = time.strftime("%Y%m%d-%H%M%S", time.gmtime()) + "-" + secrets.token_hex(4)
    backup_root = PurePosixPath(f"/chambre-rose-api-backup-{release}")
    changed = 0
    environment_path = FTP_ROOT / ".env"
    journal: dict[PurePosixPath, tuple[bytes | None, bytes]] = {}

    try:
        with ftplib.FTP() as ftp:
            ftp.connect(host, port, timeout=35)
            ftp.login(user, password)
            ftp.set_pasv(True)
            ftp.voidcmd("TYPE I")

            original_environment = read_remote(ftp, environment_path)
            if original_environment is None:
                raise RuntimeError("O .env de producao nao foi encontrado.")

            for relative_name in files[:-2]:
                local = workspace / relative_name
                if not local.is_file():
                    raise RuntimeError(f"Arquivo local ausente: {relative_name}")
                uploaded = replace_file(
                    ftp,
                    FTP_ROOT / PurePosixPath(relative_name),
                    local.read_bytes(),
                    release,
                    backup_root,
                    journal,
                )
                print(f"{'Enviado' if uploaded else 'Sem alteracao'}: {relative_name}", flush=True)
                changed += int(uploaded)

            release_environment = original_environment
            if not options.skip_migrations or options.seed_mvp_content:
                release_environment = enable_auto_migrate(release_environment)
            if options.seed_mvp_content:
                release_environment = enable_mvp_content_seed(release_environment)
            else:
                release_environment = disable_automatic_seeds(release_environment)
            if read_remote(ftp, environment_path) != original_environment:
                raise RuntimeError("O ambiente foi alterado durante o envio; a configuracao externa foi preservada.")
            if release_environment != original_environment:
                journal[environment_path] = (original_environment, release_environment)
            try:
                replace_file(ftp, environment_path, release_environment, release, None)
                check_health(release + "-schema")
                for relative_name in files[-2:]:
                    uploaded = replace_file(
                        ftp, FTP_ROOT / relative_name, (workspace / relative_name).read_bytes(), release, backup_root, journal
                    )
                    print(f"{'Enviado' if uploaded else 'Sem alteracao'}: {relative_name}", flush=True)
                    changed += int(uploaded)
                check_health(release)
            finally:
                current_environment = read_remote(ftp, environment_path)
                if current_environment is not None and current_environment not in (original_environment, release_environment):
                    raise RuntimeError("O ambiente foi alterado por outra operacao e nao sera sobrescrito.")
                replace_file(ftp, environment_path, original_environment, release + "-restore", None)

            restored = read_remote(ftp, environment_path)
            if restored != original_environment:
                raise RuntimeError("O .env original nao foi restaurado integralmente.")

        check_health(release + "-final")
    except Exception as error:
        print(f"Falha no deploy da API: {error}", file=sys.stderr)
        if journal:
            try:
                with ftplib.FTP() as recovery:
                    recovery.connect(host, port, timeout=35)
                    recovery.login(user, password)
                    recovery.set_pasv(True)
                    recovery.voidcmd("TYPE I")
                    restore_release(recovery, journal, release)
                check_health(release + "-rollback")
                print("Arquivos anteriores e ambiente restaurados; nenhuma migracao foi revertida automaticamente.", flush=True)
            except Exception as recovery_error:
                print(f"Recuperacao incompleta: {recovery_error}. Backup: {backup_root}", file=sys.stderr)
        return 1

    migration_status = "migracoes nao solicitadas" if options.skip_migrations and not options.seed_mvp_content else "migracoes pendentes aplicadas"
    seed_status = "; catalogo MVP verificado" if options.seed_mvp_content else ""
    print(f"API publicada: {changed} arquivos alterados; {migration_status}{seed_status}; .env preservado.")
    print(f"Backup dos arquivos substituidos: {backup_root}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

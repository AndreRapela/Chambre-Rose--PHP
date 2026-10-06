from __future__ import annotations

import argparse
import base64
import ftplib
import io
import os
from pathlib import Path, PurePosixPath
import re
import sys
import time
import urllib.request
import xml.etree.ElementTree as ET


FTP_HOST = "ftp.chambre-rosecom.webhosting.be"
FTP_ROOT = PurePosixPath("/chambre-rose-api")
HEALTH_URL = "https://chambre-rose.com/api/health"
FILES = (
    "bin/process-notification-outbox.php",
    "database/migrations/023-native-push-devices.mysql.sql",
    "database/migrations/023-native-push-devices.pgsql.sql",
    "database/migrations/024-private-identity-verification.mysql.sql",
    "database/migrations/024-private-identity-verification.pgsql.sql",
    "database/migrations/025-private-company-verification.mysql.sql",
    "database/migrations/025-private-company-verification.pgsql.sql",
    "database/migrations/026-personal-area.mysql.sql",
    "database/migrations/026-personal-area.pgsql.sql",
    "database/migrations/027-marketplace-listing-performance.mysql.sql",
    "database/migrations/027-marketplace-listing-performance.pgsql.sql",
    "database/migrations/028-site-promotions.mysql.sql",
    "database/migrations/028-site-promotions.pgsql.sql",
    "src/AccountRecovery.php",
    "src/AddressSearchRoutes.php",
    "src/AdminModerationRoutes.php",
    "src/AdminModerationService.php",
    "src/AdminRoutes.php",
    "src/ApiRequestGuard.php",
    "src/App.php",
    "src/AppointmentRepository.php",
    "src/AuthRateLimiter.php",
    "src/AuthRoutes.php",
    "src/CalendarRoutes.php",
    "src/CompanyVerificationRepository.php",
    "src/CompanyVerificationService.php",
    "src/CompositePushNotificationSender.php",
    "src/Database.php",
    "src/IdentityVerificationRepository.php",
    "src/IdentityVerificationService.php",
    "src/Jwt.php",
    "src/ListingRoutes.php",
    # Publish backward-compatible dependencies before their new callers.
    "src/UserExclusionRepository.php",
    "src/ResponsiveImageVariantRepository.php",
    "src/ResponsiveImageService.php",
    "src/MarketplaceService.php",
    "src/Messaging.php",
    "src/MessagingRoutes.php",
    "src/NativePushNotificationService.php",
    "src/NotificationRepository.php",
    "src/NotificationRoutes.php",
    "src/PrivateIdentityFileCipher.php",
    "src/ProductImageRepository.php",
    "src/ProductRepository.php",
    "src/ProductRoutes.php",
    "src/ProductService.php",
    "src/ProfessionalProfileMapper.php",
    "src/ProfessionalProfileRepository.php",
    "src/ProfessionalProfileSearch.php",
    "src/ProfileMedia.php",
    "src/PromotionRepository.php",
    "src/PromotionRoutes.php",
    "src/PromotionService.php",
    "src/PushNotificationService.php",
    "src/Repositories.php",
    "src/ResponsiveImageProcessor.php",
    "src/Response.php",
    "src/SearchPagination.php",
    "src/Seeder.php",
    "src/Services.php",
    "src/SupportRoutes.php",
    "src/UserNotificationService.php",
    "src/Validator.php",
    "resources/brand/brand-logo.png",
    "resources/seed-images/carousel-basic-black.jpeg",
    "resources/seed-images/carousel-pink-lace-tie.jpeg",
    "resources/seed-images/carousel-pink-ring-thong.jpeg",
)


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
        r"(?m)^APP_AUTO_MIGRATE[ \t]*=[ \t]*false[ \t]*$",
        "APP_AUTO_MIGRATE=true",
        text,
        count=1,
    )
    if replacements == 0 and not re.search(r"(?m)^APP_AUTO_MIGRATE[ \t]*=[ \t]*true[ \t]*$", text):
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
        host, port, user, password = load_credentials()
    except Exception as error:
        print(f"Falha ao carregar credenciais de deploy: {error}", file=sys.stderr)
        return 2

    release = time.strftime("%Y%m%d-%H%M%S", time.gmtime())
    backup_root = PurePosixPath(f"/chambre-rose-api-backup-{release}")
    changed = 0
    environment_path = FTP_ROOT / ".env"

    try:
        with ftplib.FTP() as ftp:
            ftp.connect(host, port, timeout=35)
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
            release_environment = original_environment
            if not options.skip_migrations or options.seed_mvp_content:
                release_environment = enable_auto_migrate(release_environment)
            if options.seed_mvp_content:
                release_environment = enable_mvp_content_seed(release_environment)
            try:
                replace_file(ftp, environment_path, release_environment, release, None)
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

    migration_status = "migracoes nao solicitadas" if options.skip_migrations and not options.seed_mvp_content else "migracoes pendentes aplicadas"
    seed_status = "; catalogo MVP verificado" if options.seed_mvp_content else ""
    print(f"API publicada: {changed} arquivos alterados; {migration_status}{seed_status}; .env preservado.")
    print(f"Backup dos arquivos substituidos: {backup_root}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

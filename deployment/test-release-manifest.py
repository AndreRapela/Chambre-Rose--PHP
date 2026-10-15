import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location("api_deployment", Path(__file__).with_name("upload-easyhost-api.py"))
deployment = importlib.util.module_from_spec(spec)
spec.loader.exec_module(deployment)
workspace = Path(__file__).resolve().parent.parent


class ReleaseManifestTests(unittest.TestCase):
    def test_includes_every_php_source(self):
        expected = {path.relative_to(workspace).as_posix() for path in (workspace / "src").rglob("*.php")}
        actual = {path for path in deployment.release_files(workspace) if path.startswith("src/")}
        self.assertEqual(actual, expected)

    def test_bootstrap_is_uploaded_after_dependencies(self):
        self.assertEqual(deployment.release_files(workspace)[-1], "bootstrap.php")
        self.assertEqual(deployment.release_files(workspace)[-2], "src/App.php")

    def test_includes_all_migrations_before_the_migrator_and_app(self):
        files = deployment.release_files(workspace)
        expected = {path.relative_to(workspace).as_posix() for path in (workspace / "database/migrations").glob("*.sql")}
        self.assertTrue(expected)
        for migration in expected:
            self.assertIn(migration, files)
            self.assertLess(files.index(migration), files.index("src/DatabaseMigrator.php"))
            self.assertLess(files.index(migration), files.index("src/App.php"))

    def test_has_no_duplicate_entries(self):
        files = deployment.release_files(workspace)
        self.assertEqual(len(files), len(set(files)))

    def test_schema_only_release_does_not_run_content_seeds(self):
        original = b"APP_AUTO_MIGRATE=false\nSEED_MVP_CONTENT=true\nSEED_DEMO_USERS=true\nPRIVATE_SETTING=unchanged\n"
        staged = deployment.disable_automatic_seeds(deployment.enable_auto_migrate(original))
        self.assertIn(b"APP_AUTO_MIGRATE=true", staged)
        self.assertIn(b"SEED_MVP_CONTENT=false", staged)
        self.assertIn(b"SEED_DEMO_USERS=false", staged)
        self.assertIn(b"PRIVATE_SETTING=unchanged", staged)
        self.assertIn(b"SEED_MVP_CONTENT=true", original)

    def test_normalizes_quoted_and_duplicate_temporary_migration_flags(self):
        staged = deployment.enable_auto_migrate(b"APP_AUTO_MIGRATE='false'\r\nAPP_AUTO_MIGRATE=false\nOTHER=unchanged\n")
        self.assertEqual(staged.count(b"APP_AUTO_MIGRATE=true"), 2)
        self.assertNotIn(b"false", staged)
        self.assertIn(b"OTHER=unchanged", staged)

    def test_excludes_environment_vendor_tests_and_temporary_files(self):
        files = deployment.release_files(workspace)
        self.assertFalse(any(path.startswith((".env", "vendor/", "tests/", "tmp/", "deployment/")) for path in files))


if __name__ == "__main__":
    unittest.main()

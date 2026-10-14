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

    def test_has_no_duplicate_entries(self):
        files = deployment.release_files(workspace)
        self.assertEqual(len(files), len(set(files)))

    def test_excludes_environment_vendor_tests_and_temporary_files(self):
        files = deployment.release_files(workspace)
        self.assertFalse(any(path.startswith((".env", "vendor/", "tests/", "tmp/", "deployment/")) for path in files))


if __name__ == "__main__":
    unittest.main()

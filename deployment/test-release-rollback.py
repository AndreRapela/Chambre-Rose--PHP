from contextlib import redirect_stderr, redirect_stdout
import ftplib
import importlib.util
import io
from pathlib import Path, PurePosixPath
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('api_deployment', Path(__file__).with_name('upload-easyhost-api.py'))
deployment = importlib.util.module_from_spec(spec)
spec.loader.exec_module(deployment)


class MemoryFTP:
    def __init__(self, files, faults=None):
        self.files = files
        self.faults = faults or {}
        self.closed = False
    def __enter__(self): return self
    def __exit__(self, *_): pass
    def connect(self, *_args, **_kwargs): pass
    def login(self, *_): pass
    def set_pasv(self, *_): pass
    def voidcmd(self, *_): pass
    def mkd(self, *_): pass
    def retrbinary(self, command, receive):
        if self.closed: raise ConnectionError('Injected dropped connection.')
        path = command.removeprefix('RETR ')
        if path not in self.files: raise ftplib.error_perm('550 Missing file')
        receive(self.files[path])
    def storbinary(self, command, stream, **_kwargs):
        if self.closed: raise ConnectionError('Injected dropped connection.')
        path = command.removeprefix('STOR ')
        contents = stream.read()
        if self.faults.get('path') and self.faults['path'] in path:
            self.faults.pop('path')
            self.files[path] = contents[:max(1, len(contents) // 2)]
            self.closed = True
            raise ConnectionError('Injected partial upload.')
        self.files[path] = contents
    def delete(self, path):
        if path not in self.files: raise ftplib.error_perm('550 Missing file')
        del self.files[path]
    def rename(self, previous, destination):
        self.files[destination] = self.files.pop(previous)


class ReleaseRollbackTests(unittest.TestCase):
    def test_recovery_never_replaces_another_operations_file(self):
        path = PurePosixPath('/chambre-rose-api/src/App.php')
        remote = {str(path): b'another deployment'}
        with self.assertRaisesRegex(RuntimeError, 'manual recovery'):
            deployment.restore_release(MemoryFTP(remote), {path: (b'previous', b'our release')}, 'test-release')
        self.assertEqual(remote[str(path)], b'another deployment')

    def test_recovery_rejects_targets_outside_the_api_directory(self):
        for name in ['/www/customer-upload.webp', '/chambre-rose-api/../www/customer-upload.webp']:
            with self.subTest(path=name):
                path = PurePosixPath(name)
                remote = {str(path): b'user media'}
                with self.assertRaisesRegex(RuntimeError, 'invalid target'):
                    deployment.restore_release(MemoryFTP(remote), {path: (None, b'user media')}, 'test-release')
                self.assertEqual(remote[str(path)], b'user media')

    def run_release(self, failed_health, environment_change=None, failed_transfer=None, success=False):
        with tempfile.TemporaryDirectory() as temporary:
            workspace = Path(temporary)
            paths = ['src/ExistingService.php', 'src/NewService.php', 'src/App.php', 'bootstrap.php']
            for name in paths:
                file = workspace / name
                file.parent.mkdir(parents=True, exist_ok=True)
                file.write_bytes(('new ' + name).encode())
            originals = {'/chambre-rose-api/.env': b'APP_AUTO_MIGRATE=false\nSEED_MVP_CONTENT=true\nSEED_DEMO_USERS=true\n'}
            for name in paths:
                if name != 'src/NewService.php':
                    originals[str(deployment.FTP_ROOT / name)] = ('previous ' + name).encode()
            remote = originals.copy()
            calls = []
            connections = []
            faults = {'path': failed_transfer} if failed_transfer else {}
            external_environment = b'APP_AUTO_MIGRATE=false\nEXTERNAL_SETTING=preserved\n'
            actual_replace = deployment.replace_file

            def replace(*args, **kwargs):
                result = actual_replace(*args, **kwargs)
                if environment_change == 'before' and args[1] == deployment.FTP_ROOT / 'src/NewService.php':
                    remote['/chambre-rose-api/.env'] = external_environment
                return result

            def health(release):
                calls.append(release)
                if environment_change == 'during' and len(calls) == 1:
                    remote['/chambre-rose-api/.env'] = external_environment
                if len(calls) - 1 == failed_health:
                    raise RuntimeError('Injected health-check failure.')

            def connect():
                connection = MemoryFTP(remote, faults)
                connections.append(connection)
                return connection

            with patch.object(deployment, '__file__', str(workspace / 'deployment/upload.py')), \
                    patch.object(deployment, 'release_files', return_value=tuple(paths)), \
                    patch.object(deployment, 'load_credentials', return_value=('test.invalid', 21, 'test', 'unused')), \
                    patch.object(deployment.ftplib, 'FTP', side_effect=connect), \
                    patch.object(deployment.time, 'strftime', return_value='test-release'), \
                    patch.object(deployment, 'check_health', side_effect=health), \
                    patch.object(deployment, 'replace_file', side_effect=replace), \
                    patch.object(deployment.sys, 'argv', ['deploy']), \
                    redirect_stdout(io.StringIO()), redirect_stderr(io.StringIO()):
                status = deployment.main()
            self.assertEqual(status, 0 if success else 1)
            self.assertEqual(len(connections), 1 if success else 2, 'Recovery must use a fresh FTP connection.')
            if success:
                self.assertEqual(remote['/chambre-rose-api/.env'], originals['/chambre-rose-api/.env'])
                for name in paths:
                    self.assertEqual(remote[str(deployment.FTP_ROOT / name)], ('new ' + name).encode())
                self.assertFalse(any('.next-' in path for path in remote))
                return
            if environment_change:
                originals['/chambre-rose-api/.env'] = external_environment
            for path, contents in originals.items():
                self.assertEqual(remote[path], contents, 'Failed releases must restore ' + path)
            self.assertNotIn('/chambre-rose-api/src/NewService.php', remote,
                             'Failed releases must remove only newly introduced release files.')
            self.assertFalse(any('.next-' in path for path in remote), 'Release staging files must be removed.')

    def test_schema_check_failure_restores_dependencies(self):
        self.run_release(0)

    def test_successful_release_keeps_new_files_and_restores_the_environment(self):
        self.run_release(None, success=True)

    def test_application_check_failure_restores_app_and_dependencies(self):
        self.run_release(1)

    def test_final_check_failure_restores_app_and_dependencies(self):
        self.run_release(2)

    def test_does_not_overwrite_environment_changed_during_dependency_upload(self):
        self.run_release(0, 'before')

    def test_does_not_overwrite_environment_changed_during_health_check(self):
        self.run_release(0, 'during')

    def test_connection_loss_during_dependency_upload_is_recoverable(self):
        self.run_release(None, failed_transfer='NewService.php.next-')

    def test_connection_loss_during_app_activation_is_recoverable(self):
        self.run_release(None, failed_transfer='App.php.next-')

    def test_connection_loss_during_environment_restoration_is_recoverable(self):
        self.run_release(None, failed_transfer='-restore')


if __name__ == '__main__':
    unittest.main()

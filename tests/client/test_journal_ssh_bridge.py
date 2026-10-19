import importlib.util
import json
from pathlib import Path
import subprocess
from types import SimpleNamespace
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('journal_ssh_bridge', Path(__file__).resolve().parents[2] / 'bin/journal-ssh-bridge.py')
bridge = importlib.util.module_from_spec(spec)
spec.loader.exec_module(bridge)


class JournalBridgeTest(unittest.TestCase):
    def setUp(self):
        self.args = SimpleNamespace(host='user@private-host', identity='/test/key', container='movary', owner=1, write=False)
        self.request = {'jsonrpc': '2.0', 'id': 1, 'method': 'tools/list'}

    def test_unavailable_app_does_not_poison_the_next_request(self):
        ok = {'jsonrpc': '2.0', 'id': 1, 'result': {'tools': []}}
        results = [subprocess.CompletedProcess([], 255, '', 'private details'),
                   subprocess.CompletedProcess([], 0, json.dumps(ok), '')]
        with patch.object(bridge.subprocess, 'run', side_effect=results) as run:
            failed = bridge.relay(self.request, self.args)
            self.assertEqual(failed['error']['code'], -32603)
            self.assertNotIn('private details', json.dumps(failed))
            self.assertEqual(run.call_count, 1)
            self.assertEqual(bridge.relay(self.request, self.args), ok)

    def test_notifications_do_not_create_a_remote_session(self):
        with patch.object(bridge.subprocess, 'run') as run:
            self.assertIsNone(bridge.relay({'jsonrpc': '2.0', 'method': 'notifications/initialized'}, self.args))
            run.assert_not_called()

    def test_write_mode_is_explicit_and_request_is_only_in_stdin(self):
        self.args.write = True
        result = subprocess.CompletedProcess([], 0, '{"jsonrpc":"2.0","id":1,"result":{}}', '')
        with patch.object(bridge.subprocess, 'run', return_value=result) as run:
            bridge.relay(self.request, self.args)
            command = run.call_args.args[0]
            self.assertIn('--write', command[-1])
            self.assertNotIn(json.dumps(self.request), ' '.join(command))


if __name__ == '__main__':
    unittest.main()

#!/usr/bin/env python3
"""Keep an MCP STDIO connection alive across private Movary container restarts."""
import argparse
import json
from pathlib import Path
import re
import shlex
import subprocess
import sys


def relay(request, args):
    if 'id' not in request:
        return None
    remote = ['docker', 'exec', '-i', args.container, 'php', 'bin/journal-mcp.php',
              '--user-id=' + str(args.owner)]
    if args.write:
        remote.append('--write')
    command = ['ssh', '-i', str(Path(args.identity).expanduser()), '-o', 'IdentitiesOnly=yes',
               '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', '-T', '--', args.host,
               shlex.join(remote)]
    try:
        result = subprocess.run(command, input=json.dumps(request) + '\n', text=True,
                                capture_output=True, timeout=240)
        if result.returncode == 0:
            response = json.loads(result.stdout)
            if isinstance(response, dict) and response.get('id') == request['id']:
                return response
    except (OSError, subprocess.TimeoutExpired, ValueError):
        pass
    # Keep the local transport open; never retry a potentially completed write.
    return {'jsonrpc': '2.0', 'id': request['id'], 'error': {'code': -32603,
            'message': 'The private journal is temporarily unavailable. Retry after the app is ready; read current context before repeating a write. No request was automatically retried.'}}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--host', required=True)
    parser.add_argument('--identity', required=True)
    parser.add_argument('--container', default='movary')
    parser.add_argument('--owner', type=int, required=True)
    parser.add_argument('--write', action='store_true')
    args = parser.parse_args()
    if args.owner < 1 or args.host.startswith('-') or not re.fullmatch(r'[A-Za-z0-9_.@:-]+', args.host):
        parser.error('Use an explicit SSH host and positive owner ID.')
    if not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9_.-]*', args.container):
        parser.error('Invalid container name.')
    while True:
        line = sys.stdin.readline(1000001)
        if not line:
            return
        if len(line) > 1000000 or not line.endswith('\n'):
            raise SystemExit('Oversized or incomplete MCP message.')
        try:
            request = json.loads(line)
            if not isinstance(request, dict) or request.get('jsonrpc') != '2.0':
                raise ValueError('Invalid JSON-RPC request.')
            response = relay(request, args)
        except ValueError:
            response = {'jsonrpc': '2.0', 'id': None,
                        'error': {'code': -32700, 'message': 'Invalid JSON-RPC request.'}}
        if response is not None:
            print(json.dumps(response), flush=True)


if __name__ == '__main__':
    main()

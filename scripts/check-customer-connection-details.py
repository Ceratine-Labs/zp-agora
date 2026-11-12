#!/usr/bin/env python3
"""Reject the known customer host and login in tracked documentation."""

import subprocess
import sys
from pathlib import Path
from tempfile import TemporaryDirectory


# Construct the identifiers so this guard does not itself repeat them in full.
IDENTIFIERS = ('.'.join(('105', '247', '172', '179')).encode(),
               ('Rev' + 'teck').encode().lower())


def contains_identifier(data: bytes) -> bool:
    return any(identifier in data.lower() for identifier in IDENTIFIERS)


def self_test() -> None:
    """Exercise the real command in an isolated index, without database access."""
    with TemporaryDirectory(prefix='agora-connection-guard-') as directory:
        root = Path(directory)
        script = root / 'scripts' / Path(__file__).name
        script.parent.mkdir()
        script.write_bytes(Path(__file__).read_bytes())
        fixture = root / 'docs' / 'reference' / 'fixture with spaces.html'
        fixture.parent.mkdir(parents=True)
        fixture.write_bytes(b'customer-db-host customer-db-login 192.0.2.1')
        subprocess.run(['git', 'init', '--quiet', str(root)], check=True)
        subprocess.run(['git', 'add', 'docs'], cwd=root, check=True)

        def run_guard() -> subprocess.CompletedProcess:
            return subprocess.run([sys.executable, str(script)], capture_output=True)

        result = run_guard()
        assert result.returncode == 0 and not result.stderr, 'Placeholders must pass.'

        for identifier in IDENTIFIERS:
            for value in (identifier, identifier.upper()):
                fixture.write_bytes(b'connection=' + value + b';')
                result = run_guard()
                assert result.returncode == 1, 'A known identifier must fail.'
                assert result.stdout == b'', 'Failure must not report success.'
                assert result.stderr == (
                    b'Customer connection identifier found in:\n'
                    b'docs/reference/fixture with spaces.html\n'
                ), 'Failures must name the path without exposing the identifier.'

        # A removed tracked document is no longer content being published.
        fixture.unlink()
        result = run_guard()
        assert result.returncode == 0 and not result.stderr, 'Deleted files must be skipped.'

        # The policy applies to tracked docs, not local scratch or source files.
        fixture.write_bytes(b'customer-db-host customer-db-login')
        (root / 'docs' / 'untracked.md').write_bytes(IDENTIFIERS[0])
        (root / 'source.txt').write_bytes(IDENTIFIERS[1])
        subprocess.run(['git', 'add', 'source.txt'], cwd=root, check=True)
        result = run_guard()
        assert result.returncode == 0 and not result.stderr, 'Only tracked docs are in scope.'


def main() -> int:
    if sys.argv[1:] == ['--self-test']:
        self_test()
        print('Customer connection identifier guard self-test passed.')
        return 0
    if sys.argv[1:]:
        print('Usage: python3 scripts/check-customer-connection-details.py [--self-test]', file=sys.stderr)
        return 2

    root = Path(__file__).resolve().parent.parent
    paths = subprocess.check_output(['git', 'ls-files', '-z', '--', 'docs'], cwd=root).split(b'\0')
    violations = [str(Path(path.decode())) for path in paths if path and (root / path.decode()).is_file()
                  and contains_identifier((root / path.decode()).read_bytes())]
    if violations:
        print('Customer connection identifier found in:', *violations, sep='\n', file=sys.stderr)
        return 1
    print('Tracked documentation contains no known customer connection identifiers.')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())

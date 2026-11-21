"""Exercise the actual staging guard with disconnected WP/cache collaborators."""
from pathlib import Path
import hashlib
import json
import os
import subprocess
import sys
import tempfile

guard = Path(__file__).with_name('staging-target-check.sh')
home = 'https://env-holisticpeoplecom-hpdevplus.kinsta.cloud'
with tempfile.TemporaryDirectory(prefix='gmc-staging-target-') as temp:
    base = Path(temp).resolve()
    root = base / 'public'
    plugins = root / 'wp-content/plugins'
    plugin = plugins / 'hp-gmc-manager'
    (plugin / 'includes/Services').mkdir(parents=True)
    (root / 'wp-load.php').write_text('<?php')
    (root / 'wp-settings.php').write_text('<?php')
    header = plugin / 'hp-gmc-manager.php'
    header.write_text('<?php\n/**\n * Version: 3.4.22\n */\n')
    policy = plugin / 'includes/Services/MerchantReturnPolicySchemaService.php'
    policy.write_text('<?php // selected policy fixture\n')
    bin_dir = base / 'bin'
    bin_dir.mkdir()
    fake_wp = bin_dir / 'wp'
    fake_wp.write_text(f'#!{sys.executable}\n' + '''
import json, os, pathlib, sys
with open(os.environ['GMC_TEST_LOG'], 'a') as out:
    out.write(json.dumps(sys.argv[1:]) + '\\n')
if 'eval' in sys.argv:
    print(os.environ['GMC_TEST_FACTS'])
elif sys.argv[-2:] == ['cache', 'flush']:
    sys.exit(int(os.environ.get('GMC_TEST_CACHE_EXIT', '0')))
else:
    sys.exit(71)
''')
    fake_wp.chmod(0o755)
    # The host is Linux. A Python-backed sha256sum collaborator also allows the
    # same shell contract to run on a development Mac lacking GNU coreutils.
    hash_bin = bin_dir / 'sha256sum'
    hash_bin.write_text(f'#!{sys.executable}\n' + '''
import hashlib, pathlib, sys
for line in sys.stdin:
    digest, path = line.rstrip('\\n').split('  ', 1)
    if hashlib.sha256(pathlib.Path(path).read_bytes()).hexdigest() != digest:
        sys.exit(1)
''')
    hash_bin.chmod(0o755)
    realpath_bin = bin_dir / 'realpath'
    realpath_bin.write_text(f'#!{sys.executable}\n' + '''
import pathlib, sys
print(pathlib.Path(sys.argv[-1]).resolve(strict=True))
''')
    realpath_bin.chmod(0o755)
    facts = dict(home=home, site=home, environment='staging', php=80500,
                 public='0', mode='auto', override='', policyMigration='3.4.9')
    log = base / 'calls.jsonl'
    header_sha = hashlib.sha256(header.read_bytes()).hexdigest()
    policy_sha = hashlib.sha256(policy.read_bytes()).hexdigest()
    results = []

    def run(name, *, changes=None, phase='preflight', expected_ok=False,
            candidate_root=root, candidate_base=plugins, candidate_home=home,
            candidate_sha=policy_sha, cache_exit=0, expected_calls=None):
        log.write_text('')
        env = dict(os.environ, PATH=str(bin_dir) + os.pathsep + os.environ['PATH'],
                   GMC_TEST_LOG=str(log), GMC_TEST_FACTS=json.dumps(facts | (changes or {})),
                   GMC_TEST_CACHE_EXIT=str(cache_exit))
        result = subprocess.run(['bash', str(guard), phase, str(candidate_base),
                                 str(candidate_root), candidate_home, '3.4.22',
                                 header_sha, candidate_sha], env=env, capture_output=True, text=True)
        calls = [json.loads(row) for row in log.read_text().splitlines()]
        assert (result.returncode == 0) == expected_ok, (name, result.stderr, result.stdout)
        assert all(call[:3] == [f'--path={root}', '--skip-plugins', '--skip-themes'] for call in calls), name
        if expected_calls is not None:
            assert len(calls) == expected_calls, (name, calls)
        flushes = sum(call[-2:] == ['cache', 'flush'] for call in calls)
        assert flushes == (1 if phase == 'installed' and name in ['installed', 'cache-failure'] else 0), name
        results.append(name)

    run('staging', expected_ok=True, expected_calls=1)
    run('missing-root', candidate_root=base / 'missing', expected_calls=0)
    run('wrong-plugins-root', candidate_base=base, expected_calls=0)
    run('foreign-home-argument', candidate_home='https://holisticpeople.com', expected_calls=0)
    for name, delta in [
        ('production-home', {'home': 'https://holisticpeople.com'}),
        ('production-site', {'site': 'https://holisticpeople.com'}),
        ('production-environment', {'environment': 'production'}),
        ('unknown-environment', {'environment': 'unknown'}),
        ('indexed-staging', {'public': '1'}),
        ('php-floor', {'php': 80400}),
        ('live-mode', {'mode': 'live'}),
        ('passthrough-mode', {'mode': 'passthrough'}),
        ('copied-production-override', {'override': 'production'}),
        ('pending-content-migration', {'policyMigration': ''}),
    ]:
        run(name, changes=delta, expected_calls=1)
    run('explicit-dry-run', changes={'mode': 'dry_run'}, expected_ok=True, expected_calls=1)
    run('installed', phase='installed', expected_ok=True, expected_calls=2)
    run('changed-installed-policy', phase='installed', candidate_sha='0' * 64, expected_calls=1)
    run('cache-failure', phase='installed', cache_exit=17, expected_calls=2)
    print(json.dumps({'result': 'PASS', 'cases': results,
                      'scope': 'Actual shell guard, disconnected collaborators; no SSH/site/provider operations'}))

"""Run the existing isolated PHP suites with provider credentials suppressed."""
from pathlib import Path
from concurrent.futures import ThreadPoolExecutor, as_completed
import json, os, re, subprocess, time, sys

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / 'inventory' / 'hostinger-regressions'
OUT.mkdir(parents=True, exist_ok=True)
runtime = json.loads((ROOT / 'inventory/hostinger-php83-runtime.json').read_text())
PHP = runtime['executable']
environment = dict(os.environ)
environment.update({
    'CONDO_APP_ENV': 'test', 'CONDO_AUTO_MIGRATE': '1',
    'CONDO_APP_URL': 'http://localhost/CondoSystem3',
    'CONDO_DB_NAME': 'condo_audit_guard_test_unavailable',
    'CONDO_SMTP_USER': '', 'CONDO_SMTP_PASS': '',
    'CONDO_MAIL_FROM': 'audit@example.invalid',
    'CONDO_PAYMONGO_SECRET_KEY': '', 'CONDO_PAYMONGO_WEBHOOK_SECRET': '',
    'CONDO_SEMAPHORE_API_KEY': '', 'CONDO_TWILIO_SID': '',
    'CONDO_TWILIO_AUTH_TOKEN': '', 'CONDO_TWILIO_FROM': '',
})
tests = sorted(p for p in (ROOT / 'scripts').glob('test_*.php') if p.name not in {'test_vehicle_upload.php', 'test_ui_redesign.php'})
results = []
if len(sys.argv) > 1:
    selected = set(sys.argv[1:])
    tests = [p for p in tests if p.name in selected]
    if {p.name for p in tests} != selected:
        raise SystemExit('Unknown regression suite; pass existing test_*.php filenames.')
    previous = OUT / 'results.json'
    if previous.exists():
        results = [r for r in json.loads(previous.read_text())['results'] if r['suite'] not in selected]

def run(test):
    start = time.monotonic()
    try:
        proc = subprocess.run([PHP, str(test)], cwd=ROOT, env=environment, capture_output=True, timeout=480)
        output = proc.stdout.decode(errors='replace')
        errors = proc.stderr.decode(errors='replace')
        row = {'suite': test.name, 'exit_code': proc.returncode, 'seconds': round(time.monotonic()-start, 2), 'summary': output.strip().splitlines()[-1:]}
        (OUT / (test.stem + '.log')).write_text(output + '\nSTDERR:\n' + errors, encoding='utf-8')
        row['warnings'] = len(re.findall(r'(?:PHP (?:Warning|Deprecated|Fatal)|Fatal error|Deprecated:)', output + errors))
    except subprocess.TimeoutExpired:
        row = {'suite': test.name, 'exit_code': -1, 'seconds': round(time.monotonic()-start, 2), 'summary': ['Timed out; check for any remaining disposable fixture processes.']}
    return row

with ThreadPoolExecutor(max_workers=3) as pool:
    for future in as_completed([pool.submit(run, test) for test in tests]):
        row = future.result()
        results.append(row)
        (OUT / 'results.json').write_text(json.dumps({'php': runtime['version'], 'results': results}, indent=2), encoding='utf-8')
        print(json.dumps(row), flush=True)
print(json.dumps({'passed': sum(r['exit_code']==0 for r in results), 'failed': sum(r['exit_code']!=0 for r in results)}), flush=True)

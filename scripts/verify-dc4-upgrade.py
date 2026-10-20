"""Additive upgrade evidence on an online SQLite copy of the isolated DC3 test DB."""
import hashlib, json, os, pathlib, sqlite3, subprocess

root = pathlib.Path(__file__).resolve().parent.parent
source = root / 'backend/database/e2e.sqlite'
target = root / 'output/dc4-upgrade.sqlite'
if target.exists():
    raise SystemExit('Existing upgrade evidence is preserved; choose a new explicitly reviewed target before another run.')
if not source.exists() or target.parent.resolve() != (root / 'output').resolve():
    raise SystemExit('Expected isolated DC3 source and workspace output target.')
with sqlite3.connect(source.as_uri() + '?mode=ro', uri=True) as before, sqlite3.connect(target) as copy:
    before.backup(copy)
tables = ['journal_entries', 'journal_lines', 'expenses', 'invoices', 'invoice_items', 'financial_account_transactions', 'documents']
columns = {}
def snapshot():
    result = {}
    with sqlite3.connect(target) as db:
        for table in tables:
            columns.setdefault(table, [row[1] for row in db.execute(f'PRAGMA table_info("{table}")')])
            selected = ','.join('"' + col + '"' for col in columns[table])
            rows = db.execute(f'SELECT {selected} FROM "{table}" ORDER BY id').fetchall()
            digest = hashlib.sha256(json.dumps(rows, default=str, ensure_ascii=False, separators=(',', ':')).encode()).hexdigest()
            result[table] = {'count': len(rows), 'sha256': digest}
    return result
initial = snapshot()
if not initial['journal_entries']['count']:
    raise SystemExit('The source has no journals; it cannot prove a financial-history upgrade.')
env = dict(os.environ, APP_ENV='testing', APP_KEY='base64:32Hr/pYy5GkWR0Lcm3HnL0fHw2cbCtmHtravymO8GA0=', DB_CONNECTION='sqlite', DB_DATABASE=str(target), DB_URL='', CACHE_STORE='array', SESSION_DRIVER='array', QUEUE_CONNECTION='sync', MAIL_MAILER='array')
php = r'C:\Users\anisi\Lab2\desktop\resources\php\php.exe'
for args in [['artisan', 'migrate', '--force'], ['artisan', 'workforce:setup']]:
    subprocess.run([php, *args], cwd=root / 'backend', env=env, check=True, capture_output=True, text=True)
final = snapshot()
if initial != final:
    raise SystemExit('Existing financial records changed during additive upgrade.')
result = {'source': str(source), 'target': str(target), 'method': 'sqlite online backup → migrate --force → workforce:setup; no migrate:fresh', 'before': initial, 'after': final, 'financial_history_unchanged': True}
(root / 'output/dc4-upgrade-evidence.json').write_text(json.dumps(result, indent=2) + '\n', encoding='utf-8')
print(json.dumps(result, indent=2))

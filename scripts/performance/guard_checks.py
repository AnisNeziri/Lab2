"""Negative checks for disposable PR2 tooling; writes only a fresh unmarked scratch DB under output."""
import hashlib
import json
import os
from pathlib import Path
import secrets
import sqlite3
import subprocess

root = Path(__file__).resolve().parents[2]
owned = root / "output"
if owned.resolve() != owned or owned.is_symlink():
    raise RuntimeError("Redirected output refused.")
php = os.environ.get("AIMS_PERFORMANCE_PHP", "php")
env = dict(os.environ)
if env.get("APP_ENV") == "production":
    raise RuntimeError("Refusal probes are forbidden in production.")
env.pop("APP_ENV", None)
seed = 1700000000 + secrets.randbelow(100000000)
directory = owned / "performance" / str(seed)
if directory.exists():
    raise RuntimeError("Fresh scratch directory required.")
for parent in [owned, owned / "performance"]:
    if parent.is_symlink() or parent.resolve() != parent:
        raise RuntimeError("Redirected scratch parent refused.")
    parent.mkdir(exist_ok=True)
directory.mkdir()
database = directory / "aims-pr2.sqlite"
with sqlite3.connect(database) as connection:
    connection.execute("CREATE TABLE sentinel (value TEXT)")
    connection.execute("INSERT INTO sentinel VALUES ('preserve unmarked database')")
before = hashlib.sha256(database.read_bytes()).hexdigest()
generate = str(root / "scripts/performance/generate.php")
cases = [
    ("missing_confirmation", [generate], {}),
    ("production_refused", [generate, "--yes"], {"APP_ENV": "production"}),
    ("invalid_engine", [generate, "--engine=remote", "--yes"], {}),
    ("invalid_seed", [generate, "--seed=0", "--yes"], {}),
    ("invalid_port", [generate, "--yes"], {"AIMS_PERF_MYSQL_PORT": "3312;DROP"}),
    ("unmarked_reset_preserved", [generate, "--engine=sqlite", f"--seed={seed}", "--reset", "--yes"], {}),
    ("outside_database_refused", [str(root / "scripts/performance/profile.php"), str(root / "backend/database/e2e.sqlite"), str(owned / "never-written.json")], {}),
]
checks = {}
for name, args, overrides in cases:
    result = subprocess.run([php, *args], cwd=root, env={**env, **overrides}, capture_output=True, timeout=30)
    checks[name] = result.returncode != 0
checks["unmarked_bytes_unchanged"] = hashlib.sha256(database.read_bytes()).hexdigest() == before
report = {"status": "PASS" if all(checks.values()) else "FAIL", "checks": checks, "qualification": "Negative tooling checks; no production or caller-selected database is opened for writing."}
(owned / "pr2-generator-guards.json").write_text(json.dumps(report, indent=2), encoding="utf-8")
print(json.dumps(report))
raise SystemExit(0 if report["status"] == "PASS" else 1)


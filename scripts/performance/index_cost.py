"""Measure aggregate PR2 SQLite index allocation on a disposable compacted copy."""
import json, os, re, secrets, sqlite3, sys
from pathlib import Path
root=Path(__file__).resolve().parents[2]
if os.environ.get('APP_ENV')=='production':raise RuntimeError('Allocation probes are forbidden in production.')
owned=root/'output'
source=Path(sys.argv[1]).absolute()
if source.resolve()!=source or not source.is_relative_to(owned) or owned.resolve()!=owned:
    raise RuntimeError('Physical owned fixture required.')
original=sqlite3.connect('file:'+source.as_posix()+'?mode=ro',uri=True)
if original.execute('SELECT marker FROM _aims_performance_manifest').fetchone()[0]!='AIMS_PR2_PERFORMANCE_ONLY':
    raise RuntimeError('Performance marker required.')
directory=owned/('index-control-'+secrets.token_hex(8))
directory.mkdir()
copy=directory/'control.sqlite'
scratch=sqlite3.connect(copy)
try:
    original.backup(scratch)
    original.close()
    scratch.execute('VACUUM')
    before=copy.stat().st_size
    names=[r[0] for r in scratch.execute("SELECT name FROM sqlite_master WHERE type='index' AND name LIKE 'pr2_%' ORDER BY name")]
    if len(names)!=7 or not all(re.fullmatch(r'pr2_[a-z_]+',name) for name in names):
        raise RuntimeError('Expected seven PR2 indexes.')
    for name in names:scratch.execute('DROP INDEX "'+name+'"')
    scratch.commit()
    scratch.execute('VACUUM')
    after=copy.stat().st_size
    report={'qualification':'Compacted owned copy with the same rows and schema, removing only seven PR2 indexes; source opened readonly. Measures aggregate allocation, not individual index sizes.','with_pr2_bytes':before,'without_pr2_bytes':after,'pr2_index_bytes':before-after,'indexes':names,'status':'PASS' if before>after else 'FAIL'}
finally:
    scratch.close()
    if copy.resolve()==copy and copy!=source and copy.is_relative_to(owned):copy.unlink()
    directory.rmdir()
(owned/'pr2-index-allocation.json').write_text(json.dumps(report,indent=2),encoding='utf-8')
print(json.dumps(report))
raise SystemExit(0 if report['status']=='PASS' else 1)


"""Regenerate pinned ATK PHP bindings with an independently supplied protoc 36.2."""
import argparse
import hashlib
import json
import shutil
import subprocess
import tempfile
from pathlib import Path

root = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser()
parser.add_argument("--protoc", required=True)
args = parser.parse_args()
version = subprocess.check_output([args.protoc, "--version"], text=True).strip()
if version != "libprotoc 36.2":
    raise SystemExit("Pinned generator requires libprotoc 36.2.")
source = root / "backend/resources/sef"
provenance = json.loads((source / "PROVENANCE.json").read_text(encoding="utf-8"))
schema = (source / "models.proto").read_bytes()
if hashlib.sha256(schema).hexdigest() != provenance["source_schema_sha256"]:
    raise SystemExit("The pinned ATK schema checksum differs.")
before = {str(p): hashlib.sha256(p.read_bytes()).hexdigest()
          for p in (root / "backend/app/Integrations/Sef").rglob("*.php")
          if p.parent.name in ["Generated", "Metadata"]}
with tempfile.TemporaryDirectory(prefix="aims-sef-protoc-") as owned:
    folder = Path(owned)
    options = r'option php_namespace = "App\\Integrations\\Sef\\Generated";' + "\n"
    options += r'option php_metadata_namespace = "App\\Integrations\\Sef\\Metadata";'
    text = schema.decode().replace("package atk;", "package atk;\n" + options)
    (folder / "models.proto").write_text(text, encoding="utf-8", newline="\n")
    out = folder / "out"
    out.mkdir()
    subprocess.run([args.protoc, "--proto_path=" + str(folder),
                    "--php_out=" + str(out), str(folder / "models.proto")], check=True)
    generated = out / "App/Integrations/Sef"
    for path in generated.rglob("*.php"):
        target = root / "backend/app/Integrations/Sef" / path.relative_to(generated)
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(path, target)
after = {str(p): hashlib.sha256(p.read_bytes()).hexdigest()
         for p in (root / "backend/app/Integrations/Sef").rglob("*.php")
         if p.parent.name in ["Generated", "Metadata"]}
print("Generated the pinned ATK schema; wire fields were not modified.")
if before:
    print("Bindings identical to previous generation:", before == after)

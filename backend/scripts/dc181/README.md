# DC18.1 isolated inference evidence

State/report: `C:/Users/anisi/.codex/recovery/dc18-1-20261104/`.
Resume its checkpoint; do not regenerate DC16 or rerun completed suites.
The active `.env` is never changed by this harness. The existing DC16 ownership,
storage, encryption-key and MariaDB 11.4.13/3320 guards remain mandatory.

`start-model.ps1` starts the verified portable runtime with process-only settings,
private model storage and cloud disabled. It does not install a Windows service.
The selected model is `qwen3-vl:4b-instruct`, Q4_K_M; the original default thinking
tag and the smaller 2B Instruct were also measured, with limitations recorded.
The pinned SigLIP 2 encoder lives in a private Python environment; no global pip
packages, PATH, GPU drivers or unrelated dependencies were changed.

Use the DC16 README's PHPRC/PHP_INI_SCAN_DIR settings. The optional model-enabled
API uses `php -S 127.0.0.1:8028 scripts/dc181/serve.php` from backend. The ordinary
isolated ERP worker remains on 8026. Frontend preview 4188 proxies to 8028 using a
process-only VITE_API_PROXY_TARGET. Model tools remain disabled by default.
A single PHP development worker is occupied while its assistant request waits;
production worker commissioning is not established by this preview.

Evidence entry points (do not repeat successful runs without a reason):

- `benchmark.py`: real 4B text/vision inference and runtime metadata.
- `ask-evaluate.php`: unchanged Ask40, explicit protected-business digest checks.
  DC17_ASK_CASES permits focused reruns; previous rows are retained.
- `ask-oracles*.php`: independent SQL product rankings/economic totals/historical AR.
- `conversation-compare.php`: deterministic versus real-model conversation probe.
- `embedding-evaluate.py`: real learned embeddings on generated regression only.
- `media-verify.php`: real screenshot negatives and authorized internal support receipt.
- `runtime-verify.py`: separate ERP responsiveness and actual CPU fallback.
- `export-feedback.php`: administrator-only, minimized review export. It never trains,
  modifies aliases, changes evaluations or executes business writes.

Feedback curation: review pending examples for privacy and correctness; record a
reviewer and approval outside the immutable original Ask40; create a separate
minimized evaluation file with independent expected results. Test against held-out
examples before any manually reviewed alias/model release. No automatic training.

The company has no catalogue photos. Generated stripes and synthetic UI fixtures
are not field-accuracy evidence. The current image scan is capped at 50 candidates
and a time budget; incomplete coverage is disclosed. File-cache embeddings are
company/content/model-revision scoped. Real photos, index scaling, calibrated
no-match thresholds and broader conversational reference quality remain outstanding.
Do not forward screenshots through support: only reviewed/redacted text is supported.
Do not begin DC19.

"""Read-only source/asset inspection. Reports paths and counts, never secret values."""
from pathlib import Path
import json, re, subprocess, urllib.parse

ROOT = Path(__file__).resolve().parents[1]
SKIP = {'.git', '.venv', 'vendor', 'private_uploads', 'inventory'}
FILES = [p for p in ROOT.rglob('*') if p.is_file() and not any(x in SKIP for x in p.relative_to(ROOT).parts)]
SOURCES = [p for p in FILES if p.suffix.lower() in {'.php', '.html', '.css', '.js'} and 'scripts' not in p.relative_to(ROOT).parts]
result = {'files_reviewed': len(SOURCES), 'literal_asset_references': [], 'missing_assets': [], 'case_mismatches': [], 'dynamic_references': 0, 'local_url_references': [], 'large_images': [], 'secret_candidates': []}

def exact_path(path):
    try:
        relative = path.resolve().relative_to(ROOT)
    except ValueError:
        return None, False
    current, exact = ROOT, True
    for part in relative.parts:
        if not current.is_dir():
            return None, False
        names = {p.name: p for p in current.iterdir()}
        if part in names:
            current = names[part]
        else:
            found = next((p for name, p in names.items() if name.lower() == part.lower()), None)
            if found is None:
                return None, False
            current, exact = found, False
    return current, exact

for source in SOURCES:
    text = source.read_text(encoding='utf-8', errors='replace')
    refs = list(re.finditer(r'''(?:src|href|poster)\s*=\s*["']([^"']+)["']''', text))
    refs += list(re.finditer(r'''url\(\s*["']?([^)'"\s]+)''', text))
    for match in refs:
        ref = match.group(1)
        if any(x in ref for x in ['<?', '$', '${', '{{']):
            result['dynamic_references'] += 1
            continue
        if re.match(r'^(?:[a-z]+:|//|#)', ref, re.I):
            continue
        target = urllib.parse.unquote(urllib.parse.urlsplit(ref.replace('&amp;', '&')).path)
        if Path(target).suffix.lower() not in {'.svg', '.png', '.jpg', '.jpeg', '.webp', '.gif', '.ico', '.css', '.js', '.woff', '.woff2', '.ttf', '.wav'}:
            continue
        item = {'source': source.relative_to(ROOT).as_posix(), 'line': text.count('\n', 0, match.start()) + 1, 'reference': ref}
        candidates = [ROOT / target.lstrip('/')] if target.startswith('/') else [source.parent / target]
        # Shared PHP fragments have caller-relative URLs; record those as requiring browser confirmation.
        if source.parent.name == 'includes':
            candidates += [ROOT / target, ROOT / 'resident' / target, ROOT / 'superadmin' / target]
        matches = [exact_path(candidate) for candidate in candidates]
        resolved = next(((p, exact) for p, exact in matches if p and p.is_file()), None)
        if resolved:
            item['resolved'] = resolved[0].relative_to(ROOT).as_posix()
            item['exact_case'] = resolved[1]
            if not resolved[1]:
                result['case_mismatches'].append(item)
        else:
            result['missing_assets'].append(item)
        result['literal_asset_references'].append(item)
    for match in re.finditer(r'''https?://(?:localhost|127\.0\.0\.1|[^\s"'<>]*ngrok[^\s"'<>]*)[^\s"'<>]*''', text, re.I):
        result['local_url_references'].append({'source': source.relative_to(ROOT).as_posix(), 'line': text.count('\n', 0, match.start()) + 1})

for file in FILES:
    if file.suffix.lower() in {'.jpg', '.jpeg', '.png', '.webp'} and file.stat().st_size > 2 * 1024 * 1024:
        result['large_images'].append({'path': file.relative_to(ROOT).as_posix(), 'bytes': file.stat().st_size})

tracked = subprocess.check_output(['git', 'ls-files', '-z'], cwd=ROOT).decode().split('\0')
secret_pattern = re.compile(r'(?:sk_(?:live|test)_[A-Za-z0-9]{20,}|whsk_[A-Za-z0-9]{20,}|AKIA[A-Z0-9]{16})')
for name in tracked:
    file = ROOT / name
    if not name or not file.is_file() or any(x in SKIP for x in Path(name).parts) or file.stat().st_size > 2 * 1024 * 1024:
        continue
    text = file.read_text(encoding='utf-8', errors='replace')
    for match in secret_pattern.finditer(text):
        result['secret_candidates'].append({'file': name, 'line': text.count('\n', 0, match.start()) + 1})

output = ROOT / 'inventory' / 'hostinger-static-audit.json'
output.parent.mkdir(exist_ok=True)
output.write_text(json.dumps(result, indent=2), encoding='utf-8')
print(json.dumps({k: len(v) if isinstance(v, list) else v for k, v in result.items()}))
for kind in ['missing_assets', 'case_mismatches', 'large_images', 'secret_candidates']:
    print(kind, json.dumps(result[kind]))

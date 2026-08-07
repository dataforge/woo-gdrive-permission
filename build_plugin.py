#!/usr/bin/env python3
"""Build a WordPress plugin release zip with forward-slash entry paths.

Usage: build_plugin.py <slug> <src-dir> [out-zip]

Always writes forward slashes (zip-spec compliant) regardless of host OS, so
Linux servers extract a real directory tree under a single top-level folder
named exactly <slug>. NEVER build the release zip with PowerShell's
Compress-Archive - it writes backslash paths that break extraction on Linux.
"""

import os
import sys
import zipfile

if len(sys.argv) < 3:
    print("usage: build_plugin.py <slug> <src-dir> [out-zip]")
    sys.exit(1)

slug = sys.argv[1]
src = os.path.abspath(sys.argv[2])
out = os.path.abspath(sys.argv[3] if len(sys.argv) > 3 else f"{slug}.zip")

EXCLUDE_DIRS = {'.git', '.github', '.claude', 'node_modules', '_zips', '_reference', 'tests'}
EXCLUDE_FILES = {'.gitignore', '.gitattributes', 'CLAUDE.md', 'build_plugin.py'}

with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as zf:
    for root, dirs, files in os.walk(src):
        dirs[:] = [d for d in sorted(dirs) if d not in EXCLUDE_DIRS]
        for f in sorted(files):
            full = os.path.join(root, f)
            if f in EXCLUDE_FILES or f.endswith('.zip') or os.path.abspath(full) == out:
                continue
            rel = os.path.relpath(full, src).replace(os.sep, '/')
            arc = f"{slug}/{rel}"
            zf.write(full, arc)
            print(arc)

print(f"SIZE {os.path.getsize(out)}")

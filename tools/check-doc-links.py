#!/usr/bin/env python3
"""Check repository links in current documentation without network access."""

import argparse
import html
import re
import sys
from pathlib import Path
from urllib.parse import unquote


def prose_lines(text):
    fence = None
    for number, line in enumerate(text.splitlines(), 1):
        marker = re.match(r"^\s*(`{3,}|~{3,})", line)
        if marker:
            token = marker[1]
            if fence is None:
                fence = token
            elif token[0] == fence[0] and len(token) >= len(fence):
                fence = None
            continue
        if fence is None:
            yield number, line


def anchors(text):
    found = set()
    counts = {}
    for _, line in prose_lines(text):
        found.update(re.findall(r'<a\s+(?:id|name)=["\']([^"\']+)', line))
        heading = re.match(r"^#{1,6}\s+(.+?)(?:\s+#+)?$", line)
        if heading is None:
            continue
        label = re.sub(r"\[([^]]+)\]\([^)]*\)", r"\1", heading[1])
        label = html.unescape(re.sub(r"<[^>]+>", "", label))
        slug = re.sub(r"\s", "-", re.sub(r"[^\w\s-]", "", label.lower()))
        count = counts.get(slug, 0)
        counts[slug] = count + 1
        found.add(slug if count == 0 else slug + "-" + str(count))
    return found


def link_targets(text):
    for number, line in prose_lines(text):
        line = re.sub(r"(`+).*?\1", "", line)
        pattern = r"!?\[[^\]\n]*\]\((<[^>]+>|[^\s)]+(?:\([^)]*\)[^\s)]*)*)(?:\s+[^)]*)?\)"
        for match in re.finditer(pattern, line):
            yield number, match[1].strip("<>")
        definition = re.match(r"^\s*\[[^]]+\]:\s*(<[^>]+>|\S+)", line)
        if definition:
            yield number, definition[1].strip("<>")


def current_documents(root):
    names = ["README.md", "CONTRIBUTING.md", "SECURITY.md", "GOVERNANCE.md",
             "CODE_OF_CONDUCT.md", "DESIGN.md", "AGENTS.md", "cli/README.md"]
    documents = [root / name for name in names if (root / name).is_file()]
    for directory in ["docs", "spec", "skills/wprism"]:
        documents.extend(path for path in (root / directory).rglob("*.md")
                         if "history" not in path.relative_to(root).parts)
    documents.extend((root / "docs/history").glob("README.md"))
    documents.extend((root / "adapter-packages").glob("*/README.md"))
    documents.extend((root / "integration-scenarios").glob("*/README.md"))
    documents.extend((root / "tools").glob("*.md"))
    documents.extend((root / "tools/codemod").glob("README.md"))
    documents.extend((root / "sandbox/tests/lib").glob("README.md"))
    return sorted(set(documents))


def check(root, documents):
    failures = []
    count = 0
    cache = {}
    for document in documents:
        for line, target in link_targets(document.read_text()):
            if re.match(r"^[a-zA-Z][a-zA-Z0-9+.-]*:", target) or target.startswith("//"):
                continue
            count += 1
            path, _, fragment = target.partition("#")
            destination = ((root / path.lstrip("/")) if path.startswith("/")
                           else document.parent / unquote(path)).resolve() if path else document
            label = str(document.relative_to(root)) + ":" + str(line)
            try:
                destination.relative_to(root)
            except ValueError:
                failures.append(label + ": link escapes repository: " + target)
                continue
            if not destination.exists():
                failures.append(label + ": missing path: " + target)
                continue
            if not fragment:
                continue
            if destination.is_dir():
                destination = destination / "README.md"
            if destination.suffix.lower() != ".md":
                if re.fullmatch(r"L\d+(?:-L\d+)?", fragment):
                    continue
                failures.append(label + ": cannot verify non-Markdown anchor: " + target)
                continue
            if not destination.is_file():
                failures.append(label + ": directory anchor needs README.md: " + target)
                continue
            if destination not in cache:
                cache[destination] = anchors(destination.read_text())
            if unquote(fragment) not in cache[destination]:
                failures.append(label + ": missing anchor: " + target)
    return count, failures


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", type=Path, default=Path(__file__).resolve().parent.parent)
    args = parser.parse_args()
    root = args.root.resolve()
    documents = current_documents(root)
    if not documents:
        print("FAIL: no current documentation found", file=sys.stderr)
        return 1
    count, failures = check(root, documents)
    for failure in failures:
        print("FAIL: " + failure, file=sys.stderr)
    print("Documentation links: " + str(count) + " local links across "
          + str(len(documents)) + " current documents; " + str(len(failures)) + " failures")
    return 1 if failures else 0


if __name__ == "__main__":
    raise SystemExit(main())

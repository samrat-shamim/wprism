# Machine compatibility

Read this reference before interpreting WPrism JSON. The package-local
[compatibility manifest](formats.json) is the closed admission set for the
workflow documents this skill consumes. Select the document entry through the
manifest's `commands` map, require every named top-level field, and accept only
the exact `format` value. A missing, additional-version, renamed, or unlisted
format is a compatibility mismatch even when the verb and `--format=json`
still exist.

`complete-plan` is the one deliberate legacy exception: its authoritative
outer envelope has no top-level `format`. Require every action bucket named in
that manifest and the embedded `category_summary.format`; never replace it with
the non-authoritative `plan-view` projection. Refusals use their separate
`command-refusal` entry and never count as the requested success document.

The manifest names only top-level fields the skill relies on for routing,
identity, status, or safety. Their presence does not replace the installed
command's own closed-schema, canonical-byte, digest, signature, or cross-field
validation. Preserve signed or digest-bound documents byte-for-byte and pass
them back to WPrism for authoritative validation.

For redirected examples, use the manifest's `artifacts` map. If a command
returns valid JSON under a different listed document than the one mapped to
that operation, stop rather than translating it.

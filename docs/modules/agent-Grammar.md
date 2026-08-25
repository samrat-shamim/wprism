# agent: Grammar

**Purpose.** Declarative grammars and resolvers that turn manifest declarations and WordPress content syntax (blocks, shortcodes, tokens, options, taxonomies, user meta) into typed policy structures.

**Directory** `agent/src/Grammar/` &middot; **layer** `policy` &middot; **files** 26 &middot; **status** populated

**Entry points** (classes other modules already reference; a new cross-module reference to anything else is a design change): `Tokens`, `Blocks`, `OptionGrammar`, `SubKeyGrammar`, `UserMetaGrammar`, `AttributeGrammar`, `AttrIdCodecGrammar`, `ColumnCodecGrammar`, `OptionNameReferenceResolver`, `OptionReferenceGrammar`, `PostTypeGrammar`, `ContentAttributeRuleResolver`, `DynamicOptionResolver`, `ExactOptionResolver`, `FieldGrammar`, `OptionNamespaceResolver`, `PostTypeRelationResolver`, `ShortcodeAlternateRegistrar`, `Shortcodes`, `TableDeclarationResolver`, `TaxonomyDescriptionReferenceResolver`, `TaxonomyGrammar`, `TaxonomyKeyspaceResolver`, `TaxonomyObjectTypeOptionResolver`, `TaxonomyPatternResolver`, `WidgetTypeResolver`.

**May depend on:** `Grammar`, `Kernel`.

**Ratified exceptions** (same-layer or upward edges that exist today; ratchet — may shrink, never grow):

- `Policy` (intra-layer, 7 edges)
  `Blocks.php -> Policy.php`; `ExactOptionResolver.php -> PolicyRuleResolver.php`; `OptionReferenceGrammar.php -> Policy.php`; `ShortcodeAlternateRegistrar.php -> Policy.php`; `Shortcodes.php -> Policy.php`; `SubKeyGrammar.php -> Policy.php`; …
- `Repository` (upward, 1 edge)
  `Tokens.php -> Ledger.php`

**Must not depend on.** Repository, Code and every engine module. Grammar answers shape questions; it must not read state.

**Known debts.**

- `Tokens.php -> Ledger.php` is the one upward edge; Tokens is really two things (a content token codec and a ledger lookup).
- Grammar<->Policy is a true 2-cycle (30 edges down, 7 back).

**Sub-namespace plan.** Target `Duo\Grammar\`. Not in this round: the move keeps `namespace Duo;` flat so that manifest interpreters/providers can keep naming `\Duo\Policy`, `\Duo\ProviderSdk`, `\Duo\Providers` and `\Duo\Canon` by FQCN — those hook files are `hash_file`'d into every adapter's identity row (`ArtifactPolicyIdentity::manifest_rows()`), so renaming the namespace moves each `adapter_digest` and forces a recompile plus a reviewed re-pin on every deployed site. Kernel migrates first (no inbound FQCN from manifests); Policy, Adapter and Canon migrate last, behind a hook-file change.

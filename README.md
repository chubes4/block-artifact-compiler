# Block Artifact Compiler

Block Artifact Compiler is a legacy compatibility entrypoint for the canonical Blocks Engine PHP transformer. New consumers should depend on `automattic/blocks-engine-php-transformer` directly instead of adopting BAC.

BAC does not own artifact compilation, block conversion, source normalization, component discovery, or materialization semantics. Those behaviors live in Blocks Engine. BAC only loads the runtime and exposes the old package/plugin function names for consumers that still depend on them.

```text
Studio Web
  -> Static Site Importer
      -> Block Artifact Compiler legacy entrypoint
          -> Blocks Engine PHP Transformer
```

## Public API

This section documents the stable compatibility shim for existing BAC callers only. It is not a new integration guide; new compiler integrations should use `Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler` from `automattic/blocks-engine-php-transformer` directly.

Load BAC through Composer autoloading, the WordPress plugin entrypoint, or `library.php`:

```php
require_once __DIR__ . '/library.php';
```

Then call the compatibility functions:

```php
$result = bac_compile_website_artifact(
	array(
		'files' => array(
			'index.html' => '<main><h1>Hello</h1></main>',
		),
	)
);

$fragment = bac_compile_fragment( $html, 'main:index.html', 'html', $options );
```

Both functions return the canonical Blocks Engine result envelope directly. BAC does not document, project, or validate a separate result schema; callers should treat the envelope as owned by `Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler`.

## Boundaries

Block Artifact Compiler does not orchestrate agents, import WordPress sites, deploy outputs, define artifact schemas, or maintain a second compiler contract.

- Studio Web owns product orchestration, review, preview, and push flows.
- Static Site Importer owns WordPress import and materialization.
- Blocks Engine PHP transformer owns artifact compilation and result envelopes.

## Test

```bash
composer test
```

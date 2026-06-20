<?php
/**
 * Contract smoke tests.
 *
 * @package BlockArtifactCompiler
 */

require_once dirname( __DIR__ ) . '/library.php';

$assert = static function ( bool $condition, string $message, string $detail = '' ): void {
	if ( $condition ) {
		return;
	}

	$failure = 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL;
	file_put_contents( 'php://output', $failure );
	file_put_contents( 'php://stderr', $failure );
	throw new RuntimeException( trim( $failure ) );
};

$canonical_compiler_class = 'Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\ArtifactCompiler';
$assert( class_exists( $canonical_compiler_class ), 'canonical Blocks Engine artifact compiler is available' );

$result = bac_compile_website_artifact(
	array(
		'files' => array(
			'index.html'          => '<main><img src="assets/icon.svg" alt=""><h1>Hello compiler</h1><p>Canonical delegation.</p></main>',
			'assets/icon.svg'     => '<svg viewBox="0 0 24 24"><script>alert(1)</script><path d="M0 0h1"/></svg>',
			'components/Card.jsx' => 'export default function Card() { return <section />; }',
		),
	)
);

$assert( 'block-artifact-compiler/result/v1' === ( $result['schema'] ?? '' ), 'BAC result schema is preserved' );
$assert( 'block-artifact-compiler/website-artifact/v1' === ( $result['input']['schema'] ?? '' ), 'BAC input schema is preserved' );
$assert( 'success_with_warnings' === ( $result['status'] ?? '' ), 'canonical warnings surface through BAC status', (string) ( $result['status'] ?? '' ) );
$assert( 'index.html' === ( $result['input']['entry_path'] ?? '' ), 'entry path is projected from canonical artifact report' );
$assert( 4 === ( $result['input']['source_report']['html']['element_count'] ?? null ), 'canonical source report is exposed under BAC input metadata' );
$assert( str_contains( (string) ( $result['wordpress_artifacts']['block_markup'] ?? '' ), 'Hello compiler' ), 'canonical serialized block output is exposed as BAC block markup' );
$assert( isset( $result['wordpress_artifacts']['block_tree'] ) && is_array( $result['wordpress_artifacts']['block_tree'] ), 'BAC block tree summary is retained' );
$assert( ! empty( array_filter( $result['diagnostics'] ?? array(), static fn ( array $diagnostic ): bool => 'unsafe_svg_asset' === ( $diagnostic['code'] ?? '' ) ) ), 'canonical diagnostics surface through BAC API' );
$assert( ! empty( array_filter( $result['wordpress_artifacts']['components'] ?? array(), static fn ( array $component ): bool => 'jsx-component-file' === ( $component['signal'] ?? '' ) && 'Card' === ( $component['name'] ?? '' ) ) ), 'canonical component candidates surface through BAC API' );

$delegated_svg_asset = null;
foreach ( $result['wordpress_artifacts']['files'] ?? array() as $asset_file ) {
	if ( 'assets/icon.svg' === ( $asset_file['path'] ?? '' ) ) {
		$delegated_svg_asset = $asset_file;
		break;
	}
}
$assert( is_array( $delegated_svg_asset ), 'canonical asset appears in BAC file manifest' );
$assert( ! array_key_exists( 'content', $delegated_svg_asset ), 'canonical unsafe SVG asset omits inline content' );

$markdown = bac_compile_website_artifact(
	array(
		'files' => array(
			'content/about.md' => "---\ntitle: About Us\nslug: about\npost_type: page\n---\n# About\n\nPlain Markdown content.",
		),
	)
);
$assert( in_array( ( $markdown['status'] ?? '' ), array( 'success', 'success_with_warnings' ), true ), 'markdown artifacts compile through canonical compiler', (string) ( $markdown['status'] ?? '' ) );
$assert( 1 === ( $markdown['input']['files_by_kind']['markdown'] ?? 0 ), 'canonical markdown file classification is projected' );
$assert( 1 === count( $markdown['wordpress_artifacts']['documents'] ?? array() ), 'canonical markdown document projection is exposed' );
$assert( 'about' === ( $markdown['wordpress_artifacts']['documents'][0]['slug'] ?? '' ), 'canonical markdown frontmatter slug is preserved' );
$assert( str_contains( (string) ( $markdown['wordpress_artifacts']['block_markup'] ?? '' ), 'Plain Markdown content.' ), 'canonical markdown block markup is exposed at BAC top level' );

$fragment = bac_compile_fragment( '<!-- wp:paragraph --><p>Native blocks</p><!-- /wp:paragraph -->', 'content/native.blocks', 'blocks' );
$assert( 'success' === ( $fragment['status'] ?? '' ), 'serialized block fragments compile through canonical compiler' );
$assert( 'content/native.blocks.html' === ( $fragment['input']['entry_path'] ?? '' ), 'fragment source is normalized for canonical artifact entry' );
$assert( str_contains( (string) ( $fragment['wordpress_artifacts']['block_markup'] ?? '' ), 'Native blocks' ), 'serialized block fragments preserve block markup' );

$summary = bac_summarize_result( $result );
$assert( 'block-artifact-compiler/result/v1' === ( $summary['schema'] ?? '' ), 'summary preserves BAC schema' );
$assert( 1 === ( $summary['diagnostic_count'] ?? null ), 'summary counts canonical diagnostics' );
$assert( 1 === ( $summary['component_count'] ?? null ), 'summary counts canonical component candidates' );

$canonical_blocks_entry = ( new $canonical_compiler_class() )->compile(
	array(
		'entrypoint' => 'content/native.blocks.html',
		'files'      => array(
			array(
				'path'    => 'content/native.blocks.html',
				'kind'    => 'blocks',
				'content' => '<!-- wp:paragraph --><p>Native blocks</p><!-- /wp:paragraph -->',
			),
		),
	)
)->toArray();
$assert( 'success' === ( $canonical_blocks_entry['status'] ?? '' ), 'canonical compiler accepts serialized block entry artifacts' );
$assert( str_contains( (string) ( $canonical_blocks_entry['serialized_blocks'] ?? '' ), 'Native blocks' ), 'canonical compiler preserves serialized block entry markup' );

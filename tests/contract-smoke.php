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

$compat_compiler = new Block_Artifact_Compiler();

$result = bac_compile_website_artifact(
	array(
		'files' => array(
			'index.html'          => '<main><img src="assets/icon.svg" alt=""><h1>Hello compiler</h1><p>Canonical delegation.</p></main>',
			'assets/icon.svg'     => '<svg viewBox="0 0 24 24"><script>alert(1)</script><path d="M0 0h1"/></svg>',
			'components/Card.jsx' => 'export default function Card() { return <section />; }',
		),
	)
);

$assert( 'blocks-engine/php-transformer/result/v1' === ( $result['schema'] ?? '' ), 'Blocks Engine result schema is returned directly' );
$assert( 'success_with_warnings' === ( $result['status'] ?? '' ), 'canonical warnings surface through BAC status', (string) ( $result['status'] ?? '' ) );
$assert( 'index.html' === ( $result['source_reports']['artifact']['entry_path'] ?? '' ), 'entry path is returned from canonical artifact report' );
$assert( 'index.html' === ( $result['source_reports']['conversion_report']['source_summary']['entry_path'] ?? '' ), 'SSI-facing conversion report summary is preserved' );
$assert( 4 === ( $result['source_reports']['artifact']['html']['element_count'] ?? null ), 'canonical source report is exposed directly' );
$assert( str_contains( (string) ( $result['serialized_blocks'] ?? '' ), 'Hello compiler' ), 'canonical serialized block output is returned directly' );
$assert( ! empty( array_filter( $result['diagnostics'] ?? array(), static fn ( array $diagnostic ): bool => 'unsafe_svg_asset' === ( $diagnostic['code'] ?? '' ) ) ), 'canonical diagnostics surface through BAC API' );
$assert( ! empty( array_filter( $result['components'] ?? array(), static fn ( array $component ): bool => 'jsx-component-file' === ( $component['signal'] ?? '' ) && 'Card' === ( $component['name'] ?? '' ) ) ), 'canonical component candidates surface through BAC API' );

$canonical_result = ( new $canonical_compiler_class() )->compile(
	array(
		'files' => array(
			'index.html'          => '<main><img src="assets/icon.svg" alt=""><h1>Hello compiler</h1><p>Canonical delegation.</p></main>',
			'assets/icon.svg'     => '<svg viewBox="0 0 24 24"><script>alert(1)</script><path d="M0 0h1"/></svg>',
			'components/Card.jsx' => 'export default function Card() { return <section />; }',
		),
	)
)->toArray();
$result_without_timing = $result;
$canonical_without_timing = $canonical_result;
unset( $result_without_timing['metrics']['transform_duration_ms'], $canonical_without_timing['metrics']['transform_duration_ms'] );
unset( $result_without_timing['source_reports']['conversion_report']['metrics']['transform_duration_ms'], $canonical_without_timing['source_reports']['conversion_report']['metrics']['transform_duration_ms'] );
$assert( $canonical_without_timing === $result_without_timing, 'BAC artifact API returns the canonical ArtifactCompiler result' );

$class_result = $compat_compiler->compile(
	array(
		'files' => array(
			'index.html'          => '<main><img src="assets/icon.svg" alt=""><h1>Hello compiler</h1><p>Canonical delegation.</p></main>',
			'assets/icon.svg'     => '<svg viewBox="0 0 24 24"><script>alert(1)</script><path d="M0 0h1"/></svg>',
			'components/Card.jsx' => 'export default function Card() { return <section />; }',
		),
	)
);
$class_result_without_timing = $class_result;
unset( $class_result_without_timing['metrics']['transform_duration_ms'] );
unset( $class_result_without_timing['source_reports']['conversion_report']['metrics']['transform_duration_ms'] );
$assert( $canonical_without_timing === $class_result_without_timing, 'BAC class API returns the canonical ArtifactCompiler result' );

$delegated_svg_asset = null;
foreach ( $result['assets'] ?? array() as $asset_file ) {
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
$assert( 1 === ( $markdown['source_reports']['artifact']['files_by_kind']['markdown'] ?? 0 ), 'canonical markdown file classification is returned directly' );
$assert( 1 === count( $markdown['documents'] ?? array() ), 'canonical markdown document projection is exposed' );
$assert( 'about' === ( $markdown['documents'][0]['slug'] ?? '' ), 'canonical markdown frontmatter slug is preserved' );
$assert( str_contains( (string) ( $markdown['serialized_blocks'] ?? '' ), 'Plain Markdown content.' ), 'canonical markdown block markup is exposed at BAC top level' );

$fragment = bac_compile_fragment( '<main><h2>Fragment</h2><p>Copy</p></main>', 'content/fragment.html', 'html' );
$assert( 'success' === ( $fragment['status'] ?? '' ), 'serialized block fragments compile through canonical compiler' );
$assert( 'content/fragment.html' === ( $fragment['provenance'][0]['source'] ?? '' ), 'fragment source is passed to canonical compiler' );
$assert( 'artifact-fragment' === ( $fragment['provenance'][0]['scope'] ?? '' ), 'fragment source scope is set by canonical compiler' );
$assert( str_contains( (string) ( $fragment['serialized_blocks'] ?? '' ), '<!-- wp:group' ), 'fragment compilation uses Blocks Engine compileFragment' );
$assert( str_contains( (string) ( $fragment['serialized_blocks'] ?? '' ), '<h2>Fragment</h2>' ), 'fragment compilation preserves canonical serialized content' );

$summary = bac_summarize_result( $result );
$assert( 'blocks-engine/php-transformer/result/v1' === ( $summary['schema'] ?? '' ), 'summary preserves canonical schema' );
$assert( ( $result['metrics']['diagnostic_count'] ?? null ) === ( $summary['diagnostic_count'] ?? null ), 'summary projects canonical diagnostic metric' );
$assert( ( $result['source_reports']['artifact']['file_count'] ?? null ) === ( $summary['file_count'] ?? null ), 'summary projects canonical artifact file count' );
$assert( 1 === ( $summary['component_count'] ?? null ), 'summary counts canonical component candidates' );

$canonical_fragment = ( new $canonical_compiler_class() )->compileFragment( '<main><h2>Fragment</h2><p>Copy</p></main>', 'content/fragment.html', 'html' )->toArray();
$assert( $canonical_fragment === $fragment, 'BAC fragment API returns the canonical compileFragment result' );

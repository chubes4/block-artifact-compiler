<?php
/**
 * Delegation contract tests.
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
$assert( function_exists( 'bac_compile_website_artifact' ), 'artifact compatibility function is available' );
$assert( function_exists( 'bac_compile_fragment' ), 'fragment compatibility function is available' );
$assert( ! function_exists( 'bac_summarize_result' ), 'BAC no longer exposes a report summary projection' );

$artifact = array(
	'files' => array(
		'index.html' => '<main><h1>Hello compiler</h1><p>Canonical delegation.</p></main>',
	),
);

$result           = bac_compile_website_artifact( $artifact );
$canonical_result = ( new $canonical_compiler_class() )->compile( $artifact )->toArray();

$assert( 'blocks-engine/php-transformer/result/v1' === ( $result['schema'] ?? '' ), 'BAC returns the canonical Blocks Engine result schema' );
$assert( array_key_exists( 'serialized_blocks', $result ), 'canonical result envelope is returned without BAC projection' );

$result_without_timing    = $result;
$canonical_without_timing = $canonical_result;
unset( $result_without_timing['metrics']['transform_duration_ms'], $canonical_without_timing['metrics']['transform_duration_ms'] );
unset( $result_without_timing['source_reports']['conversion_report']['metrics']['transform_duration_ms'], $canonical_without_timing['source_reports']['conversion_report']['metrics']['transform_duration_ms'] );
$assert( $canonical_without_timing === $result_without_timing, 'artifact API delegates to ArtifactCompiler::compile()' );

$fragment           = bac_compile_fragment( '<main><h2>Fragment</h2><p>Copy</p></main>', 'content/fragment.html', 'html' );
$canonical_fragment = ( new $canonical_compiler_class() )->compileFragment( '<main><h2>Fragment</h2><p>Copy</p></main>', 'content/fragment.html', 'html' )->toArray();

$assert( 'blocks-engine/php-transformer/result/v1' === ( $fragment['schema'] ?? '' ), 'fragment API returns the canonical Blocks Engine result schema' );
$assert( $canonical_fragment === $fragment, 'fragment API delegates to ArtifactCompiler::compileFragment()' );

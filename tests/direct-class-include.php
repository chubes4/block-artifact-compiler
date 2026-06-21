<?php
/**
 * Direct class include compatibility test.
 *
 * @package BlockArtifactCompiler
 */

require_once dirname( __DIR__ ) . '/includes/class-block-artifact-compiler.php';

$assert = static function ( bool $condition, string $message, string $detail = '' ): void {
	if ( $condition ) {
		return;
	}

	$failure = 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL;
	file_put_contents( 'php://output', $failure );
	file_put_contents( 'php://stderr', $failure );
	throw new RuntimeException( trim( $failure ) );
};

$assert( class_exists( 'Block_Artifact_Compiler' ), 'direct class include defines the compatibility class' );
$assert( function_exists( 'bac_compile_website_artifact' ), 'direct class include loads delegated public functions' );
$assert( class_exists( 'Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\ArtifactCompiler' ), 'direct class include loads Blocks Engine dependencies' );

$compiler = new Block_Artifact_Compiler();
$result   = $compiler->compile(
	array(
		'files' => array(
			'index.html' => '<main><h1>Direct include</h1></main>',
		),
	)
);

$assert( 'blocks-engine/php-transformer/result/v1' === ( $result['schema'] ?? '' ), 'direct class include compiles through the canonical Blocks Engine result' );
$assert( str_contains( (string) ( $result['serialized_blocks'] ?? '' ), 'Direct include' ), 'direct class include returns canonical serialized block output' );

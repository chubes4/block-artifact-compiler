<?php
/**
 * Public API functions.
 *
 * @package BlockArtifactCompiler
 */

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

if ( ! function_exists( 'bac_compile_website_artifact' ) ) {
	/**
	 * Compile a website artifact bundle into a WordPress-native artifact bundle.
	 *
	 * @param array<string,mixed> $artifact Website artifact input.
	 * @param array<string,mixed> $options  Compiler options.
	 * @return array<string,mixed> Canonical Blocks Engine compiler result envelope.
	 */
	function bac_compile_website_artifact( array $artifact, array $options = array() ): array {
		unset( $options );

		return ( new ArtifactCompiler() )->compile( $artifact )->toArray();
	}
}

if ( ! function_exists( 'bac_compile_fragment' ) ) {
	/**
	 * Compile a single source fragment into a WordPress-native artifact envelope.
	 *
	 * @param string               $content Source content.
	 * @param string               $source  Source label or path.
	 * @param string               $format  Source format.
	 * @param array<string, mixed> $options Compiler options.
	 * @return array<string,mixed> Canonical Blocks Engine compiler result envelope.
	 */
	function bac_compile_fragment( string $content, string $source = 'fragment', string $format = 'html', array $options = array() ): array {
		return ( new ArtifactCompiler() )->compileFragment( $content, $source, $format, $options )->toArray();
	}
}

if ( ! function_exists( 'bac_summarize_result' ) ) {
	/**
	 * Summarize a compiler result for reports and logs.
	 *
	 * @param array<string,mixed> $compiled Compiler result envelope.
	 * @return array<string,mixed> Compact summary.
	 */
	function bac_summarize_result( array $compiled ): array {
		$metrics  = is_array( $compiled['metrics'] ?? null ) ? $compiled['metrics'] : array();
		$artifact = is_array( $compiled['source_reports']['artifact'] ?? null ) ? $compiled['source_reports']['artifact'] : array();

		return array(
			'schema'           => (string) ( $compiled['schema'] ?? '' ),
			'status'           => (string) ( $compiled['status'] ?? '' ),
			'block_count'      => (int) ( $metrics['block_count'] ?? 0 ),
			'component_count'  => is_array( $compiled['components'] ?? null ) ? count( $compiled['components'] ) : 0,
			'file_count'       => (int) ( $artifact['file_count'] ?? 0 ),
			'diagnostic_count' => (int) ( $metrics['diagnostic_count'] ?? 0 ),
		);
	}
}

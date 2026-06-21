<?php
/**
 * Blocks Engine artifact compiler compatibility wrapper.
 *
 * @package BlockArtifactCompiler
 */

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

/**
 * Thin compatibility wrapper around the Blocks Engine artifact compiler.
 */
class Block_Artifact_Compiler {

	/**
	 * Compile a website artifact bundle.
	 *
	 * @param array<string,mixed> $artifact Website artifact input.
	 * @param array<string,mixed> $options  Compiler options.
	 * @return array<string,mixed> Canonical Blocks Engine compiler result envelope.
	 */
	public function compile( array $artifact, array $options = array() ): array {
		unset( $options );

		return ( new ArtifactCompiler() )->compile( $artifact )->toArray();
	}

	/**
	 * Compile a single content fragment.
	 *
	 * @param string              $content Source content.
	 * @param string              $source  Source label or path.
	 * @param string              $format  Source format.
	 * @param array<string,mixed> $options Compiler options.
	 * @return array<string,mixed> Canonical Blocks Engine compiler result envelope.
	 */
	public function compile_fragment( string $content, string $source = 'fragment', string $format = 'html', array $options = array() ): array {
		return ( new ArtifactCompiler() )->compileFragment( $content, $source, $format, $options )->toArray();
	}

	/**
	 * Summarize a compiler result for upstream import reports.
	 *
	 * @param array<string,mixed> $compiled Compiler result envelope.
	 * @return array<string,mixed> Compact summary.
	 */
	public function summarize_result( array $compiled ): array {
		$diagnostics = is_array( $compiled['diagnostics'] ?? null ) ? $compiled['diagnostics'] : array();

		return array(
			'schema'           => (string) ( $compiled['schema'] ?? '' ),
			'status'           => (string) ( $compiled['status'] ?? '' ),
			'block_count'      => is_array( $compiled['blocks'] ?? null ) ? count( $compiled['blocks'] ) : 0,
			'component_count'  => is_array( $compiled['components'] ?? null ) ? count( $compiled['components'] ) : 0,
			'file_count'       => is_array( $compiled['assets'] ?? null ) ? count( $compiled['assets'] ) : 0,
			'diagnostic_count' => count( $diagnostics ),
		);
	}
}

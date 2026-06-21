<?php
/**
 * Blocks Engine artifact compiler compatibility wrapper.
 *
 * @package BlockArtifactCompiler
 */

/**
 * Legacy method adapter for the public BAC compatibility functions.
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
		return bac_compile_website_artifact( $artifact, $options );
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
		return bac_compile_fragment( $content, $source, $format, $options );
	}

	/**
	 * Summarize a compiler result for upstream import reports.
	 *
	 * @param array<string,mixed> $compiled Compiler result envelope.
	 * @return array<string,mixed> Compact summary.
	 */
	public function summarize_result( array $compiled ): array {
		return bac_summarize_result( $compiled );
	}
}

<?php
/**
 * Blocks Engine artifact compiler compatibility wrapper.
 *
 * @package BlockArtifactCompiler
 */

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

/**
 * Preserves BAC's public result shape while delegating compilation to Blocks Engine.
 */
class Block_Artifact_Compiler {

	private const RESULT_SCHEMA = 'block-artifact-compiler/result/v1';
	private const INPUT_SCHEMA  = 'block-artifact-compiler/website-artifact/v1';

	/**
	 * Compile a website artifact bundle.
	 *
	 * @param array<string,mixed> $artifact Website artifact input.
	 * @param array<string,mixed> $options  Compiler options.
	 * @return array<string,mixed> BAC compatibility result envelope.
	 */
	public function compile( array $artifact, array $options = array() ): array {
		$canonical = $this->canonical_compile( $artifact, $options );

		if ( ! empty( $canonical['__bac_dependency_error'] ) ) {
			unset( $canonical['__bac_dependency_error'] );
			return $this->compatibility_envelope( $canonical, $artifact );
		}

		return $this->compatibility_envelope( $canonical, $artifact );
	}

	/**
	 * Compile a single content fragment.
	 *
	 * @param string              $content Source content.
	 * @param string              $source  Source label or path.
	 * @param string              $format  Source format.
	 * @param array<string,mixed> $options Compiler options.
	 * @return array<string,mixed> BAC compatibility result envelope.
	 */
	public function compile_fragment( string $content, string $source = 'fragment', string $format = 'html', array $options = array() ): array {
		$format = '' !== trim( $format ) ? strtolower( trim( $format ) ) : 'html';

		return $this->compile(
			array(
				'entrypoint' => $this->virtual_fragment_path( $source, $format ),
				'files'      => array(
					array(
						'path'    => $this->virtual_fragment_path( $source, $format ),
						'kind'    => $format,
						'content' => $content,
					),
				),
			),
			$options
		);
	}

	/**
	 * Summarize a compiler result for upstream import reports.
	 *
	 * @param array<string,mixed> $compiled Compiler result envelope.
	 * @return array<string,mixed> Compact summary.
	 */
	public function summarize_result( array $compiled ): array {
		$artifacts   = is_array( $compiled['wordpress_artifacts'] ?? null ) ? $compiled['wordpress_artifacts'] : array();
		$input       = is_array( $compiled['input'] ?? null ) ? $compiled['input'] : array();
		$block_tree  = is_array( $artifacts['block_tree'] ?? null ) ? $artifacts['block_tree'] : array();
		$diagnostics = is_array( $compiled['diagnostics'] ?? null ) ? $compiled['diagnostics'] : array();

		return array(
			'schema'                         => (string) ( $compiled['schema'] ?? '' ),
			'status'                         => (string) ( $compiled['status'] ?? '' ),
			'source'                         => (string) ( $compiled['provenance']['source'] ?? '' ),
			'source_element_count'           => (int) ( $input['source_report']['html']['element_count'] ?? 0 ),
			'source_class_count'             => (int) ( $input['source_report']['html']['class_count'] ?? 0 ),
			'source_css_selector_count'      => (int) ( $input['source_report']['css']['selector_count'] ?? 0 ),
			'block_count'                    => (int) ( $block_tree['block_count'] ?? 0 ),
			'block_depth'                    => (int) ( $block_tree['max_depth'] ?? 0 ),
			'block_type_count'               => is_array( $artifacts['block_types'] ?? null ) ? count( $artifacts['block_types'] ) : 0,
			'plugin_artifact_count'          => is_array( $artifacts['plugins'] ?? null ) ? count( $artifacts['plugins'] ) : 0,
			'custom_block_requirement_count' => is_array( $artifacts['requirements']['custom_blocks'] ?? null ) ? count( $artifacts['requirements']['custom_blocks'] ) : 0,
			'component_count'                => is_array( $artifacts['components'] ?? null ) ? count( $artifacts['components'] ) : 0,
			'file_count'                     => is_array( $artifacts['files'] ?? null ) ? count( $artifacts['files'] ) : 0,
			'svg_icon_artifact_count'        => is_array( $artifacts['svg_icon_artifacts'] ?? null ) ? count( $artifacts['svg_icon_artifacts'] ) : 0,
			'diagnostic_count'               => count( $diagnostics ),
		);
	}

	/**
	 * Compile through the Blocks Engine plugin helper or canonical class.
	 *
	 * @param array<string,mixed> $artifact Website artifact input.
	 * @param array<string,mixed> $options  Compiler options.
	 * @return array<string,mixed> Canonical transformer result.
	 */
	private function canonical_compile( array $artifact, array $options ): array {
		try {
			if ( function_exists( 'blocks_engine_php_transformer_compile_artifact' ) ) {
				$result = blocks_engine_php_transformer_compile_artifact( $artifact, $options );
				return is_array( $result ) ? $result : array();
			}

			if ( class_exists( ArtifactCompiler::class ) ) {
				return ( new ArtifactCompiler() )->compile( $artifact )->toArray();
			}
		} catch ( Throwable $throwable ) {
			return array(
				'status'                 => 'failed',
				'diagnostics'            => array(
					$this->diagnostic( 'canonical_artifact_compiler_failed', 'error', 'The canonical Blocks Engine artifact compiler failed.', array( 'error' => $throwable->getMessage() ) ),
				),
				'__bac_dependency_error' => true,
			);
		}

		return array(
			'status'                 => 'failed',
			'diagnostics'            => array(
				$this->diagnostic( 'blocks_engine_php_transformer_unavailable', 'error', 'The Blocks Engine PHP transformer plugin or package is required.' ),
			),
			'__bac_dependency_error' => true,
		);
	}

	/**
	 * Project the canonical transformer result into BAC's legacy envelope.
	 *
	 * @param array<string,mixed> $canonical Canonical transformer result.
	 * @param array<string,mixed> $artifact  Original artifact input.
	 * @return array<string,mixed> BAC compatibility result envelope.
	 */
	private function compatibility_envelope( array $canonical, array $artifact ): array {
		$source_reports = is_array( $canonical['source_reports'] ?? null ) ? $canonical['source_reports'] : array();
		$artifact_report = is_array( $source_reports['artifact'] ?? null ) ? $source_reports['artifact'] : array();
		$compiled_site = is_array( $source_reports['compiled_site'] ?? null ) ? $source_reports['compiled_site'] : array();
		$conversion_report = is_array( $source_reports['conversion_report'] ?? null ) ? $source_reports['conversion_report'] : array();
		$documents = is_array( $canonical['documents'] ?? null ) ? $canonical['documents'] : array();
		$assets = is_array( $canonical['assets'] ?? null ) ? $canonical['assets'] : array();
		$blocks = is_array( $canonical['blocks'] ?? null ) ? $canonical['blocks'] : array();
		$serialized_blocks = (string) ( $canonical['serialized_blocks'] ?? '' );
		$entry_path = (string) ( $artifact_report['entry_path'] ?? '' );
		$diagnostics = is_array( $canonical['diagnostics'] ?? null ) ? $canonical['diagnostics'] : array();

		$input = $artifact_report;
		$input['schema'] = self::INPUT_SCHEMA;
		$input['entry_path'] = $entry_path;
		$input['entrypoints'] = is_array( $artifact_report['entrypoints'] ?? null ) ? $artifact_report['entrypoints'] : $this->artifact_entrypoints( $artifact, $entry_path );
		$input['file_count'] = (int) ( $artifact_report['file_count'] ?? count( $assets ) );
		$input['accepted_count'] = (int) ( $artifact_report['accepted_count'] ?? $input['file_count'] );
		$input['rejected_count'] = (int) ( $artifact_report['rejected_count'] ?? 0 );
		$input['bytes'] = (int) ( $artifact_report['bytes'] ?? 0 );
		$input['files_by_kind'] = is_array( $artifact_report['files_by_kind'] ?? null ) ? $artifact_report['files_by_kind'] : array();
		$input['files_by_role'] = is_array( $artifact_report['files_by_role'] ?? null ) ? $artifact_report['files_by_role'] : array();
		$input['files_by_mime'] = is_array( $artifact_report['files_by_mime'] ?? null ) ? $artifact_report['files_by_mime'] : array();
		$input['original_schema'] = (string) ( $artifact_report['original_schema'] ?? ( $artifact['schema'] ?? '' ) );
		$input['source_report'] = $artifact_report;

		return array(
			'schema'              => self::RESULT_SCHEMA,
			'status'              => (string) ( $canonical['status'] ?? $this->status_from_diagnostics( $diagnostics ) ),
			'input'               => $input,
			'wordpress_artifacts' => array(
				'block_markup'          => $serialized_blocks,
				'blocks'                => $blocks,
				'block_tree'            => $this->block_tree_report( $blocks, $serialized_blocks ),
				'document_metadata'     => is_array( $documents[0]['document_metadata'] ?? null ) ? $documents[0]['document_metadata'] : array(),
				'site'                  => $compiled_site,
				'regions'               => is_array( $compiled_site['shared_regions'] ?? null ) ? $compiled_site['shared_regions'] : array(),
				'template_parts'        => array(),
				'asset_references'      => is_array( $artifact_report['asset_references'] ?? null ) ? $artifact_report['asset_references'] : array(),
				'svg_icon_artifacts'    => array(),
				'navigation_candidates' => array(),
				'visual_repair'         => array( 'metadata' => array() ),
				'visual_repair_metadata' => array(),
				'selector_provenance'   => array(),
				'block_types'           => is_array( $canonical['block_types'] ?? null ) ? $canonical['block_types'] : array(),
				'plugins'               => array(),
				'requirements'          => array( 'custom_blocks' => array(), 'plugins' => array() ),
				'components'            => is_array( $canonical['components'] ?? null ) ? $canonical['components'] : array(),
				'documents'             => $documents,
				'files'                 => $assets,
			),
			'provenance'          => array(
				'source_hash' => (string) ( $artifact_report['source_hash'] ?? '' ),
				'source'      => $entry_path,
				'canonical'   => is_array( $canonical['provenance'] ?? null ) ? $canonical['provenance'] : array(),
			),
			'diagnostics'         => $diagnostics,
			'bfb_report'          => $conversion_report,
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
	 * @return array<string,mixed>
	 */
	private function block_tree_report( array $blocks, string $serialized_blocks ): array {
		return array(
			'schema'      => 'block-artifact-compiler/block-tree/v1',
			'block_count' => $this->count_blocks( $blocks, $serialized_blocks ),
			'max_depth'   => $this->max_block_depth( $blocks ),
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
	 */
	private function count_blocks( array $blocks, string $serialized_blocks ): int {
		if ( array() === $blocks ) {
			return preg_match_all( '/<!--\s+wp:/', $serialized_blocks ) ?: 0;
		}

		$count = 0;
		foreach ( $blocks as $block ) {
			++$count;
			if ( is_array( $block['innerBlocks'] ?? null ) ) {
				$count += $this->count_blocks( $block['innerBlocks'], '' );
			}
		}

		return $count;
	}

	/**
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
	 */
	private function max_block_depth( array $blocks, int $depth = 0 ): int {
		$max = $depth;
		foreach ( $blocks as $block ) {
			$children = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array();
			$max = max( $max, $this->max_block_depth( $children, $depth + 1 ) );
		}

		return $max;
	}

	/**
	 * @param array<string,mixed> $artifact Original artifact input.
	 * @return array<int,string>
	 */
	private function artifact_entrypoints( array $artifact, string $entry_path ): array {
		if ( is_array( $artifact['entrypoints'] ?? null ) ) {
			return array_values( array_filter( array_map( 'strval', $artifact['entrypoints'] ) ) );
		}

		foreach ( array( 'entrypoint', 'entry', 'main' ) as $key ) {
			if ( is_string( $artifact[ $key ] ?? null ) && '' !== trim( $artifact[ $key ] ) ) {
				return array( trim( $artifact[ $key ] ) );
			}
		}

		return '' !== $entry_path ? array( $entry_path ) : array();
	}

	private function virtual_fragment_path( string $source, string $format ): string {
		$path = '' !== trim( $source ) ? trim( $source ) : 'fragment';
		$path = str_replace( '\\', '/', $path );
		$path = preg_replace( '#[^A-Za-z0-9._/\-]#', '-', $path ) ?? 'fragment';
		$path = trim( preg_replace( '#/+#', '/', $path ) ?? $path, '/' );
		$path = str_replace( '..', '.', $path );
		$path = '' !== $path ? $path : 'fragment';

		if ( 'blocks' === $format && ! str_ends_with( strtolower( $path ), '.html' ) ) {
			$path .= '.html';
		} elseif ( ! preg_match( '/\.[A-Za-z0-9]+$/', $path ) ) {
			$path .= '.' . $format;
		}

		return $path;
	}

	/**
	 * @param array<int,array<string,mixed>> $diagnostics Diagnostics.
	 */
	private function status_from_diagnostics( array $diagnostics ): string {
		foreach ( $diagnostics as $diagnostic ) {
			if ( 'error' === ( $diagnostic['severity'] ?? '' ) ) {
				return 'failed';
			}
		}

		return array() === $diagnostics ? 'success' : 'success_with_warnings';
	}

	/**
	 * @param array<string,mixed> $context Diagnostic context.
	 * @return array<string,mixed>
	 */
	private function diagnostic( string $code, string $severity, string $message, array $context = array() ): array {
		$diagnostic = array(
			'code'     => $code,
			'severity' => $severity,
			'message'  => $message,
		);

		if ( array() !== $context ) {
			$diagnostic['context'] = $context;
		}

		return $diagnostic;
	}
}

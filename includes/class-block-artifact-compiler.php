<?php
/**
 * Website artifact to WordPress artifact compiler.
 *
 * @package BlockArtifactCompiler
 */

/**
 * Compiles arbitrary website artifact bundles into WordPress-native artifacts.
 */
class Block_Artifact_Compiler {


	private const RESULT_SCHEMA = 'block-artifact-compiler/result/v1';
	private const INPUT_SCHEMA  = 'block-artifact-compiler/website-artifact/v1';

	private const DEFAULT_MAX_FILES       = 200;
	private const DEFAULT_MAX_FILE_BYTES  = 2097152;
	private const DEFAULT_MAX_TOTAL_BYTES = 10485760;

	/**
	 * Compile a website artifact bundle.
	 *
	 * @param  array<string,mixed> $artifact Website artifact input.
	 * @param  array<string,mixed> $options  Compiler options.
	 * @return array<string,mixed> Compiler result envelope.
	 */
	public function compile( array $artifact, array $options = array() ): array {
		$canonical = $this->canonical_compile($artifact);
		$normalized  = $this->normalize_artifact($artifact, $options);
		$documents   = $this->compile_source_documents($normalized, $options, $canonical);
		$entry       = $this->entry_file($normalized);
		$block_entry = $this->entry_block_file($normalized);
		$html        = is_array($entry) ? $entry['content'] : '';
		$entry_path  = is_array($entry) ? $entry['path'] : '';
		$canonical_diagnostics = isset($canonical['diagnostics']) && is_array($canonical['diagnostics']) ? $canonical['diagnostics'] : array();
		$diagnostics = $this->dedupe_diagnostics(array_merge(
			$normalized['diagnostics'],
			$documents['diagnostics'],
			$canonical_diagnostics
		));

		if ( '' === trim($html) && ! is_array($block_entry) && empty($documents['documents']) ) {
			$diagnostics[] = $this->diagnostic('missing_entry_html', 'error', 'No HTML entry file was available to compile.');
		}

		$entry_document = '' !== trim($html) ? $this->entry_document_contract($html, $entry_path) : array(
			'body_html' => '',
			'metadata'  => array(),
		);
		$conversion    = '' !== trim($entry_document['body_html']) ? $this->convert_content_to_blocks($entry_document['body_html'], 'html', $options) : array(
			'serialized_blocks'    => '',
			'blocks'               => array(),
			'diagnostics'          => array(),
			'report'               => array(),
			'asset_references'     => array(),
			'svg_icon_artifacts'   => array(),
			'navigation_candidates' => array(),
			'selector_provenance'  => array(),
			'fallbacks'            => array(),
			'metrics'              => array(),
		);
		if ( '' === trim($entry_document['body_html']) && is_array($block_entry) ) {
			$entry_path  = (string) $block_entry['path'];
			$conversion = $this->convert_content_to_blocks((string) $block_entry['content'], 'blocks', $options);
		}
		if ( '' === trim($entry_path) && ! empty($documents['documents'][0]['source_path']) ) {
			$entry_path = (string) $documents['documents'][0]['source_path'];
		}
		$canonical_source_report = $this->canonical_artifact_source_report($canonical);
		$source_report           = $this->source_report($normalized, $entry_path, $html, $canonical_source_report);

		$diagnostics = $this->dedupe_diagnostics(array_merge($diagnostics, $conversion['diagnostics']));
		$components  = isset($canonical['components']) && is_array($canonical['components']) ? $canonical['components'] : array();
		$block_types = isset($canonical['block_types']) && is_array($canonical['block_types']) ? $canonical['block_types'] : array();
		$plugins     = $this->build_plugin_artifacts($normalized, $block_types, $canonical);
		$files       = isset($canonical['assets']) && is_array($canonical['assets']) ? $canonical['assets'] : array();
		if ( '' === trim($html) && ! is_array($block_entry) && ! empty($documents['documents'][0]['block_markup']) ) {
			$conversion['serialized_blocks'] = (string) $documents['documents'][0]['block_markup'];
			$conversion['blocks']            = isset($documents['documents'][0]['blocks']) && is_array($documents['documents'][0]['blocks']) ? $documents['documents'][0]['blocks'] : array();
			$conversion['report']            = isset($documents['documents'][0]['bfb_report']) && is_array($documents['documents'][0]['bfb_report']) ? $documents['documents'][0]['bfb_report'] : array();
			$conversion['selector_provenance'] = isset($documents['documents'][0]['selector_provenance']) && is_array($documents['documents'][0]['selector_provenance']) ? $documents['documents'][0]['selector_provenance'] : array();
		}
		$requirements   = $this->build_artifact_requirements($conversion['serialized_blocks'], $block_types, $plugins, $canonical);
		$template_parts = $this->template_part_artifacts($normalized, $entry_path, $options, $canonical);
		$regions        = $this->semantic_region_contracts($normalized, $canonical);
		$visual_repair  = $this->visual_repair_artifacts($normalized, $conversion, $documents['documents'], $template_parts, $canonical);

		return array(
			'schema'              => self::RESULT_SCHEMA,
			'status'              => $this->status_from_diagnostics($diagnostics),
			'input'               => array(
				'schema'          => self::INPUT_SCHEMA,
				'entry_path'      => '' !== (string) ( $canonical_source_report['entry_path'] ?? '' ) ? (string) $canonical_source_report['entry_path'] : $entry_path,
				'entrypoints'     => isset($canonical_source_report['entrypoints']) && is_array($canonical_source_report['entrypoints']) ? $canonical_source_report['entrypoints'] : $normalized['entrypoints'],
				'file_count'      => (int) ( $canonical_source_report['file_count'] ?? count($normalized['files']) ),
				'accepted_count'  => (int) ( $canonical_source_report['accepted_count'] ?? count($normalized['files']) ),
				'rejected_count'  => (int) ( $canonical_source_report['rejected_count'] ?? $normalized['rejected_count'] ),
				'bytes'           => (int) ( $canonical_source_report['bytes'] ?? $normalized['bytes'] ),
				'files_by_kind'   => isset($canonical_source_report['files_by_kind']) && is_array($canonical_source_report['files_by_kind']) ? $canonical_source_report['files_by_kind'] : $this->count_files_by_kind($normalized['files']),
				'files_by_role'   => isset($canonical_source_report['files_by_role']) && is_array($canonical_source_report['files_by_role']) ? $canonical_source_report['files_by_role'] : $this->count_files_by_field($normalized['files'], 'role'),
				'files_by_mime'   => isset($canonical_source_report['files_by_mime']) && is_array($canonical_source_report['files_by_mime']) ? $canonical_source_report['files_by_mime'] : $this->count_files_by_field($normalized['files'], 'mime_type'),
				'original_schema' => (string) ( $artifact['schema'] ?? '' ),
				'source_report'   => $source_report,
			),
			'wordpress_artifacts' => array(
				'block_markup' => $conversion['serialized_blocks'],
				'blocks'       => $conversion['blocks'],
				'block_tree'   => $this->compiled_block_tree_report($canonical, $conversion['blocks'], $conversion['serialized_blocks']),
				'document_metadata' => $entry_document['metadata'],
				'site'         => $this->compiled_site_artifact($normalized, $documents['documents'], $template_parts, $regions, $canonical),
				'regions'      => $regions,
				'template_parts' => $template_parts,
				'asset_references' => $this->compiled_asset_references($canonical, $conversion, $documents['documents'], $template_parts),
				'svg_icon_artifacts' => $this->compiled_svg_icon_artifacts($conversion, $documents['documents'], $template_parts),
				'navigation_candidates' => $this->compiled_navigation_candidates($conversion, $documents['documents'], $template_parts),
				'visual_repair' => $visual_repair,
				'visual_repair_metadata' => $visual_repair['metadata'],
				'selector_provenance' => $conversion['selector_provenance'],
				'block_types'  => $block_types,
				'plugins'      => $plugins,
				'requirements' => $requirements,
				'components'   => $components,
				'documents'    => $documents['documents'],
				'files'        => $files,
			),
			'provenance'          => array(
				'source_hash' => hash('sha256', $this->artifact_hash_payload($normalized)),
				'source'      => $entry_path,
			),
			'diagnostics'         => $diagnostics,
			'bfb_report'          => $conversion['report'],
		);
	}

	/**
	 * Compile through the canonical Blocks Engine transformer package.
	 *
	 * @param array<string,mixed> $artifact Website artifact input.
	 * @return array<string,mixed> Canonical transformer result, or an empty array if the package is unavailable.
	 */
	private function canonical_compile( array $artifact ): array {
		$compiler_class = 'Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\ArtifactCompiler';
		if ( ! class_exists($compiler_class) ) {
			return array();
		}

		try {
			$result = ( new $compiler_class() )->compile($artifact);
		} catch ( Throwable $throwable ) {
			return array(
				'diagnostics' => array(
					$this->diagnostic(
						'canonical_artifact_compiler_failed',
						'warning',
						'The canonical Blocks Engine artifact compiler failed; BAC local compatibility compilation continued.',
						array(
							'compiler' => $compiler_class,
							'error'    => $throwable->getMessage(),
						)
					),
				),
			);
		}
		if ( ! is_object($result) || ! method_exists($result, 'toArray') ) {
			return array();
		}

		$compiled = $result->toArray();
		return is_array($compiled) ? $compiled : array();
	}

	/**
	 * Compile a single content fragment.
	 *
	 * @param  string               $content Source content.
	 * @param  string               $source  Source label or path.
	 * @param  string               $format  Source format.
	 * @param  array<string, mixed> $options Compiler options.
	 * @return array<string,mixed> Compiler result envelope.
	 */
	public function compile_fragment( string $content, string $source = 'fragment', string $format = 'html', array $options = array() ): array {
		$path = $this->virtual_fragment_path($source, $format);

		return $this->compile(
			array(
				'files' => array(
					array(
						'path'    => $path,
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
	 * @param  array<string,mixed> $compiled Compiler result envelope.
	 * @return array<string,mixed> Compact summary.
	 */
	public function summarize_result( array $compiled ): array {
		$artifacts    = isset($compiled['wordpress_artifacts']) && is_array($compiled['wordpress_artifacts']) ? $compiled['wordpress_artifacts'] : array();
		$block_types  = isset($artifacts['block_types']) && is_array($artifacts['block_types']) ? $artifacts['block_types'] : array();
		$plugins      = isset($artifacts['plugins']) && is_array($artifacts['plugins']) ? $artifacts['plugins'] : array();
		$requirements = isset($artifacts['requirements']) && is_array($artifacts['requirements']) ? $artifacts['requirements'] : array();
		$components   = isset($artifacts['components']) && is_array($artifacts['components']) ? $artifacts['components'] : array();
		$files        = isset($artifacts['files']) && is_array($artifacts['files']) ? $artifacts['files'] : array();
		$diagnostics  = isset($compiled['diagnostics']) && is_array($compiled['diagnostics']) ? $compiled['diagnostics'] : array();
		$source       = isset($compiled['input']['source_report']) && is_array($compiled['input']['source_report']) ? $compiled['input']['source_report'] : array();
		$block_tree   = isset($artifacts['block_tree']) && is_array($artifacts['block_tree']) ? $artifacts['block_tree'] : array();

		return array(
			'schema'                    => isset($compiled['schema']) ? (string) $compiled['schema'] : '',
			'status'                    => isset($compiled['status']) ? (string) $compiled['status'] : '',
			'source'                    => isset($compiled['provenance']['source']) ? (string) $compiled['provenance']['source'] : '',
			'source_element_count'      => (int) ( $source['html']['element_count'] ?? 0 ),
			'source_class_count'        => (int) ( $source['html']['class_count'] ?? 0 ),
			'source_css_selector_count' => (int) ( $source['css']['selector_count'] ?? 0 ),
			'block_count'               => (int) ( $block_tree['block_count'] ?? 0 ),
			'block_depth'               => (int) ( $block_tree['max_depth'] ?? 0 ),
			'block_type_count'          => count($block_types),
			'plugin_artifact_count'     => count($plugins),
			'custom_block_requirement_count' => isset($requirements['custom_blocks']) && is_array($requirements['custom_blocks']) ? count($requirements['custom_blocks']) : 0,
			'component_count'           => count($components),
			'file_count'                => count($files),
			'svg_icon_artifact_count'   => isset($artifacts['svg_icon_artifacts']) && is_array($artifacts['svg_icon_artifacts']) ? count($artifacts['svg_icon_artifacts']) : 0,
			'diagnostic_count'          => count($diagnostics),
		);
	}

	/**
	 * Normalize supported website artifact input shapes.
	 *
	 * @param  array<string,mixed> $artifact Raw artifact.
	 * @param  array<string,mixed> $options  Compiler options.
	 * @return array{files:array<int,array<string,mixed>>,diagnostics:array<int,array<string,mixed>>,rejected_count:int,bytes:int,entrypoints:array<int,string>}
	 */
	private function normalize_artifact( array $artifact, array $options ): array {
		$limits          = array(
			'max_files'       => max(1, (int) ( $options['max_files'] ?? self::DEFAULT_MAX_FILES )),
			'max_file_bytes'  => max(1, (int) ( $options['max_file_bytes'] ?? self::DEFAULT_MAX_FILE_BYTES )),
			'max_total_bytes' => max(1, (int) ( $options['max_total_bytes'] ?? self::DEFAULT_MAX_TOTAL_BYTES )),
		);
		$raw_entrypoints = $this->extract_entrypoints($artifact);
		$raw_files       = $this->extract_raw_files($artifact);
		$files           = array();
		$diagnostics     = array();
		$total_bytes     = 0;
		$rejected        = 0;
		$seen_paths      = array();
		$entrypoints     = array();

		foreach ( $raw_entrypoints as $entrypoint ) {
			$path = $this->safe_relative_path($entrypoint);
			if ( '' === $path ) {
				$diagnostics[] = $this->diagnostic('unsafe_entrypoint_path', 'warning', 'An artifact entrypoint was ignored because its path is empty, absolute, or escapes the artifact root.', array( 'path' => $entrypoint ));
				continue;
			}
			$entrypoints[ $path ] = true;
		}
		$declared_entrypoints = array_keys($entrypoints);

		foreach ( $raw_files as $index => $file ) {
			if ( count($files) >= $limits['max_files'] ) {
				++$rejected;
				$diagnostics[] = $this->diagnostic('file_limit_exceeded', 'warning', 'Additional artifact files were ignored because the file limit was reached.', array( 'max_files' => $limits['max_files'] ));
				break;
			}

			$path = $this->safe_relative_path( (string) ( $file['path'] ?? '' ));
			if ( '' === $path ) {
				++$rejected;
				$diagnostics[] = $this->diagnostic('unsafe_artifact_path', 'warning', 'An artifact file was ignored because its path is empty, absolute, or escapes the artifact root.', array( 'index' => $index ));
				continue;
			}

			$payload     = $this->normalize_file_payload($file, $path);
			$diagnostics = array_merge($diagnostics, $payload['diagnostics']);
			if ( ! $payload['accepted'] ) {
				++$rejected;
				continue;
			}

			$content = $payload['content'];
			$bytes   = $payload['bytes'];
			if ( $bytes > $limits['max_file_bytes'] ) {
				++$rejected;
				$diagnostics[] = $this->diagnostic(
					'artifact_file_too_large',
					'warning',
					'An artifact file was ignored because it exceeds the per-file byte limit.',
					array(
						'path'           => $path,
						'bytes'          => $bytes,
						'max_file_bytes' => $limits['max_file_bytes'],
					)
				);
				continue;
			}

			if ( $total_bytes + $bytes > $limits['max_total_bytes'] ) {
				++$rejected;
				$diagnostics[] = $this->diagnostic(
					'artifact_total_too_large',
					'warning',
					'An artifact file was ignored because the bundle byte limit was reached.',
					array(
						'path'            => $path,
						'bytes'           => $bytes,
						'max_total_bytes' => $limits['max_total_bytes'],
					)
				);
				continue;
			}

			$deduped_path                = $this->dedupe_path($path, $seen_paths);
			$seen_paths[ $deduped_path ] = true;
			$total_bytes                += $bytes;
			$mime_type                   = $this->normalize_mime_type( (string) ( $file['mime_type'] ?? $file['mime'] ?? $file['media_type'] ?? ( str_contains( (string) ( $file['type'] ?? '' ), '/') ? $file['type'] : '' ) ), $deduped_path);
			$kind                        = $this->normalize_kind( (string) ( $file['kind'] ?? $file['type'] ?? '' ), $deduped_path, $content, $mime_type);
			$is_binary                   = $payload['binary'] || $this->is_binary_mime_type($mime_type);
			$role                        = $this->normalize_role( (string) ( $file['role'] ?? '' ), $kind, $mime_type, $deduped_path);
			$intent                      = $this->normalize_intent( (string) ( $file['intent'] ?? '' ), $kind, $role);
			$is_entry                    = ! empty($entrypoints[ $deduped_path ]) || ! empty($file['entrypoint']) || 'entry' === $role;
			$content_base64              = $payload['content_base64'];
			if ( $is_binary && '' === $content_base64 ) {
             // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Required for API authentication, not obfuscation.
				$content_base64 = base64_encode($content);
			}

			if ( $is_entry ) {
				$entrypoints[ $deduped_path ] = true;
			}

			$normalized_file = array(
				'path'       => $deduped_path,
				'content'    => $content,
				'kind'       => $kind,
				'bytes'      => $bytes,
				'source'     => (string) ( $file['source'] ?? 'artifact' ),
				'mime_type'  => $mime_type,
				'role'       => $role,
				'encoding'   => $payload['encoding'],
				'binary'     => $is_binary,
				'entrypoint' => $is_entry,
				'provenance' => array(
					'source_path' => $deduped_path,
					'source'      => (string) ( $file['source'] ?? 'artifact' ),
					'hash'        => hash('sha256', '' !== $content_base64 ? $content_base64 : $content),
				),
			);

			if ( '' !== $content_base64 ) {
				$normalized_file['content_base64'] = $content_base64;
			}
			if ( '' !== $intent ) {
				$normalized_file['intent'] = $intent;
			}
			$files[] = $normalized_file;

			if ( 'mdx' === $kind ) {
				$diagnostics[] = $this->diagnostic('mdx_source_document_detected', 'warning', 'MDX source document support is partial; BAC preserved the source and extracted inspectable document/component metadata.', array( 'path' => $deduped_path ));
			}
		}

		return array(
			'files'          => $files,
			'diagnostics'    => $this->dedupe_diagnostics($diagnostics),
			'rejected_count' => $rejected,
			'bytes'          => $total_bytes,
			'entrypoints'    => array_keys($entrypoints),
			'declared_entrypoints' => $declared_entrypoints,
		);
	}

	/**
	 * Extract file-like entries from common AI artifact shapes.
	 *
	 * @param  array<string,mixed> $artifact Raw artifact.
	 * @return array<int,array<string,mixed>> Raw files.
	 */
	private function extract_raw_files( array $artifact ): array {
		$files = array();
		foreach ( array( 'files', 'artifacts', 'outputs' ) as $key ) {
			if ( isset($artifact[ $key ]) && is_array($artifact[ $key ]) ) {
				$files = array_merge($files, $this->normalize_file_collection($artifact[ $key ], $key));
			}
		}

		foreach ( array( 'html', 'generated_html', 'content', 'body' ) as $key ) {
			if ( isset($artifact[ $key ]) && is_string($artifact[ $key ]) && '' !== trim($artifact[ $key ]) ) {
				$files[] = array(
					'path'    => 'index.html',
					'content' => $artifact[ $key ],
					'kind'    => 'html',
					'source'  => $key,
				);
			}
		}

		foreach ( array(
			'css'        => 'style.css',
			'styles'     => 'style.css',
			'javascript' => 'site.js',
			'js'         => 'site.js',
			'script'     => 'site.js',
		) as $key => $path ) {
			if ( isset($artifact[ $key ]) && is_string($artifact[ $key ]) && '' !== trim($artifact[ $key ]) ) {
				$files[] = array(
					'path'    => $path,
					'content' => $artifact[ $key ],
					'kind'    => str_contains($path, '.css') ? 'css' : 'js',
					'source'  => $key,
				);
			}
		}

		return $files;
	}

	/**
	 * Extract explicit bundle entrypoints from common artifact shapes.
	 *
	 * @param  array<string,mixed> $artifact Raw artifact.
	 * @return array<int,string> Entrypoint paths.
	 */
	private function extract_entrypoints( array $artifact ): array {
		$entrypoints = array();
		foreach ( array( 'entrypoint', 'entry', 'main' ) as $key ) {
			if ( isset($artifact[ $key ]) && is_string($artifact[ $key ]) ) {
				$entrypoints[] = $artifact[ $key ];
			}
		}

		if ( isset($artifact['entrypoints']) && is_array($artifact['entrypoints']) ) {
			foreach ( $artifact['entrypoints'] as $entrypoint ) {
				if ( is_string($entrypoint) ) {
					$entrypoints[] = $entrypoint;
				}
			}
		}

		return array_values(array_unique($entrypoints));
	}

	/**
	 * Normalize a list or path=>content map into file entries.
	 *
	 * @param  array<mixed> $collection File collection.
	 * @param  string       $source     Source key.
	 * @return array<int,array<string,mixed>> Raw files.
	 */
	private function normalize_file_collection( array $collection, string $source ): array {
		$files = array();
		foreach ( $collection as $key => $file ) {
			if ( is_array($file) ) {
				$path_source     = $file['path'] ?? $file['name'] ?? $key;
				$artifact_source = $file['source'] ?? $source;
				$file['path']    = is_scalar($path_source) ? (string) $path_source : '';
				$file['source']  = is_scalar($artifact_source) ? (string) $artifact_source : $source;
				$files[]         = $file;
				continue;
			}

			if ( is_string($file) ) {
				$path    = is_string($key) ? $key : 'artifact-' . (string) $key . '.html';
				$files[] = array(
					'path'    => $path,
					'content' => $file,
					'kind'    => '',
					'source'  => $source,
				);
			}
		}

		return $files;
	}

	/**
	 * Return the HTML entry file.
	 *
	 * @param  array{files:array<int,array<string,mixed>>,entrypoints?:array<int,string>} $artifact Normalized artifact.
	 * @return array<string,mixed>|null
	 */
	private function entry_file( array $artifact ): ?array {
		$entrypoints = $artifact['entrypoints'] ?? array();
		foreach ( $entrypoints as $entrypoint ) {
			foreach ( $artifact['files'] as $file ) {
				if ( $entrypoint === $file['path'] && 'html' === $file['kind'] && empty($file['binary']) ) {
					return $file;
				}
			}
		}

		$preferred = array( 'index.html', 'index.htm', 'static-site/index.html', 'public/index.html' );
		foreach ( $preferred as $path ) {
			foreach ( $artifact['files'] as $file ) {
				if ( strtolower( (string) $file['path']) === $path && empty($file['binary']) ) {
					return $file;
				}
			}
		}

		foreach ( $artifact['files'] as $file ) {
			if ( 'html' === $file['kind'] && empty($file['binary']) ) {
				return $file;
			}
		}

		return null;
	}

	/**
	 * Return the entry file when the source is already serialized block markup.
	 *
	 * @param  array{files:array<int,array<string,mixed>>,entrypoints?:array<int,string>} $artifact Normalized artifact.
	 * @return array<string,mixed>|null
	 */
	private function entry_block_file( array $artifact ): ?array {
		$entrypoints = $artifact['entrypoints'] ?? array();
		foreach ( $entrypoints as $entrypoint ) {
			foreach ( $artifact['files'] as $file ) {
				if ( $entrypoint === $file['path'] && 'blocks' === $file['kind'] && empty($file['binary']) ) {
					return $file;
				}
			}
		}

		foreach ( $artifact['files'] as $file ) {
			if ( 'blocks' === $file['kind'] && empty($file['binary']) ) {
				return $file;
			}
		}

		return null;
	}

	/**
	 * Split a full HTML document into renderable body markup and document metadata.
	 *
	 * @param string $html       Source HTML.
	 * @param string $entry_path Source path.
	 * @return array{body_html:string,metadata:array<string,mixed>}
	 */
	private function entry_document_contract( string $html, string $entry_path ): array {
		$metadata = array(
			'schema'      => 'block-artifact-compiler/document-metadata/v1',
			'source_path' => $entry_path,
			'title'       => '',
			'meta'        => array(),
			'links'       => array(),
			'styles'      => array(),
			'scripts'     => array(),
		);

		if ( '' === trim($html) || ! class_exists('DOMDocument') || ! $this->is_full_html_document($html) ) {
			return array(
				'body_html' => $html,
				'metadata'  => $metadata,
			);
		}

		$doc      = new DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$loaded   = $doc->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		if ( ! $loaded ) {
			return array(
				'body_html' => $html,
				'metadata'  => $metadata,
			);
		}

		$head = $doc->getElementsByTagName('head')->item(0);
		if ( $head instanceof DOMElement ) {
			$metadata['title']   = $this->first_text_child($head, 'title');
			$metadata['meta']    = $this->head_element_attributes($head, 'meta', array( 'charset', 'name', 'property', 'http-equiv', 'content' ));
			$metadata['links']   = $this->head_element_attributes($head, 'link', array( 'rel', 'href', 'as', 'type', 'media', 'crossorigin', 'integrity' ));
			$metadata['styles']  = $this->head_inline_contents($head, 'style');
			$metadata['scripts'] = $this->document_script_contracts($head, 'head', false);
		}

		$body = $doc->getElementsByTagName('body')->item(0);
		if ( $body instanceof DOMElement ) {
			$metadata['scripts'] = array_merge($metadata['scripts'], $this->document_script_contracts($body, 'body', true));
		}
		$body_html = $body instanceof DOMElement ? $this->inner_html($doc, $body) : $this->document_without_head($doc);

		return array(
			'body_html' => trim($body_html),
			'metadata'  => $metadata,
		);
	}

	/**
	 * Check whether source HTML should be parsed as a full document.
	 *
	 * Body fragments are already the editable payload. Parsing them with
	 * DOMDocument can repair sibling block elements into invalid descendants.
	 *
	 * @param string $html Source HTML.
	 * @return bool
	 */
	private function is_full_html_document( string $html ): bool {
		return 1 === preg_match('/<(?:!doctype\s+html|html|head|body)\b/i', $html);
	}

	/**
	 * Read the first text child by tag name.
	 *
	 * @param DOMElement $root Root element.
	 * @param string     $tag  Tag name.
	 * @return string
	 */
	private function first_text_child( DOMElement $root, string $tag ): string {
		$nodes = $root->getElementsByTagName($tag);
		$node  = $nodes->length > 0 ? $nodes->item(0) : null;

		return $node instanceof DOMNode ? trim((string) $node->textContent) : '';
	}

	/**
	 * Collect safe attributes from head child elements.
	 *
	 * @param DOMElement        $head       Head element.
	 * @param string            $tag        Tag name.
	 * @param array<int,string> $attributes Attribute allow-list.
	 * @return array<int,array<string,string>>
	 */
	private function head_element_attributes( DOMElement $head, string $tag, array $attributes ): array {
		$items = array();
		foreach ( $head->getElementsByTagName($tag) as $node ) {
			if ( ! $node instanceof DOMElement ) {
				continue;
			}

			$item = array();
			foreach ( $attributes as $attribute ) {
				$value = trim($node->getAttribute($attribute));
				if ( '' !== $value ) {
					$item[ str_replace('-', '_', $attribute) ] = $value;
				}
			}

			if ( ! empty($item) ) {
				$items[] = $item;
			}
		}

		return $items;
	}

	/**
	 * Collect inline head element contents.
	 *
	 * @param DOMElement $head Head element.
	 * @param string     $tag  Tag name.
	 * @return array<int,array<string,mixed>>
	 */
	private function head_inline_contents( DOMElement $head, string $tag ): array {
		$items = array();
		foreach ( $head->getElementsByTagName($tag) as $node ) {
			$content = trim((string) $node->textContent);
			if ( '' === $content ) {
				continue;
			}

			$items[] = array(
				'content' => $content,
				'bytes'   => strlen($content),
				'hash'    => hash('sha256', $content),
			);
		}

		return $items;
	}

	/**
	 * Collect script metadata from document-level script tags.
	 *
	 * @param DOMElement $root      Root element.
	 * @param string     $placement Script placement.
	 * @param bool       $remove    Whether collected scripts should be removed from the DOM.
	 * @return array<int,array<string,mixed>>
	 */
	private function document_script_contracts( DOMElement $root, string $placement, bool $remove ): array {
		$scripts = array();
		foreach ( iterator_to_array($root->getElementsByTagName('script')) as $node ) {
			if ( ! $node instanceof DOMElement ) {
				continue;
			}

			$script = array();
			foreach ( array( 'src', 'type', 'defer', 'async', 'crossorigin', 'integrity' ) as $attribute ) {
				if ( $node->hasAttribute($attribute) ) {
					$value = trim($node->getAttribute($attribute));
					$script[ $attribute ] = in_array($attribute, array( 'defer', 'async' ), true) ? true : $value;
				}
			}

			$content = trim((string) $node->textContent);
			if ( '' !== $content ) {
				$script['inline'] = array(
					'bytes' => strlen($content),
					'hash'  => hash('sha256', $content),
				);
			}

			if ( ! empty($script) ) {
				$script['placement'] = $placement;
				$scripts[] = $script;
			}

			if ( $remove && $node->parentNode instanceof DOMNode ) {
				$node->parentNode->removeChild($node);
			}
		}

		return $scripts;
	}

	/**
	 * Serialize a DOM element's children.
	 *
	 * @param DOMDocument $doc     Document.
	 * @param DOMElement  $element Element.
	 * @return string
	 */
	private function inner_html( DOMDocument $doc, DOMElement $element ): string {
		$html = array();
		foreach ( $element->childNodes as $child ) {
			$serialized = $doc->saveHTML($child);
			if ( false !== $serialized ) {
				$html[] = $serialized;
			}
		}

		return implode('', $html);
	}

	/**
	 * Serialize the document after removing document-level head metadata.
	 *
	 * @param DOMDocument $doc Document.
	 * @return string
	 */
	private function document_without_head( DOMDocument $doc ): string {
		foreach ( iterator_to_array($doc->getElementsByTagName('head')) as $head ) {
			if ( $head instanceof DOMNode && $head->parentNode instanceof DOMNode ) {
				$head->parentNode->removeChild($head);
			}
		}

		$html = $doc->saveHTML();
		return false === $html ? '' : $html;
	}

	/**
	 * Convert supported source content to block markup through BFB/H2BC when available.
	 *
	 * @param  string              $content Source content.
	 * @param  string              $format  Source format.
	 * @param  array<string,mixed> $options Compiler options.
	 * @return array{serialized_blocks:string,blocks:array,diagnostics:array<int,array<string,mixed>>,report:array<string,mixed>,asset_references:array<int,array<string,mixed>>,svg_icon_artifacts:array<int,array<string,mixed>>,navigation_candidates:array<int,array<string,mixed>>,visual_repair_metadata:array<string,mixed>,selector_provenance:array<int,array<string,mixed>>,fallbacks:array<int,array<string,mixed>>,metrics:array<string,mixed>}
	 */
	private function convert_content_to_blocks( string $content, string $format, array $options ): array {
		$format = $this->normalize_fragment_format($format);
		if ( 'blocks' === $format || str_contains($content, '<!-- wp:') ) {
			$blocks           = function_exists('parse_blocks') ? parse_blocks($content) : array();
			$serialized_blocks = function_exists('serialize_blocks') && ! empty($blocks) ? serialize_blocks($blocks) : $content;
			return array(
				'serialized_blocks' => $serialized_blocks,
				'blocks'            => $blocks,
				'diagnostics'       => array(),
				'report'            => array(
					'status' => 'success_native',
					'source' => 'blocks',
				),
				'asset_references'      => array(),
				'svg_icon_artifacts'    => array(),
				'navigation_candidates' => array(),
				'visual_repair_metadata' => array(),
				'selector_provenance'   => array(),
				'fallbacks'             => array(),
				'metrics'               => array(),
			);
		}

		if ( 'html' === $format && function_exists('html_to_blocks_convert_fragment') ) {
			$result = html_to_blocks_convert_fragment($content, array_merge($options, array( 'context' => 'block_artifact_compiler' )));
			$report = array(
				'status'      => '' === trim((string) ( $result['block_markup'] ?? '' )) ? 'failed' : 'success_native',
				'source'      => 'html',
				'h2bc_result' => array(
					'source'                     => isset($result['source']) && is_array($result['source']) ? $result['source'] : array(),
					'metrics'                    => isset($result['metrics']) && is_array($result['metrics']) ? $result['metrics'] : array(),
					'fallbacks'                  => isset($result['fallbacks']) && is_array($result['fallbacks']) ? $result['fallbacks'] : array(),
					'asset_reference_count'       => isset($result['asset_references']) && is_array($result['asset_references']) ? count($result['asset_references']) : 0,
					'svg_icon_artifact_count'     => isset($result['svg_icon_artifacts']) && is_array($result['svg_icon_artifacts']) ? count($result['svg_icon_artifacts']) : 0,
					'navigation_candidate_count' => isset($result['navigation_candidates']) && is_array($result['navigation_candidates']) ? count($result['navigation_candidates']) : 0,
					'visual_repair_category_counts' => $this->visual_repair_category_counts(isset($result['visual_repair_metadata']) && is_array($result['visual_repair_metadata']) ? $result['visual_repair_metadata'] : array()),
					'selector_provenance_count'  => isset($result['selector_provenance']) && is_array($result['selector_provenance']) ? count($result['selector_provenance']) : 0,
				),
			);

			return array(
				'serialized_blocks'     => (string) ( $result['block_markup'] ?? '' ),
				'blocks'                => isset($result['blocks']) && is_array($result['blocks']) ? $result['blocks'] : array(),
				'diagnostics'           => isset($result['diagnostics']) && is_array($result['diagnostics']) ? $result['diagnostics'] : array(),
				'report'                => $report,
				'asset_references'      => isset($result['asset_references']) && is_array($result['asset_references']) ? $result['asset_references'] : array(),
				'svg_icon_artifacts'    => isset($result['svg_icon_artifacts']) && is_array($result['svg_icon_artifacts']) ? $result['svg_icon_artifacts'] : array(),
				'navigation_candidates' => isset($result['navigation_candidates']) && is_array($result['navigation_candidates']) ? $result['navigation_candidates'] : array(),
				'visual_repair_metadata' => isset($result['visual_repair_metadata']) && is_array($result['visual_repair_metadata']) ? $result['visual_repair_metadata'] : array(),
				'selector_provenance'   => isset($result['selector_provenance']) && is_array($result['selector_provenance']) ? $result['selector_provenance'] : array(),
				'fallbacks'             => isset($result['fallbacks']) && is_array($result['fallbacks']) ? $result['fallbacks'] : array(),
				'metrics'               => isset($result['metrics']) && is_array($result['metrics']) ? $result['metrics'] : array(),
			);
		}

		if ( function_exists('bfb_convert') ) {
			$block_markup = (string) bfb_convert($content, $format, 'blocks', $options);
			$report       = array( 'status' => '' === trim($block_markup) ? 'failed' : 'success_native' );
			if ( ! empty($options['include_bfb_report']) && function_exists('bfb_conversion_report') ) {
				$report = bfb_conversion_report($content, $format, $options);
			}

			return array(
				'serialized_blocks' => $block_markup,
				'blocks'            => function_exists('parse_blocks') && '' !== trim($block_markup) ? parse_blocks($block_markup) : array(),
				'diagnostics'       => isset($report['diagnostics']) && is_array($report['diagnostics']) ? $report['diagnostics'] : array(),
				'report'            => $report,
				'asset_references'      => array(),
				'svg_icon_artifacts'    => array(),
				'navigation_candidates' => array(),
				'visual_repair_metadata' => array(),
				'selector_provenance'   => array(),
				'fallbacks'             => array(),
				'metrics'               => array(),
			);
		}

		if ( empty($options['allow_bfb_unavailable_fallback']) ) {
			return array(
				'serialized_blocks' => '',
				'blocks'            => array(),
				'diagnostics'       => array(
					$this->diagnostic('bfb_unavailable', 'error', 'BFB is unavailable; source conversion cannot run in production compile mode.', array( 'format' => $format )),
				),
				'report'            => array(
					'status' => 'failed',
					'source' => $format,
				),
				'asset_references'      => array(),
				'svg_icon_artifacts'    => array(),
				'navigation_candidates' => array(),
				'visual_repair_metadata' => array(),
				'selector_provenance'   => array(),
				'fallbacks'             => array(),
				'metrics'               => array(),
			);
		}

		return array(
			'serialized_blocks' => '<!-- wp:html -->' . "\n" . $content . "\n" . '<!-- /wp:html -->',
			'blocks'            => array(),
			'diagnostics'       => array(
				$this->diagnostic('bfb_unavailable_fallback', 'warning', 'BFB is unavailable; preserved source content as an explicit core/html fallback.', array( 'format' => $format )),
			),
			'report'            => array(
				'status' => 'success_with_fallbacks',
				'source' => $format,
			),
			'asset_references'      => array(),
			'svg_icon_artifacts'    => array(),
			'navigation_candidates' => array(),
			'visual_repair_metadata' => array(),
			'selector_provenance'   => array(),
			'fallbacks'             => array(),
			'metrics'               => array(),
		);
	}

	/**
	 * Build source-side structural evidence before conversion mutates the document.
	 *
	 * @param  array{files:array<int,array<string,mixed>>} $artifact Normalized artifact.
	 * @return array<string,mixed>
	 */
	private function source_report( array $artifact, string $entry_path, string $html, array $canonical_source_report = array() ): array {
		$report = array(
			'entry_path' => $entry_path,
			'html'       => $this->html_structure_report($html),
			'css'        => $this->css_structure_report($artifact['files']),
		);

		if ( empty($canonical_source_report) ) {
			return $report;
		}

		foreach ( array( 'source_hash', 'internal_links', 'asset_references', 'image_references', 'files_by_source', 'files_by_intent', 'limits' ) as $key ) {
			if ( array_key_exists($key, $canonical_source_report) ) {
				$report[ $key ] = $canonical_source_report[ $key ];
			}
		}

		$report['blocks_engine'] = array_filter(
			array(
				'schema'      => isset($canonical_source_report['schema']) ? (string) $canonical_source_report['schema'] : '',
				'source_hash' => isset($canonical_source_report['source_hash']) ? (string) $canonical_source_report['source_hash'] : '',
			),
			static fn ( mixed $value ): bool => '' !== $value
		);

		return $report;
	}

	/**
	 * Extract the current Blocks Engine artifact source report.
	 *
	 * @param array<string,mixed> $canonical Canonical Blocks Engine result.
	 * @return array<string,mixed> Artifact source report.
	 */
	private function canonical_artifact_source_report( array $canonical ): array {
		$report = $canonical['source_reports']['artifact'] ?? array();
		return is_array($report) ? $report : array();
	}

	/**
	 * Build a materializer-neutral compiled site/theme artifact.
	 *
	 * @param  array{files:array<int,array<string,mixed>>} $artifact       Normalized artifact.
	 * @param  array<int,array<string,mixed>>              $documents      Compiled document artifacts.
	 * @param  array<int,array<string,mixed>>              $template_parts Template part artifacts.
	 * @param  array<int,array<string,mixed>>              $regions        Semantic region artifacts.
	 * @param  array<string,mixed>                         $canonical      Canonical Blocks Engine result.
	 * @return array<string,mixed> Compiled site artifact.
	 */
	private function compiled_site_artifact( array $artifact, array $documents, array $template_parts, array $regions, array $canonical ): array {
		$canonical_site = $this->canonical_compiled_site_report($canonical);
		$shared_regions = $this->shared_region_contracts($regions);
		$route_map      = array();
		$rewrite_map    = array();
		$front_page     = array();
		$pages          = array_map(
			static function ( array $document ) use ( &$route_map, &$rewrite_map, &$front_page ): array {
				$route_keys          = isset($document['route_keys']) && is_array($document['route_keys']) ? array_values($document['route_keys']) : array();
				$link_rewrite_keys   = isset($document['link_rewrite_keys']) && is_array($document['link_rewrite_keys']) ? array_values($document['link_rewrite_keys']) : $route_keys;
				$route_key           = (string) ( $document['route_key'] ?? $document['slug'] ?? '' );
				$link_rewrite_target = (string) ( $document['link_rewrite_target'] ?? $document['permalink_path'] ?? '' );

				foreach ( $route_keys as $key ) {
					$route_map[ (string) $key ] = $route_key;
				}
				foreach ( $link_rewrite_keys as $key ) {
					$rewrite_map[ (string) $key ] = array(
						'route_key'   => $route_key,
						'target_path' => $link_rewrite_target,
					);
				}

				$is_front_page = ! empty($document['front_page']);
				$page          = array(
					'source_path'          => (string) ( $document['source_path'] ?? '' ),
					'route_key'            => $route_key,
					'route_keys'           => $route_keys,
					'route_path'           => (string) ( $document['route_path'] ?? '' ),
					'permalink_path'       => (string) ( $document['permalink_path'] ?? '' ),
					'link_rewrite_keys'    => $link_rewrite_keys,
					'link_rewrite_target'  => $link_rewrite_target,
					'slug'                 => (string) ( $document['slug'] ?? '' ),
					'canonical_slug'       => (string) ( $document['canonical_slug'] ?? $document['slug'] ?? '' ),
					'post_type'            => (string) ( $document['post_type'] ?? 'page' ),
					'status'               => (string) ( $document['status'] ?? 'publish' ),
					'post_status'          => (string) ( $document['post_status'] ?? $document['status'] ?? 'publish' ),
					'title'                => (string) ( $document['title'] ?? '' ),
					'entrypoint'           => ! empty($document['entrypoint']),
					'front_page'           => $is_front_page,
					'artifact'             => 'wordpress_artifacts.documents',
				);

				if ( $is_front_page && empty($front_page) ) {
					$front_page = $page;
				}

				return $page;
			},
			$documents
		);

		return array(
			'schema'         => 'block-artifact-compiler/compiled-site/v1',
			'pages'          => $pages,
			'front_page'       => $front_page,
			'route_map'        => $route_map,
			'link_rewrite_map' => $rewrite_map,
			'regions'        => array_map(
				static function ( array $region ): array {
					return array(
						'role'         => (string) ( $region['role'] ?? '' ),
						'source_hash'  => (string) ( $region['source_hash'] ?? '' ),
						'source_paths' => isset($region['source_paths']) && is_array($region['source_paths']) ? $region['source_paths'] : array(),
						'artifact'     => 'wordpress_artifacts.regions',
					);
				},
				$regions
			),
			'shared_regions' => $shared_regions,
			'template_parts' => array_map(
				static function ( array $template_part ): array {
					return array(
						'slug'         => (string) ( $template_part['slug'] ?? '' ),
						'area'         => (string) ( $template_part['area'] ?? '' ),
						'source_hash'  => (string) ( $template_part['source_hash'] ?? '' ),
						'source_paths' => isset($template_part['source_paths']) && is_array($template_part['source_paths']) ? $template_part['source_paths'] : array(),
						'artifact'     => 'wordpress_artifacts.template_parts',
					);
				},
				$template_parts
			),
			'theme_assets'   => $this->theme_asset_contracts($artifact, $canonical_site),
			'blocks_engine'  => $this->blocks_engine_site_report_summary($canonical_site),
			'provenance'     => array(
				'source_hash' => hash('sha256', $this->artifact_hash_payload($artifact)),
			),
		);
	}

	/**
	 * Extract the current Blocks Engine compiled-site report from the canonical result.
	 *
	 * @param array<string,mixed> $canonical Canonical Blocks Engine result.
	 * @return array<string,mixed> Compiled-site report.
	 */
	private function canonical_compiled_site_report( array $canonical ): array {
		$report = $canonical['source_reports']['compiled_site'] ?? array();
		return is_array($report) ? $report : array();
	}

	/**
	 * Preserve BAC's public report shape while exposing the canonical source report identity.
	 *
	 * @param array<string,mixed> $canonical_site Canonical compiled-site report.
	 * @return array<string,mixed> Canonical report summary.
	 */
	private function blocks_engine_site_report_summary( array $canonical_site ): array {
		if ( empty($canonical_site) ) {
			return array();
		}

		return array_filter(
			array(
				'schema'      => isset($canonical_site['schema']) ? (string) $canonical_site['schema'] : '',
				'source_hash' => isset($canonical_site['source_hash']) ? (string) $canonical_site['source_hash'] : '',
				'entry_path'  => isset($canonical_site['entry_path']) ? (string) $canonical_site['entry_path'] : '',
				'totals'      => isset($canonical_site['totals']) && is_array($canonical_site['totals']) ? $canonical_site['totals'] : array(),
			),
			static fn ( mixed $value ): bool => '' !== $value && array() !== $value
		);
	}

	/**
	 * Extract conservative shared region candidates from semantic region contracts.
	 *
	 * @param  array<int,array<string,mixed>> $regions Semantic region contracts.
	 * @return array<int,array<string,mixed>> Shared region contracts.
	 */
	private function shared_region_contracts( array $regions ): array {
		return array_values(
			array_filter(
				$regions,
				static fn ( array $region ): bool => count($region['source_paths']) > 1
			)
		);
	}

	/**
	 * Extract semantic region contracts from HTML documents.
	 *
	 * @param  array{files:array<int,array<string,mixed>>} $artifact Normalized artifact.
	 * @return array<int,array<string,mixed>> Region contracts grouped by role and source hash.
	 */
	private function semantic_region_contracts( array $artifact, array $canonical ): array {
		unset($artifact);
		$regions = $canonical['source_reports']['compiled_site']['regions'] ?? array();
		return is_array($regions) ? array_values(array_filter($regions, 'is_array')) : array();
	}

	/**
	 * Return theme-level CSS and script assets for downstream materializers.
	 *
	 * @param  array{files:array<int,array<string,mixed>>} $artifact       Normalized artifact.
	 * @param  array<string,mixed>                         $canonical_site Canonical compiled-site report.
	 * @return array<string,array<int,array<string,mixed>>> Theme asset contract.
	 */
	private function theme_asset_contracts( array $artifact, array $canonical_site = array() ): array {
		$assets = array(
			'styles'  => array(),
			'scripts' => array(),
		);
		$files_by_path = array();
		foreach ( $artifact['files'] as $file ) {
			$files_by_path[(string) $file['path']] = $file;
		}

		if ( isset($canonical_site['assets']) && is_array($canonical_site['assets']) ) {
			foreach ( $canonical_site['assets'] as $asset ) {
				if ( ! is_array($asset) ) {
					continue;
				}

				$kind = (string) ( $asset['kind'] ?? '' );
				$key  = 'css' === $kind ? 'styles' : ( 'js' === $kind ? 'scripts' : '' );
				if ( '' === $key ) {
					continue;
				}

				$path = (string) ( $asset['path'] ?? '' );
				$source_file = $files_by_path[ $path ] ?? array();
				$assets[ $key ][] = array(
					'path'       => $path,
					'role'       => (string) ( $asset['role'] ?? $source_file['role'] ?? '' ),
					'intent'     => (string) ( $asset['intent'] ?? $source_file['intent'] ?? '' ),
					'bytes'      => (int) ( $asset['bytes'] ?? $source_file['bytes'] ?? 0 ),
					'provenance' => isset($source_file['provenance']) && is_array($source_file['provenance']) ? $source_file['provenance'] : array(),
				);
			}

			return $assets;
		}

		foreach ( $artifact['files'] as $file ) {
			if ( 'css' === $file['kind'] ) {
				$assets['styles'][] = array(
					'path'       => $file['path'],
					'role'       => $file['role'],
					'intent'     => $file['intent'],
					'bytes'      => $file['bytes'],
					'provenance' => $file['provenance'],
				);
			}
			if ( 'js' === $file['kind'] ) {
				$assets['scripts'][] = array(
					'path'       => $file['path'],
					'role'       => $file['role'],
					'intent'     => $file['intent'],
					'bytes'      => $file['bytes'],
					'provenance' => $file['provenance'],
				);
			}
		}

		return $assets;
	}

	/**
	 * Compile canonical template-part artifacts from header/footer regions.
	 *
	 * @param  array{files:array<int,array<string,mixed>>} $artifact Normalized artifact.
	 * @param  string                                      $entry_path Entrypoint path.
	 * @param  array<string,mixed>                         $options  Conversion options.
	 * @return array<int,array<string,mixed>> Template part artifacts.
	 */
	private function template_part_artifacts( array $artifact, string $entry_path, array $options, array $canonical ): array {
		unset($artifact, $entry_path, $options);
		$canonical_parts = $canonical['source_reports']['compiled_site']['template_parts'] ?? array();
		if ( ! is_array($canonical_parts) ) {
			return array();
		}

		return array_values(array_map(
			static function ( array $part ): array {
				$source_path = (string) ( $part['source_path'] ?? '' );
				return array(
					'schema'       => 'block-artifact-compiler/template-part/v1',
					'slug'         => (string) ( $part['slug'] ?? '' ),
					'area'         => (string) ( $part['area'] ?? '' ),
					'source_paths' => '' === $source_path ? array() : array( $source_path ),
					'source_hash'  => (string) ( $part['provenance']['hash'] ?? '' ),
					'block_markup' => (string) ( $part['block_markup'] ?? '' ),
					'blocks'       => array(),
					'diagnostics'  => array(),
					'bfb_report'   => array( 'source' => 'blocks-engine' ),
				);
			},
			array_filter($canonical_parts, 'is_array')
		));
	}

	/**
	 * Merge entry, document, and template-part asset references.
	 *
	 * @param array<string,mixed>            $entry_conversion Entry conversion result.
	 * @param array<int,array<string,mixed>> $documents        Document artifacts.
	 * @param array<int,array<string,mixed>> $template_parts   Template part artifacts.
	 * @return array<int,array<string,mixed>> Asset references.
	 */
	private function compiled_asset_references( array $canonical, array $entry_conversion, array $documents, array $template_parts ): array {
		return $this->dedupe_reference_rows(array_merge(
			$this->canonical_asset_reference_rows($canonical),
			$this->reference_rows_from_conversion($entry_conversion, 'entry'),
			$this->reference_rows_from_artifacts($documents, 'document', 'asset_references'),
			$this->reference_rows_from_artifacts($template_parts, 'template_part', 'asset_references')
		));
	}

	/**
	 * Convert canonical ArtifactCompiler asset reference reports to BAC reference rows.
	 *
	 * @param array<string,mixed> $canonical Canonical Blocks Engine result.
	 * @return array<int,array<string,mixed>> Asset reference rows.
	 */
	private function canonical_asset_reference_rows( array $canonical ): array {
		$source_report = $this->canonical_artifact_source_report($canonical);
		$rows          = array();

		foreach ( array( 'asset_references', 'image_references' ) as $field ) {
			$references = isset($source_report[ $field ]) && is_array($source_report[ $field ]) ? $source_report[ $field ] : array();
			foreach ( $references as $reference ) {
				if ( ! is_array($reference) ) {
					continue;
				}

				$reference['scope'] = 'artifact';
				$reference['report'] = 'source_reports.artifact.' . $field;
				$rows[] = $reference;
			}
		}

		return $rows;
	}

	/**
	 * Merge entry, document, and template-part navigation candidates.
	 *
	 * @param array<string,mixed>            $entry_conversion Entry conversion result.
	 * @param array<int,array<string,mixed>> $documents        Document artifacts.
	 * @param array<int,array<string,mixed>> $template_parts   Template part artifacts.
	 * @return array<int,array<string,mixed>> Navigation candidates.
	 */
	private function compiled_navigation_candidates( array $entry_conversion, array $documents, array $template_parts ): array {
		return $this->dedupe_reference_rows(array_merge(
			$this->reference_rows_from_conversion($entry_conversion, 'entry', 'navigation_candidates'),
			$this->reference_rows_from_artifacts($documents, 'document', 'navigation_candidates'),
			$this->reference_rows_from_artifacts($template_parts, 'template_part', 'navigation_candidates')
		));
	}

	/**
	 * Merge entry, document, and template-part SVG/icon artifacts.
	 *
	 * @param array<string,mixed>            $entry_conversion Entry conversion result.
	 * @param array<int,array<string,mixed>> $documents        Document artifacts.
	 * @param array<int,array<string,mixed>> $template_parts   Template part artifacts.
	 * @return array<int,array<string,mixed>> SVG/icon artifacts.
	 */
	private function compiled_svg_icon_artifacts( array $entry_conversion, array $documents, array $template_parts ): array {
		return $this->dedupe_reference_rows(array_merge(
			$this->reference_rows_from_conversion($entry_conversion, 'entry', 'svg_icon_artifacts'),
			$this->reference_rows_from_artifacts($documents, 'document', 'svg_icon_artifacts'),
			$this->reference_rows_from_artifacts($template_parts, 'template_part', 'svg_icon_artifacts')
		));
	}

	/**
	 * Build compiler-owned visual repair artifacts from H2BC metadata and source CSS.
	 *
	 * @param array{files:array<int,array<string,mixed>>} $artifact       Normalized artifact.
	 * @param array<string,mixed>                         $entry_conversion Entry conversion result.
	 * @param array<int,array<string,mixed>>              $documents      Document artifacts.
	 * @param array<int,array<string,mixed>>              $template_parts Template part artifacts.
	 * @return array<string,mixed> Visual repair artifact envelope.
	 */
	private function visual_repair_artifacts( array $artifact, array $entry_conversion, array $documents, array $template_parts, array $canonical ): array {
		unset($artifact, $entry_conversion, $documents, $template_parts);
		$repair = $canonical['source_reports']['compiled_site']['visual_repair'] ?? array();
		if ( ! is_array($repair) ) {
			$repair = array();
		}

		$styles = array();
		if ( '' !== trim((string) ( $repair['css'] ?? '' )) ) {
			$styles[] = array(
				'schema'  => 'block-artifact-compiler/visual-repair-css/v1',
				'target'  => 'frontend',
				'path'    => 'assets/css/visual-repair.css',
				'content' => (string) $repair['css'],
			);
		}

		return array(
			'schema'   => 'block-artifact-compiler/visual-repair-artifacts/v1',
			'metadata' => array(
				'schema'      => 'blocks-engine/php-transformer/visual-repair/v1',
				'stylesheets' => isset($repair['stylesheets']) && is_array($repair['stylesheets']) ? $repair['stylesheets'] : array(),
			),
			'styles'   => $styles,
		);
	}

	/**
	 * Count records in visual repair categories for compact reports.
	 *
	 * @param array<string,mixed> $metadata Visual repair metadata.
	 * @return array<string,int> Category counts.
	 */
	private function visual_repair_category_counts( array $metadata ): array {
		$counts = array();
		$categories = isset($metadata['categories']) && is_array($metadata['categories']) ? $metadata['categories'] : array();
		foreach ( $categories as $category => $records ) {
			$counts[(string) $category] = is_array($records) ? count($records) : 0;
		}

		return $counts;
	}

	/**
	 * Build reference rows from one conversion result.
	 *
	 * @param array<string,mixed> $conversion Conversion result.
	 * @param string              $scope      Reference scope.
	 * @param string              $field      Field to read.
	 * @return array<int,array<string,mixed>> Reference rows.
	 */
	private function reference_rows_from_conversion( array $conversion, string $scope, string $field = 'asset_references' ): array {
		$items = isset($conversion[ $field ]) && is_array($conversion[ $field ]) ? $conversion[ $field ] : array();
		return array_map(
			static function ( array $item ) use ( $scope ): array {
				$item['scope'] = $scope;
				return $item;
			},
			$items
		);
	}

	/**
	 * Build reference rows from compiled artifacts.
	 *
	 * @param array<int,array<string,mixed>> $artifacts Artifacts.
	 * @param string                         $scope     Reference scope.
	 * @param string                         $field     Field to read.
	 * @return array<int,array<string,mixed>> Reference rows.
	 */
	private function reference_rows_from_artifacts( array $artifacts, string $scope, string $field ): array {
		$rows = array();
		foreach ( $artifacts as $artifact ) {
			$items = isset($artifact[ $field ]) && is_array($artifact[ $field ]) ? $artifact[ $field ] : array();
			foreach ( $items as $item ) {
				if ( ! is_array($item) ) {
					continue;
				}

				$item['scope']       = $scope;
				$item['source_path'] = (string) ( $artifact['source_path'] ?? $artifact['slug'] ?? '' );
				$rows[]              = $item;
			}
		}

		return $rows;
	}

	/**
	 * Dedupe reference rows by stable JSON payload.
	 *
	 * @param array<int,array<string,mixed>> $rows Reference rows.
	 * @return array<int,array<string,mixed>> Deduped rows.
	 */
	private function dedupe_reference_rows( array $rows ): array {
		$deduped = array();
		foreach ( $rows as $row ) {
			$encoded = bac_json_encode($row);
			$key     = false === $encoded ? hash('sha256', serialize($row)) : hash('sha256', $encoded);
			$deduped[ $key ] = $row;
		}

		return array_values($deduped);
	}

	/**
	 * Summarize source HTML structure and selector-critical tokens.
	 *
	 * @return array<string,mixed>
	 */
	private function html_structure_report( string $html ): array {
		$report = array(
			'bytes'              => strlen($html),
			'text_length'        => $this->plain_text_length($html),
			'element_count'      => 0,
			'id_count'           => 0,
			'class_count'        => 0,
			'unique_class_count' => 0,
			'tag_counts'         => array(),
			'top_classes'        => array(),
			'landmark_counts'    => array(),
			'max_depth'          => 0,
		);

		if ( '' === trim($html) || ! class_exists('DOMDocument') ) {
			return $report;
		}

		$doc      = new DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$loaded   = $doc->loadHTML('<!doctype html><html><body>' . $html . '</body></html>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		if ( ! $loaded ) {
			return $report;
		}

		$classes = array();
		$walk    = function ( $node, int $depth ) use ( &$walk, &$report, &$classes ): void {
			if ( $node instanceof DOMElement ) {
				$tag = strtolower($node->tagName);
				if ( ! in_array($tag, array( 'html', 'body' ), true) ) {
						++$report['element_count'];
						$report['max_depth']          = max( (int) $report['max_depth'], max(0, $depth - 2));
						$report['tag_counts'][ $tag ] = (int) ( $report['tag_counts'][ $tag ] ?? 0 ) + 1;
					if ( in_array($tag, array( 'header', 'nav', 'main', 'section', 'article', 'aside', 'footer' ), true) ) {
						$report['landmark_counts'][ $tag ] = (int) ( $report['landmark_counts'][ $tag ] ?? 0 ) + 1;
					}
					if ( '' !== trim($node->getAttribute('id')) ) {
						++$report['id_count'];
					}
					$class_attr = trim($node->getAttribute('class'));
					if ( '' !== $class_attr ) {
						$class_parts = preg_split('/\s+/', $class_attr);
						foreach ( false === $class_parts ? array() : $class_parts as $class ) {
							if ( '' === $class ) {
								continue;
							}
								++$report['class_count'];
								$classes[ $class ] = (int) ( $classes[ $class ] ?? 0 ) + 1;
						}
					}
				}
			}

			foreach ( $node->childNodes as $child ) {
				$walk($child, $depth + 1);
			}
		};
		$walk($doc, 0);

		arsort($classes);
		arsort($report['tag_counts']);
		$report['unique_class_count'] = count($classes);
		$report['top_classes']        = array_slice($classes, 0, 40, true);

		return $report;
	}

	/**
	 * Summarize source CSS selectors that can be sensitive to DOM wrapper changes.
	 *
	 * @param  array<int,array<string,mixed>> $files Normalized files.
	 * @return array<string,mixed>
	 */
	private function css_structure_report( array $files ): array {
		$report = array(
			'file_count'                  => 0,
			'bytes'                       => 0,
			'selector_count'              => 0,
			'direct_child_selector_count' => 0,
			'sibling_selector_count'      => 0,
			'pseudo_selector_count'       => 0,
			'class_selector_count'        => 0,
			'id_selector_count'           => 0,
			'layout_sensitive_selectors'  => array(),
		);

		foreach ( $files as $file ) {
			if ( 'css' !== ( $file['kind'] ?? '' ) || ! empty($file['binary']) ) {
				continue;
			}
			$css = (string) ( $file['content'] ?? '' );
			if ( '' === trim($css) ) {
				continue;
			}
			++$report['file_count'];
			$report['bytes'] += strlen($css);
			$css              = preg_replace('/\/\*.*?\*\//s', '', $css) ?? $css;
			if ( preg_match_all('/([^{}@][^{}]*)\{[^{}]*\}/', $css, $matches) ) {
				foreach ( $matches[1] as $selector_list ) {
					foreach ( explode(',', (string) $selector_list) as $selector ) {
						$selector = trim(preg_replace('/\s+/', ' ', $selector) ?? $selector);
						if ( '' === $selector ) {
							continue;
						}
						++$report['selector_count'];
						$direct                          = str_contains($selector, '>');
						$sibling                         = str_contains($selector, '+') || str_contains($selector, '~');
						$pseudo                          = str_contains($selector, ':');
						$report['class_selector_count'] += substr_count($selector, '.');
						$report['id_selector_count']    += substr_count($selector, '#');
						if ( $direct ) {
							++$report['direct_child_selector_count'];
						}
						if ( $sibling ) {
							++$report['sibling_selector_count'];
						}
						if ( $pseudo ) {
							++$report['pseudo_selector_count'];
						}
						if ( $direct || $sibling || $pseudo ) {
							$report['layout_sensitive_selectors'][] = $selector;
						}
					}
				}
			}
		}

		$report['layout_sensitive_selectors'] = array_slice(array_values(array_unique($report['layout_sensitive_selectors'])), 0, 80);

		return $report;
	}

	/**
	 * Summarize the generated block tree without embedding the full serialized body.
	 *
	 * @param  array<int,array<string,mixed>> $blocks Parsed blocks.
	 * @return array<string,mixed>
	 */
	private function block_tree_report( array $blocks, string $serialized_blocks ): array {
		$report = array(
			'bytes'              => strlen($serialized_blocks),
			'text_length'        => $this->plain_text_length($serialized_blocks),
			'block_count'        => 0,
			'max_depth'          => 0,
			'block_name_counts'  => array(),
			'class_count'        => 0,
			'unique_class_count' => 0,
			'top_classes'        => array(),
		);

		$classes = array();
		$walk    = function ( array $items, int $depth ) use ( &$walk, &$report, &$classes ): void {
			foreach ( $items as $block ) {
				if ( ! is_array($block) ) {
						continue;
				}
				$name = (string) ( $block['blockName'] ?? '' );
				if ( '' !== $name ) {
					++$report['block_count'];
					$report['max_depth']                  = max( (int) $report['max_depth'], $depth);
					$report['block_name_counts'][ $name ] = (int) ( $report['block_name_counts'][ $name ] ?? 0 ) + 1;
				}
				$class_attr  = isset($block['attrs']['className']) ? (string) $block['attrs']['className'] : '';
				$class_parts = preg_split('/\s+/', trim($class_attr));
				foreach ( false === $class_parts ? array() : $class_parts as $class ) {
					if ( '' === $class ) {
						continue;
					}
					++$report['class_count'];
					$classes[ $class ] = (int) ( $classes[ $class ] ?? 0 ) + 1;
				}
				if ( ! empty($block['innerBlocks']) && is_array($block['innerBlocks']) ) {
					$walk($block['innerBlocks'], $depth + 1);
				}
			}
		};
		$walk($blocks, 1);

		arsort($classes);
		arsort($report['block_name_counts']);
		$report['unique_class_count'] = count($classes);
		$report['top_classes']        = array_slice($classes, 0, 40, true);

		return $report;
	}

	/**
	 * Prefer canonical block tree reports when ArtifactCompiler publishes them.
	 *
	 * @param array<string,mixed>            $canonical         Canonical Blocks Engine result.
	 * @param array<int,array<string,mixed>> $blocks            Parsed blocks.
	 * @param string                         $serialized_blocks Serialized block markup.
	 * @return array<string,mixed> Block tree report.
	 */
	private function compiled_block_tree_report( array $canonical, array $blocks, string $serialized_blocks ): array {
		$report = $canonical['source_reports']['block_tree'] ?? array();
		if ( is_array($report) && ! empty($report) ) {
			return $report;
		}

		return $this->block_tree_report($blocks, $serialized_blocks);
	}

	/**
	 * Return a WordPress-compatible plain-text length in and out of WordPress.
	 */
	private function plain_text_length( string $html ): int {
		if ( function_exists('wp_strip_all_tags') ) {
			return strlen(trim(wp_strip_all_tags($html)));
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Used only when WordPress is not loaded.
		return strlen(trim(strip_tags($html)));
	}

	/**
	 * Compile Markdown and MDX content documents into WordPress-shaped artifacts.
	 *
	 * @param  array{files:array<int,array<string,mixed>>} $artifact Normalized artifact.
	 * @param  array<string,mixed>                         $options  Compiler options.
	 * @return array{documents:array<int,array<string,mixed>>,components:array<int,array<string,mixed>>,diagnostics:array<int,array<string,mixed>>}
	 */
	private function compile_source_documents( array $artifact, array $options, array $canonical ): array {
		unset($options);
		$documents = array();
		$route_root = $this->site_route_root($artifact);
		$entry_path = (string) ( $canonical['source_reports']['artifact']['entry_path'] ?? '' );
		foreach ( $canonical['documents'] ?? array() as $document ) {
			if ( is_array($document) ) {
				$documents[] = $this->canonical_document_to_bac_document($document, $route_root, $entry_path);
			}
		}

		foreach ( $canonical['source_reports']['compiled_site']['pages'] ?? array() as $page ) {
			if ( ! is_array($page) || 'html' !== ( $page['kind'] ?? '' ) ) {
				continue;
			}
			$documents[] = $this->canonical_document_to_bac_document($page, $route_root, $entry_path);
		}

		return array(
			'documents'   => $documents,
			'components'  => array(),
			'diagnostics' => array(),
		);
	}

	/**
	 * Project a canonical Blocks Engine page/document into BAC's legacy document shape.
	 *
	 * @param array<string,mixed> $document Canonical document or compiled-site page.
	 * @return array<string,mixed> BAC-compatible document artifact.
	 */
	private function canonical_document_to_bac_document( array $document, string $route_root, string $entry_path ): array {
		$source_path = (string) ( $document['source_path'] ?? '' );
		$title       = (string) ( $document['title'] ?? $this->title_from_path($source_path) );
		$slug        = (string) ( $document['slug'] ?? $this->slug_from_path($source_path) );
		$is_entrypoint = '' !== $entry_path && $source_path === $entry_path;
		$explicit_slug = ( ! $is_entrypoint && ! $this->is_index_source_path($source_path) ) ? $slug : '';
		$route       = $this->page_identity_from_source($source_path, $route_root, $is_entrypoint, $explicit_slug, $title);

		return array_merge(
			$route,
			array(
				'source_path'       => $source_path,
				'kind'              => (string) ( $document['kind'] ?? 'document' ),
				'post_type'         => (string) ( $document['post_type'] ?? $document['metadata']['post_type'] ?? 'page' ),
				'post_status'       => 'publish',
				'status'            => 'publish',
				'slug'              => (string) $route['canonical_slug'],
				'title'             => $title,
				'excerpt'           => (string) ( $document['excerpt'] ?? $document['metadata']['excerpt'] ?? '' ),
				'date'              => (string) ( $document['date'] ?? $document['metadata']['date'] ?? '' ),
				'template'          => (string) ( $document['template'] ?? $document['metadata']['template'] ?? '' ),
				'taxonomies'        => isset($document['taxonomies']) && is_array($document['taxonomies']) ? $document['taxonomies'] : array(),
				'frontmatter'       => isset($document['frontmatter']) && is_array($document['frontmatter']) ? $document['frontmatter'] : array(),
				'entrypoint'        => $is_entrypoint,
				'metadata'          => isset($document['metadata']) && is_array($document['metadata']) ? $document['metadata'] : array(),
				'document_metadata' => isset($document['metadata']) && is_array($document['metadata']) ? $document['metadata'] : array(),
				'block_markup'      => (string) ( $document['block_markup'] ?? '' ),
				'blocks'            => array(),
				'bfb_report'        => array( 'source' => 'blocks-engine' ),
				'asset_references'  => isset($document['asset_references']) && is_array($document['asset_references']) ? $document['asset_references'] : array(),
				'svg_icon_artifacts' => array(),
				'navigation_candidates' => array(),
				'visual_repair_metadata' => array(),
				'selector_provenance' => array(),
				'diagnostics'       => isset($document['diagnostics']) && is_array($document['diagnostics']) ? $document['diagnostics'] : array(),
				'provenance'        => isset($document['provenance']) && is_array($document['provenance']) ? $document['provenance'] : array(),
			)
		);
	}

	/**
	 * Detect generated WordPress plugin artifact bundles without making install policy decisions.
	 *
	 * @param  array{files:array<int,array<string,mixed>>} $artifact    Normalized artifact.
	 * @param  array<int,array<string,mixed>>              $block_types Generated block type artifacts.
	 * @return array<int,array<string,mixed>> Plugin artifacts.
	 */
	private function build_plugin_artifacts( array $artifact, array $block_types, array $canonical ): array {
		unset($artifact, $block_types);
		$plugins = $canonical['source_reports']['compiled_site']['plugins'] ?? array();
		return is_array($plugins) ? array_values(array_filter($plugins, 'is_array')) : array();
	}

	/**
	 * Build downstream-facing requirements detected from the generated bundle.
	 *
	 * @param  string                         $block_markup Serialized block markup.
	 * @param  array<int,array<string,mixed>> $block_types  Generated block type artifacts.
	 * @param  array<int,array<string,mixed>> $plugins      Generated plugin artifacts.
	 * @return array<string,array<int,array<string,mixed>>> Requirement contract.
	 */
	private function build_artifact_requirements( string $block_markup, array $block_types, array $plugins, array $canonical ): array {
		unset($block_markup, $block_types, $plugins);
		$requirements = $canonical['source_reports']['compiled_site']['requirements'] ?? array();
		return is_array($requirements) ? $requirements : array();
	}

	/**
	 * Normalize a relative artifact path and reject unsafe locations.
	 */
	private function safe_relative_path( string $path ): string {
		$path = str_replace('\\', '/', trim($path));
		$path = preg_replace('/\0+/', '', $path);
		$path = ltrim( (string) $path);
		if ( '' === $path || str_starts_with($path, '/') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $path) ) {
			return '';
		}

		$segments = array();
		foreach ( explode('/', $path) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				return '';
			}
			$segments[] = preg_replace('/[^A-Za-z0-9._-]/', '-', $segment);
		}

		return implode('/', array_filter($segments));
	}

	/**
	 * Normalize content from scalar-ish inputs.
	 *
	 * @param mixed $content Raw content.
	 */
	private function normalize_content( mixed $content ): string {
		if ( is_scalar($content) || null === $content ) {
			return (string) $content;
		}

		$encoded = bac_json_encode($content, JSON_UNESCAPED_SLASHES);
		return is_string($encoded) ? $encoded : '';
	}

	/**
	 * Normalize file payloads from text or base64 content fields.
	 *
	 * @param  array<string,mixed> $file Raw file entry.
	 * @return array{accepted:bool,content:string,content_base64:string,encoding:string,binary:bool,bytes:int,diagnostics:array<int,array<string,mixed>>}
	 */
	private function normalize_file_payload( array $file, string $path ): array {
		$diagnostics = array();
		if ( isset($file['content_base64']) && is_string($file['content_base64']) ) {
			$base64 = preg_replace('/\s+/', '', $file['content_base64']) ?? '';
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes explicit artifact file payloads, not executable code.
			$decoded = base64_decode($base64, true);
			if ( false === $decoded ) {
				return array(
					'accepted'       => false,
					'content'        => '',
					'content_base64' => '',
					'encoding'       => 'base64',
					'binary'         => false,
					'bytes'          => 0,
					'diagnostics'    => array( $this->diagnostic('invalid_base64_content', 'warning', 'An artifact file was ignored because content_base64 is not valid base64.', array( 'path' => $path )) ),
				);
			}

			$is_binary = $this->looks_binary($decoded);
			if ( ! $is_binary && isset($file['content']) && is_string($file['content']) && '' !== $file['content'] && $file['content'] !== $decoded ) {
				$diagnostics[] = $this->diagnostic('content_base64_preferred', 'info', 'Both content and content_base64 were provided; decoded content_base64 was used as the canonical payload.', array( 'path' => $path ));
			}

			return array(
				'accepted'       => true,
				'content'        => $is_binary ? '' : $decoded,
				'content_base64' => $base64,
				'encoding'       => 'base64',
				'binary'         => $is_binary,
				'bytes'          => strlen($decoded),
				'diagnostics'    => $diagnostics,
			);
		}

		$content = $this->normalize_content($file['content'] ?? $file['body'] ?? $file['text'] ?? '');
		return array(
			'accepted'       => true,
			'content'        => $content,
			'content_base64' => '',
			'encoding'       => 'text',
			'binary'         => false,
			'bytes'          => strlen($content),
			'diagnostics'    => array(),
		);
	}

	/**
	 * Normalize file kind from explicit kind, path, and content.
	 */
	private function normalize_kind( string $kind, string $path, string $content, string $mime_type = '' ): string {
		$kind = bac_sanitize_key($kind);
		if ( in_array($kind, array( 'html', 'css', 'js', 'jsx', 'tsx', 'php', 'json', 'markdown', 'mdx', 'asset', 'blocks' ), true) ) {
			return $kind;
		}
		if ( str_contains($mime_type, '/') ) {
			if ( str_contains($mime_type, 'html') ) {
				return 'html';
			}
			if ( 'text/css' === $mime_type ) {
				return 'css';
			}
			if ( in_array($mime_type, array( 'application/javascript', 'text/javascript', 'application/ecmascript', 'text/ecmascript' ), true) ) {
				return 'js';
			}
			if ( 'application/json' === $mime_type ) {
				return 'json';
			}
		}

		$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		return match ( $extension ) {
			'html', 'htm'       => 'html',
			'css'               => 'css',
			'js', 'mjs'          => 'js',
			'jsx'                => 'jsx',
			'tsx'                => 'tsx',
			'php'                => 'php',
			'json'              => 'json',
			'md', 'markdown'    => 'markdown',
			'mdx'               => 'mdx',
			default             => str_contains($content, '<!-- wp:') ? 'blocks' : 'asset',
		};
	}

	/**
	 * Normalize or infer a MIME type.
	 */
	private function normalize_mime_type( string $mime_type, string $path ): string {
		$mime_type = strtolower(trim($mime_type));
		if ( preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#', $mime_type) ) {
			return $mime_type;
		}

		return match ( strtolower(pathinfo($path, PATHINFO_EXTENSION)) ) {
			'html', 'htm'       => 'text/html',
			'css'               => 'text/css',
			'js', 'mjs'          => 'application/javascript',
			'jsx'               => 'text/jsx',
			'tsx'               => 'text/tsx',
			'php'               => 'application/x-httpd-php',
			'json'              => 'application/json',
			'md', 'markdown'    => 'text/markdown',
			'mdx'               => 'text/mdx',
			'txt'               => 'text/plain',
			'svg'               => 'image/svg+xml',
			'png'               => 'image/png',
			'jpg', 'jpeg'       => 'image/jpeg',
			'gif'               => 'image/gif',
			'webp'              => 'image/webp',
			'avif'              => 'image/avif',
			'woff'              => 'font/woff',
			'woff2'             => 'font/woff2',
			'ttf'               => 'font/ttf',
			'otf'               => 'font/otf',
			default             => 'application/octet-stream',
		};
	}

	/**
	 * Normalize a file role without making policy decisions about generated output.
	 */
	private function normalize_role( string $role, string $kind, string $mime_type, string $path ): string {
		$role = bac_sanitize_key($role);
		if ( '' !== $role ) {
			return $role;
		}

		if ( 'html' === $kind ) {
			return preg_match('#(^|/)index\.html?$#i', $path) ? 'entry' : 'document';
		}
		if ( 'css' === $kind ) {
			return 'stylesheet';
		}
		if ( 'js' === $kind ) {
			return 'script';
		}
		if ( str_starts_with($mime_type, 'image/') ) {
			return 'image';
		}
		if ( str_starts_with($mime_type, 'font/') ) {
			return 'font';
		}
		if ( in_array($kind, array( 'json', 'markdown' ), true) ) {
			return 'data';
		}

		return 'asset';
	}

	/**
	 * Normalize CSS/JS intent metadata.
	 */
	private function normalize_intent( string $intent, string $kind, string $role ): string {
		$intent = bac_sanitize_key($intent);
		if ( '' !== $intent ) {
			return $intent;
		}
		if ( 'css' === $kind || 'stylesheet' === $role ) {
			return 'style';
		}
		if ( 'js' === $kind || 'script' === $role ) {
			return 'behavior';
		}

		return '';
	}

	/**
	 * Detect binary payloads conservatively.
	 */
	private function looks_binary( string $content ): bool {
		return str_contains($content, "\0");
	}

	/**
	 * Return whether a MIME type should be treated as binary in result manifests.
	 */
	private function is_binary_mime_type( string $mime_type ): bool {
		if ( str_starts_with($mime_type, 'text/') ) {
			return false;
		}

		return ! in_array($mime_type, array( 'application/json', 'application/javascript', 'application/x-httpd-php', 'image/svg+xml' ), true);
	}

	/**
	 * Build a virtual path from an arbitrary fragment source label.
	 */
	private function virtual_fragment_path( string $source, string $format ): string {
		$path      = $this->safe_relative_path(str_replace(array( ':', '#' ), '-', $source));
		$extension = match ( $this->normalize_fragment_format($format) ) {
			'css'      => 'css',
			'js'       => 'js',
			'markdown' => 'md',
			'mdx'      => 'mdx',
			'blocks'   => 'blocks.html',
			default    => 'html',
		};

		return ( '' === $path ? 'fragment' : preg_replace('/\.[A-Za-z0-9]+$/', '', $path) ) . '.' . $extension;
	}

	/**
	 * Normalize public fragment source formats to BFB-facing format keys.
	 */
	private function normalize_fragment_format( string $format ): string {
		$format = bac_sanitize_key($format);
		return match ( $format ) {
			'htm', 'html'      => 'html',
			'md', 'markdown'   => 'markdown',
			'wp-blocks', 'block-markup', 'blocks' => 'blocks',
			'mdx'              => 'mdx',
			'css'              => 'css',
			'js', 'javascript' => 'js',
			default            => 'html',
		};
	}

	/**
	 * Dedupe normalized paths deterministically.
	 *
	 * @param array<string,bool> $seen Seen paths.
	 */
	private function dedupe_path( string $path, array $seen ): string {
		if ( ! isset($seen[ $path ]) ) {
			return $path;
		}

		$extension = pathinfo($path, PATHINFO_EXTENSION);
		$base      = '' === $extension ? $path : substr($path, 0, -1 - strlen($extension));
		$suffix    = '' === $extension ? '' : '.' . $extension;
		$index     = 2;
		while ( isset($seen[ $base . '-' . $index . $suffix ]) ) {
			++$index;
		}

		return $base . '-' . $index . $suffix;
	}

	/**
	 * Count normalized files by kind.
	 *
	 * @param  array<int,array<string,mixed>> $files Files.
	 * @return array<string,int>
	 */
	private function count_files_by_kind( array $files ): array {
		return $this->count_files_by_field($files, 'kind');
	}

	/**
	 * Count normalized files by a manifest field.
	 *
	 * @param  array<int,array<string,mixed>> $files Files.
	 * @return array<string,int>
	 */
	private function count_files_by_field( array $files, string $field ): array {
		$counts = array();
		foreach ( $files as $file ) {
			$value = isset($file[ $field ]) ? (string) $file[ $field ] : '';
			if ( '' === $value ) {
				continue;
			}
			$counts[ $value ] = ( $counts[ $value ] ?? 0 ) + 1;
		}
		ksort($counts);

		return $counts;
	}

	/**
	 * Split simple YAML frontmatter from a content document.
	 *
	 * @return array{frontmatter:array<string,mixed>,body:string}
	 */
	private function parse_frontmatter( string $content ): array {
		if ( ! preg_match('/\A---\s*\R(.*?)\R---\s*\R?/s', $content, $matches) ) {
			return array(
				'frontmatter' => array(),
				'body'        => $content,
			);
		}

		$frontmatter       = array();
		$frontmatter_lines = preg_split('/\R/', trim($matches[1]));
		foreach ( false === $frontmatter_lines ? array() : $frontmatter_lines as $line ) {
			if ( ! preg_match('/^([A-Za-z0-9_-]+)\s*:\s*(.*)$/', $line, $pair) ) {
				continue;
			}

			$value = trim($pair[2]);
			$value = trim($value, " \t\n\r\0\x0B\"'");
			if ( preg_match('/^\[(.*)\]$/', $value, $list) ) {
				$value = array_values(array_filter(array_map(static fn ( string $item ): string => trim($item, " \t\n\r\0\x0B\"'"), explode(',', $list[1])), static fn ( string $item ): bool => '' !== $item));
			}

			$frontmatter[ bac_sanitize_key($pair[1]) ] = $value;
		}

		return array(
			'frontmatter' => $frontmatter,
			'body'        => substr($content, strlen($matches[0])),
		);
	}

	/**
	 * Extract conservative MDX/JSX component candidates while producing Markdown-compatible text.
	 *
	 * @param  array<string,mixed>                         $file     Source file.
	 * @param  array{files:array<int,array<string,mixed>>} $artifact Normalized artifact.
	 * @return array{markdown_body:string,components:array<int,array<string,mixed>>,diagnostics:array<int,array<string,mixed>>}
	 */
	private function extract_mdx_semantics( string $body, array $file, array $artifact ): array {
		$imports     = $this->extract_mdx_imports($body);
		$components  = array();
		$diagnostics = array(
			$this->diagnostic(
				'mdx_candidate_extraction_only',
				'info',
				'MDX/JSX handling is conservative component candidate extraction; BAC does not evaluate MDX runtime semantics.',
				array( 'path' => $file['path'] )
			),
		);

		if ( preg_match_all('/<([A-Z][A-Za-z0-9._-]*)(?:\s[^>]*)?\s*(?:>|\/>)/', $body, $matches) ) {
			foreach ( $matches[1] as $name ) {
				$import    = $imports[ $name ] ?? null;
				$resolved  = is_array($import) ? $this->resolve_component_import( (string) $import['path'], (string) $file['path'], $artifact) : '';
				$component = array(
					'name'        => $name,
					'source'      => $file['path'],
					'signal'      => 'mdx-jsx',
					'occurrences' => ( $components[ $name ]['occurrences'] ?? 0 ) + 1,
					'provenance'  => array( 'source_path' => $file['path'] ),
				);

				if ( is_array($import) ) {
					$component['import_path'] = $import['path'];
				}
				if ( '' !== $resolved ) {
					$component['resolved_path'] = $resolved;
				}

				$components[ $name ] = $component;

				if ( ! is_array($import) ) {
					$diagnostics[] = $this->diagnostic(
						'mdx_component_unresolved',
						'warning',
						'MDX component candidate has no matching import.',
						array(
							'path'      => $file['path'],
							'component' => $name,
						)
					);
				} elseif ( '' === $resolved && str_starts_with( (string) $import['path'], '.') ) {
					$diagnostics[] = $this->diagnostic(
						'mdx_import_unresolved',
						'warning',
						'MDX component candidate import could not be linked to a generated source file.',
						array(
							'path'        => $file['path'],
							'component'   => $name,
							'import_path' => $import['path'],
						)
					);
				}
			}
		}

		$markdown_body = preg_replace('/^\s*import\s+[^;\r\n]+;?\s*$/m', '', $body) ?? $body;
		$markdown_body = preg_replace('/^\s*export\s+[^\r\n]+\s*$/m', '', $markdown_body) ?? $markdown_body;
		$markdown_body = preg_replace('/<([A-Z][A-Za-z0-9._-]*)(?:\s[^>]*)?\s*\/>/', '', $markdown_body) ?? $markdown_body;
		$markdown_body = preg_replace('/<\/?[A-Z][A-Za-z0-9._-]*(?:\s[^>]*)?>/', '', $markdown_body) ?? $markdown_body;

		return array(
			'markdown_body' => trim($markdown_body),
			'components'    => array_values($components),
			'diagnostics'   => $this->dedupe_diagnostics($diagnostics),
		);
	}

	/**
	 * Extract default and named import aliases from simple MDX import lines.
	 *
	 * @return array<string,array{path:string}>
	 */
	private function extract_mdx_imports( string $body ): array {
		$imports = array();
		if ( ! preg_match_all('/^\s*import\s+(.+?)\s+from\s+["\']([^"\']+)["\'];?\s*$/m', $body, $matches, PREG_SET_ORDER) ) {
			return $imports;
		}

		foreach ( $matches as $match ) {
			$clause = trim($match[1]);
			$path   = $match[2];
			if ( preg_match('/^([A-Z][A-Za-z0-9_]*)/', $clause, $default) ) {
				$imports[ $default[1] ] = array( 'path' => $path );
			}
			if ( preg_match('/\{([^}]+)\}/', $clause, $named) ) {
				foreach ( explode(',', $named[1]) as $name ) {
					$parts = preg_split('/\s+as\s+/i', trim($name));
					$parts = false === $parts ? array() : $parts;
					$alias = trim( (string) end($parts));
					if ( preg_match('/^[A-Z][A-Za-z0-9_]*$/', $alias) ) {
						$imports[ $alias ] = array( 'path' => $path );
					}
				}
			}
		}

		return $imports;
	}

	/**
	 * Detect top-level component declarations in generated JSX/TSX files.
	 *
	 * @param  array<string,mixed> $file Normalized file.
	 * @return array<int,array<string,mixed>> Component candidates.
	 */
	private function detect_jsx_file_components( array $file ): array {
		$components = array();
		$content    = (string) ( $file['content'] ?? '' );

		if ( preg_match_all('/(?:export\s+default\s+)?function\s+([A-Z][A-Za-z0-9_]*)\s*\(/', $content, $matches) ) {
			foreach ( $matches[1] as $name ) {
				$components[ $name ] = true;
			}
		}

		if ( preg_match_all('/(?:export\s+)?(?:const|let|var)\s+([A-Z][A-Za-z0-9_]*)\s*=\s*(?:\([^)]*\)|[A-Za-z0-9_]+)\s*=>/', $content, $matches) ) {
			foreach ( $matches[1] as $name ) {
				$components[ $name ] = true;
			}
		}

		return array_map(
			fn ( string $name ): array => array(
				'name'        => $name,
				'source'      => (string) ( $file['path'] ?? '' ),
				'signal'      => 'jsx-component-file',
				'occurrences' => 1,
				'provenance'  => array( 'source_path' => (string) ( $file['path'] ?? '' ) ),
			),
			array_keys($components)
		);
	}

	/**
	 * Resolve a relative component import to a generated source file path when present.
	 *
	 * @param array{files:array<int,array<string,mixed>>} $artifact Normalized artifact.
	 */
	private function resolve_component_import( string $import_path, string $source_path, array $artifact ): string {
		if ( ! str_starts_with($import_path, '.') ) {
			return '';
		}

		$base = dirname($source_path);
		$path = $this->normalize_relative_import_path(( '.' === $base ? '' : $base . '/' ) . $import_path);
		if ( '' === $path ) {
			return '';
		}

		$candidates = array( $path );
		foreach ( array( 'js', 'jsx', 'ts', 'tsx', 'mdx' ) as $extension ) {
			$candidates[] = $path . '.' . $extension;
			$candidates[] = $path . '/index.' . $extension;
		}

		foreach ( $artifact['files'] as $file ) {
			if ( in_array($file['path'], $candidates, true) ) {
				return (string) $file['path'];
			}
		}

		return '';
	}

	/**
	 * Normalize a relative import path after joining it to the importing file.
	 */
	private function normalize_relative_import_path( string $path ): string {
		$segments = array();
		foreach ( explode('/', str_replace('\\', '/', $path)) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				array_pop($segments);
				continue;
			}
			$segments[] = preg_replace('/[^A-Za-z0-9._-]/', '-', $segment);
		}

		return implode('/', array_filter($segments));
	}

	/**
	 * Read a scalar frontmatter value with aliases.
	 *
	 * @param array<string,mixed> $frontmatter Frontmatter map.
	 * @param array<int,string>   $keys        Keys in priority order.
	 */
	private function frontmatter_string( array $frontmatter, array $keys, string $fallback ): string {
		foreach ( $keys as $key ) {
			if ( isset($frontmatter[ $key ]) && is_scalar($frontmatter[ $key ]) && '' !== trim( (string) $frontmatter[ $key ]) ) {
				return (string) $frontmatter[ $key ];
			}
		}

		return $fallback;
	}

	/**
	 * Extract common taxonomy hints from frontmatter.
	 *
	 * @param  array<string,mixed> $frontmatter Frontmatter map.
	 * @return array<string,mixed>
	 */
	private function frontmatter_taxonomies( array $frontmatter ): array {
		$taxonomies = array();
		foreach ( array( 'category', 'categories', 'tag', 'tags' ) as $key ) {
			if ( isset($frontmatter[ $key ]) ) {
				$taxonomies[ $key ] = $frontmatter[ $key ];
			}
		}

		return $taxonomies;
	}

	/**
	 * Infer the artifact route root from an explicit index entrypoint.
	 *
	 * @param array{entrypoints?:array<int,string>} $artifact Normalized artifact.
	 */
	private function site_route_root( array $artifact ): string {
		$entrypoints = isset($artifact['declared_entrypoints']) && is_array($artifact['declared_entrypoints']) ? $artifact['declared_entrypoints'] : array();
		foreach ( $entrypoints as $entrypoint ) {
			$entrypoint = trim((string) $entrypoint, '/');
			if ( '' === $entrypoint || ! $this->is_index_source_path($entrypoint) ) {
				continue;
			}

			$root = trim(dirname($entrypoint), './');
			return '.' === $root ? '' : $root;
		}

		return '';
	}

	/**
	 * Build canonical page identity and route/link rewrite data for a source document.
	 *
	 * @return array<string,mixed>
	 */
	private function page_identity_from_source( string $source_path, string $route_root, bool $is_entrypoint, string $explicit_slug = '', string $title = '' ): array {
		$source_path   = trim($source_path, '/');
		$relative_path = $this->route_relative_path($source_path, $route_root);
		$route_path    = $this->canonical_route_path($relative_path);
		$front_page    = $is_entrypoint || '' === $route_path;
		$slug          = '' !== trim($explicit_slug) ? bac_sanitize_key(str_replace(array( '/', '_' ), '-', $explicit_slug)) : $this->canonical_slug_from_route_path($route_path, $front_page);
		$permalink     = '' === $route_path ? '/' : '/' . $route_path . '/';
		$route_key     = '' === $route_path ? '/' : $route_path;
		$route_keys    = $this->route_keys_for_source($source_path, $relative_path, $route_path);

		return array(
			'canonical_slug'       => $slug,
			'route_key'            => $route_key,
			'route_keys'           => $route_keys,
			'route_path'           => $route_path,
			'permalink_path'       => $permalink,
			'link_rewrite_keys'    => $route_keys,
			'link_rewrite_target'  => $permalink,
			'front_page'           => $front_page,
			'route'                => array(
				'source_path'         => $source_path,
				'relative_path'       => $relative_path,
				'route_key'           => $route_key,
				'route_keys'          => $route_keys,
				'path'                => $route_path,
				'permalink_path'      => $permalink,
				'link_rewrite_target' => $permalink,
			),
			'page_identity'        => array(
				'slug'       => $slug,
				'title'      => $title,
				'front_page' => $front_page,
			),
		);
	}

	/**
	 * Strip the artifact route root from a source path when applicable.
	 */
	private function route_relative_path( string $source_path, string $route_root ): string {
		$source_path = trim($source_path, '/');
		$route_root  = trim($route_root, '/');
		if ( '' !== $route_root && ( $source_path === $route_root || str_starts_with($source_path, $route_root . '/') ) ) {
			return ltrim(substr($source_path, strlen($route_root)), '/');
		}

		return $source_path;
	}

	/**
	 * Build the materializer-neutral route path for a source-relative document.
	 */
	private function canonical_route_path( string $relative_path ): string {
		$extensionless = preg_replace('/\.(?:html?|md|markdown|mdx)$/i', '', trim($relative_path, '/'));
		$extensionless = trim((string) ( '' === (string) $extensionless ? $relative_path : $extensionless ), '/');

		if ( preg_match('#(^|/)index$#i', $extensionless) ) {
			$extensionless = preg_replace('#(^|/)index$#i', '$1', $extensionless);
		}

		return trim((string) $extensionless, '/');
	}

	/**
	 * Build a WordPress-safe slug from a canonical route path.
	 */
	private function canonical_slug_from_route_path( string $route_path, bool $front_page ): string {
		if ( $front_page || '' === trim($route_path) ) {
			return 'home';
		}

		$slug = bac_sanitize_key(str_replace(array( '/', '_' ), '-', trim($route_path, '/')));
		return '' === $slug ? 'document' : $slug;
	}

	/**
	 * Check whether a path is an index source document.
	 */
	private function is_index_source_path( string $path ): bool {
		return in_array(strtolower(basename($path)), array( 'index.html', 'index.htm', 'index.md', 'index.markdown', 'index.mdx' ), true);
	}

	/**
	 * Build stable route/link keys for source href resolution.
	 *
	 * @return array<int,string>
	 */
	private function route_keys_for_source( string $source_path, string $relative_path, string $route_path ): array {
		$keys = array();
		foreach ( array( $source_path, $relative_path ) as $path ) {
			$path = trim($path, '/');
			if ( '' === $path ) {
				continue;
			}

			$keys[]        = $path;
			$keys[]        = '/' . $path;
			$keys[]        = './' . $path;
			$extensionless = preg_replace('/\.(?:html?|md|markdown|mdx)$/i', '', $path);
			$extensionless = trim((string) ( '' === (string) $extensionless ? $path : $extensionless ), '/');

			foreach ( array( 'html', 'htm', 'md', 'markdown', 'mdx' ) as $extension ) {
				$keys[] = $extensionless . '.' . $extension;
				$keys[] = '/' . $extensionless . '.' . $extension;
			}
		}

		if ( '' === $route_path ) {
			$keys[] = '/';
		} else {
			$keys[] = $route_path;
			$keys[] = '/' . $route_path;
			$keys[] = $route_path . '/';
			$keys[] = '/' . $route_path . '/';
		}

		return array_values(array_unique(array_filter($keys, static fn ( string $key ): bool => '' !== trim($key))));
	}

	/**
	 * Build a stable slug from a source path.
	 */
	private function slug_from_path( string $path ): string {
		$base = preg_replace('/\.[A-Za-z0-9]+$/', '', basename($path));
		$base = '' === $base || null === $base ? 'document' : $base;
		return bac_sanitize_key(str_replace(array( '_', '.' ), '-', $base));
	}

	/**
	 * Build a readable fallback title from a source path.
	 */
	private function title_from_path( string $path ): string {
		return ucwords(str_replace('-', ' ', $this->slug_from_path($path)));
	}

	/**
	 * Build a normalized diagnostic entry.
	 *
	 * @param  array<string,mixed> $details Diagnostic details.
	 * @return array<string,mixed>
	 */
	private function diagnostic( string $code, string $severity, string $message, array $details = array() ): array {
		$diagnostic = array(
			'code'     => $code,
			'severity' => $severity,
			'message'  => $message,
		);
		if ( ! empty($details) ) {
			$diagnostic['details'] = $details;
		}

		return $diagnostic;
	}

	/**
	 * Remove duplicate diagnostics emitted while rejecting many files.
	 *
	 * @param  array<int,array<string,mixed>> $diagnostics Diagnostics.
	 * @return array<int,array<string,mixed>> Diagnostics.
	 */
	private function dedupe_diagnostics( array $diagnostics ): array {
		$deduped = array();
		$seen    = array();
		foreach ( $diagnostics as $diagnostic ) {
			$details_json = bac_json_encode($diagnostic['details'] ?? array());
			$key          = (string) ( $diagnostic['code'] ?? '' ) . '|' . md5(false === $details_json ? '' : $details_json);
			if ( isset($seen[ $key ]) ) {
				continue;
			}
			$seen[ $key ] = true;
			$deduped[]    = $diagnostic;
		}

		return $deduped;
	}

	/**
	 * Resolve result status from diagnostics.
	 *
	 * @param array<int,array<string,mixed>> $diagnostics Diagnostics.
	 */
	private function status_from_diagnostics( array $diagnostics ): string {
		foreach ( $diagnostics as $diagnostic ) {
			if ( 'error' === ( $diagnostic['severity'] ?? '' ) ) {
				return 'failed';
			}
		}

		foreach ( $diagnostics as $diagnostic ) {
			if ( 'warning' === ( $diagnostic['severity'] ?? '' ) ) {
				return 'success_with_warnings';
			}
		}

		return 'success';
	}

	/**
	 * Build a stable hash payload for provenance.
	 *
	 * @param array{files:array<int,array<string,mixed>>} $artifact Normalized artifact.
	 */
	private function artifact_hash_payload( array $artifact ): string {
		return $this->file_hash_payload($artifact['files']);
	}

	/**
	 * Build a stable hash payload from normalized files.
	 *
	 * @param array<int,array<string,mixed>> $files Files.
	 */
	private function file_hash_payload( array $files ): string {
		$payload = '';
		foreach ( $files as $file ) {
			$content  = isset($file['content_base64']) ? (string) $file['content_base64'] : (string) $file['content'];
			$payload .= $file['path'] . "\0" . $file['kind'] . "\0" . ( $file['mime_type'] ?? '' ) . "\0" . $content . "\0";
		}

		return $payload;
	}
}

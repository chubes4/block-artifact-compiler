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

	private const RESULT_SCHEMA = 'chubes4/block-artifact-compiler-result/v1';
	private const INPUT_SCHEMA  = 'chubes4/website-artifact/v1';

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
		$normalized  = $this->normalize_artifact( $artifact, $options );
		$documents   = $this->compile_source_documents( $normalized, $options );
		$entry       = $this->entry_file( $normalized );
		$html        = is_array( $entry ) ? $entry['content'] : '';
		$entry_path  = is_array( $entry ) ? $entry['path'] : '';
		$diagnostics = array_merge( $normalized['diagnostics'], $documents['diagnostics'] );

		if ( '' === trim( $html ) && empty( $documents['documents'] ) ) {
			$diagnostics[] = $this->diagnostic( 'missing_entry_html', 'error', 'No HTML entry file was available to compile.' );
		}

		$conversion = '' !== trim( $html ) ? $this->convert_html_to_blocks( $html, $options ) : array(
			'serialized_blocks' => '',
			'blocks'            => array(),
			'diagnostics'       => array(),
			'report'            => array(),
		);

		$diagnostics = array_merge( $diagnostics, $conversion['diagnostics'] );
		$components  = $this->detect_components( $normalized, $entry_path, $documents['components'] );
		$files       = $this->wordpress_files_from_artifact( $normalized );
		if ( '' === trim( $html ) && ! empty( $documents['documents'][0]['block_markup'] ) ) {
			$conversion['serialized_blocks'] = (string) $documents['documents'][0]['block_markup'];
		}

		return array(
			'schema'              => self::RESULT_SCHEMA,
			'status'              => $this->status_from_diagnostics( $diagnostics ),
			'input'               => array(
				'schema'          => self::INPUT_SCHEMA,
				'entry_path'      => $entry_path,
				'file_count'      => count( $normalized['files'] ),
				'accepted_count'  => count( $normalized['files'] ),
				'rejected_count'  => $normalized['rejected_count'],
				'bytes'           => $normalized['bytes'],
				'files_by_kind'   => $this->count_files_by_kind( $normalized['files'] ),
				'original_schema' => (string) ( $artifact['schema'] ?? '' ),
			),
			'wordpress_artifacts' => array(
				'block_markup' => $conversion['serialized_blocks'],
				'blocks'       => $conversion['blocks'],
				'block_types'  => array(),
				'components'   => $components,
				'documents'    => $documents['documents'],
				'files'        => $files,
			),
			'provenance'          => array(
				'source_hash' => hash( 'sha256', $this->artifact_hash_payload( $normalized ) ),
				'source'      => $entry_path,
			),
			'diagnostics'         => $diagnostics,
			'bfb_report'          => $conversion['report'],
		);
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
		$path = $this->virtual_fragment_path( $source, $format );

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
		$artifacts   = isset( $compiled['wordpress_artifacts'] ) && is_array( $compiled['wordpress_artifacts'] ) ? $compiled['wordpress_artifacts'] : array();
		$block_types = isset( $artifacts['block_types'] ) && is_array( $artifacts['block_types'] ) ? $artifacts['block_types'] : array();
		$components  = isset( $artifacts['components'] ) && is_array( $artifacts['components'] ) ? $artifacts['components'] : array();
		$files       = isset( $artifacts['files'] ) && is_array( $artifacts['files'] ) ? $artifacts['files'] : array();
		$diagnostics = isset( $compiled['diagnostics'] ) && is_array( $compiled['diagnostics'] ) ? $compiled['diagnostics'] : array();

		return array(
			'schema'           => isset( $compiled['schema'] ) ? (string) $compiled['schema'] : '',
			'status'           => isset( $compiled['status'] ) ? (string) $compiled['status'] : '',
			'source'           => isset( $compiled['provenance']['source'] ) ? (string) $compiled['provenance']['source'] : '',
			'block_type_count' => count( $block_types ),
			'component_count'  => count( $components ),
			'file_count'       => count( $files ),
			'diagnostic_count' => count( $diagnostics ),
		);
	}

	/**
	 * Normalize supported website artifact input shapes.
	 *
	 * @param  array<string,mixed> $artifact Raw artifact.
	 * @param  array<string,mixed> $options  Compiler options.
	 * @return array{files:array<int,array{path:string,content:string,kind:string,bytes:int,source:string,mime_type:string,provenance:array<string,mixed>}>,diagnostics:array<int,array<string,mixed>>,rejected_count:int,bytes:int}
	 */
	private function normalize_artifact( array $artifact, array $options ): array {
		$limits      = array(
			'max_files'       => max( 1, (int) ( $options['max_files'] ?? self::DEFAULT_MAX_FILES ) ),
			'max_file_bytes'  => max( 1, (int) ( $options['max_file_bytes'] ?? self::DEFAULT_MAX_FILE_BYTES ) ),
			'max_total_bytes' => max( 1, (int) ( $options['max_total_bytes'] ?? self::DEFAULT_MAX_TOTAL_BYTES ) ),
		);
		$raw_files   = $this->extract_raw_files( $artifact );
		$files       = array();
		$diagnostics = array();
		$total_bytes = 0;
		$rejected    = 0;
		$seen_paths  = array();

		foreach ( $raw_files as $index => $file ) {
			if ( count( $files ) >= $limits['max_files'] ) {
				++$rejected;
				$diagnostics[] = $this->diagnostic( 'file_limit_exceeded', 'warning', 'Additional artifact files were ignored because the file limit was reached.', array( 'max_files' => $limits['max_files'] ) );
				break;
			}

			$path = $this->safe_relative_path( (string) ( $file['path'] ?? '' ) );
			if ( '' === $path ) {
				++$rejected;
				$diagnostics[] = $this->diagnostic( 'unsafe_artifact_path', 'warning', 'An artifact file was ignored because its path is empty, absolute, or escapes the artifact root.', array( 'index' => $index ) );
				continue;
			}

			$content = $this->normalize_content( $file['content'] ?? '' );
			$bytes   = strlen( $content );
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

			$deduped_path                = $this->dedupe_path( $path, $seen_paths );
			$seen_paths[ $deduped_path ] = true;
			$total_bytes                += $bytes;

			$kind    = $this->normalize_kind( (string) ( $file['kind'] ?? '' ), $deduped_path, $content );
			$files[] = array(
				'path'       => $deduped_path,
				'content'    => $content,
				'kind'       => $kind,
				'mime_type'  => $this->mime_type_for_kind( $kind ),
				'bytes'      => $bytes,
				'source'     => (string) ( $file['source'] ?? 'artifact' ),
				'provenance' => array(
					'source_path' => $deduped_path,
					'source'      => (string) ( $file['source'] ?? 'artifact' ),
					'hash'        => hash( 'sha256', $content ),
				),
			);

			if ( 'mdx' === $kind ) {
				$diagnostics[] = $this->diagnostic( 'mdx_source_document_detected', 'warning', 'MDX source document support is partial; BAC preserved the source and extracted inspectable document/component metadata.', array( 'path' => $deduped_path ) );
			}
		}

		return array(
			'files'          => $files,
			'diagnostics'    => $this->dedupe_diagnostics( $diagnostics ),
			'rejected_count' => $rejected,
			'bytes'          => $total_bytes,
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
			if ( isset( $artifact[ $key ] ) && is_array( $artifact[ $key ] ) ) {
				$files = array_merge( $files, $this->normalize_file_collection( $artifact[ $key ], $key ) );
			}
		}

		foreach ( array( 'html', 'generated_html', 'content', 'body' ) as $key ) {
			if ( isset( $artifact[ $key ] ) && is_string( $artifact[ $key ] ) && '' !== trim( $artifact[ $key ] ) ) {
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
			if ( isset( $artifact[ $key ] ) && is_string( $artifact[ $key ] ) && '' !== trim( $artifact[ $key ] ) ) {
				$files[] = array(
					'path'    => $path,
					'content' => $artifact[ $key ],
					'kind'    => str_contains( $path, '.css' ) ? 'css' : 'js',
					'source'  => $key,
				);
			}
		}

		return $files;
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
			if ( is_array( $file ) ) {
				$path    = (string) ( $file['path'] ?? $file['name'] ?? $key );
				$files[] = array(
					'path'    => $path,
					'content' => $file['content'] ?? $file['body'] ?? $file['text'] ?? '',
					'kind'    => $file['kind'] ?? $file['type'] ?? '',
					'source'  => $source,
				);
				continue;
			}

			if ( is_string( $file ) ) {
				$path    = is_string( $key ) ? $key : 'artifact-' . (string) $key . '.html';
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
	 * @param  array{files:array<int,array{path:string,content:string,kind:string,bytes:int,source:string,mime_type:string,provenance:array<string,mixed>}>} $artifact Normalized artifact.
	 * @return array{path:string,content:string,kind:string,bytes:int,source:string}|null
	 */
	private function entry_file( array $artifact ): ?array {
		$preferred = array( 'index.html', 'index.htm', 'static-site/index.html', 'public/index.html' );
		foreach ( $preferred as $path ) {
			foreach ( $artifact['files'] as $file ) {
				if ( strtolower( $file['path'] ) === $path ) {
					return $file;
				}
			}
		}

		foreach ( $artifact['files'] as $file ) {
			if ( 'html' === $file['kind'] ) {
				return $file;
			}
		}

		return null;
	}

	/**
	 * Convert HTML to block markup through BFB/H2BC when available.
	 *
	 * @param  string              $html    Source HTML.
	 * @param  array<string,mixed> $options Compiler options.
	 * @return array{serialized_blocks:string,blocks:array,diagnostics:array<int,array<string,mixed>>,report:array<string,mixed>}
	 */
	private function convert_html_to_blocks( string $html, array $options ): array {
		if ( str_contains( $html, '<!-- wp:' ) && function_exists( 'parse_blocks' ) && function_exists( 'serialize_blocks' ) ) {
			$blocks = parse_blocks( $html );
			return array(
				'serialized_blocks' => serialize_blocks( $blocks ),
				'blocks'            => $blocks,
				'diagnostics'       => array(),
				'report'            => array(
					'status' => 'success_native',
					'source' => 'blocks',
				),
			);
		}

		if ( function_exists( 'bfb_convert' ) ) {
			$block_markup = (string) bfb_convert( $html, 'html', 'blocks', $options );
			$report       = array( 'status' => '' === trim( $block_markup ) ? 'failed' : 'success_native' );
			if ( ! empty( $options['include_bfb_report'] ) && function_exists( 'bfb_conversion_report' ) ) {
				$report = bfb_conversion_report( $html, 'html', $options );
			}

			return array(
				'serialized_blocks' => $block_markup,
				'blocks'            => function_exists( 'parse_blocks' ) && '' !== trim( $block_markup ) ? parse_blocks( $block_markup ) : array(),
				'diagnostics'       => isset( $report['diagnostics'] ) && is_array( $report['diagnostics'] ) ? $report['diagnostics'] : array(),
				'report'            => $report,
			);
		}

		return array(
			'serialized_blocks' => '<!-- wp:html -->' . "\n" . $html . "\n" . '<!-- /wp:html -->',
			'blocks'            => array(),
			'diagnostics'       => array(
				$this->diagnostic( 'bfb_unavailable', 'warning', 'BFB is unavailable; preserved source HTML as a core/html fallback.' ),
			),
			'report'            => array( 'status' => 'success_with_fallbacks' ),
		);
	}

	/**
	 * Compile Markdown and MDX content documents into WordPress-shaped artifacts.
	 *
	 * @param  array{files:array<int,array{path:string,content:string,kind:string,bytes:int,source:string,mime_type:string,provenance:array<string,mixed>}>} $artifact Normalized artifact.
	 * @param  array<string,mixed>                                                                                                                           $options  Compiler options.
	 * @return array{documents:array<int,array<string,mixed>>,components:array<int,array<string,mixed>>,diagnostics:array<int,array<string,mixed>>}
	 */
	private function compile_source_documents( array $artifact, array $options ): array {
		$documents   = array();
		$components  = array();
		$diagnostics = array();

		foreach ( $artifact['files'] as $file ) {
			if ( ! in_array( $file['kind'], array( 'markdown', 'mdx' ), true ) ) {
				continue;
			}

			$parsed               = $this->parse_frontmatter( $file['content'] );
			$body                 = $parsed['body'];
			$frontmatter          = $parsed['frontmatter'];
			$document_diagnostics = array();

			if ( 'mdx' === $file['kind'] ) {
				$mdx                  = $this->extract_mdx_semantics( $body, $file, $artifact );
				$body                 = $mdx['markdown_body'];
				$components           = array_merge( $components, $mdx['components'] );
				$document_diagnostics = array_merge( $document_diagnostics, $mdx['diagnostics'] );
			}

			$conversion           = $this->convert_markdown_to_blocks( $body, $options );
			$document_diagnostics = array_merge( $document_diagnostics, $conversion['diagnostics'] );
			$diagnostics          = array_merge( $diagnostics, $document_diagnostics );

			$documents[] = array(
				'source_path'  => $file['path'],
				'kind'         => $file['kind'],
				'post_type'    => $this->frontmatter_string( $frontmatter, array( 'post_type', 'type' ), 'page' ),
				'slug'         => $this->frontmatter_string( $frontmatter, array( 'slug' ), $this->slug_from_path( $file['path'] ) ),
				'title'        => $this->frontmatter_string( $frontmatter, array( 'title' ), $this->title_from_path( $file['path'] ) ),
				'excerpt'      => $this->frontmatter_string( $frontmatter, array( 'excerpt', 'description' ), '' ),
				'date'         => $this->frontmatter_string( $frontmatter, array( 'date', 'published', 'published_at' ), '' ),
				'template'     => $this->frontmatter_string( $frontmatter, array( 'template', 'layout' ), '' ),
				'taxonomies'   => $this->frontmatter_taxonomies( $frontmatter ),
				'frontmatter'  => $frontmatter,
				'block_markup' => $conversion['serialized_blocks'],
				'diagnostics'  => $document_diagnostics,
				'provenance'   => $file['provenance'],
			);
		}

		return array(
			'documents'   => $documents,
			'components'  => $components,
			'diagnostics' => $this->dedupe_diagnostics( $diagnostics ),
		);
	}

	/**
	 * Convert Markdown through BFB when present, otherwise preserve it in a block fallback.
	 *
	 * @param  array<string,mixed> $options Compiler options.
	 * @return array{serialized_blocks:string,blocks:array,diagnostics:array<int,array<string,mixed>>,report:array<string,mixed>}
	 */
	private function convert_markdown_to_blocks( string $markdown, array $options ): array {
		if ( function_exists( 'bfb_convert' ) ) {
			$block_markup = (string) bfb_convert( $markdown, 'markdown', 'blocks', $options );
			return array(
				'serialized_blocks' => $block_markup,
				'blocks'            => function_exists( 'parse_blocks' ) && '' !== trim( $block_markup ) ? parse_blocks( $block_markup ) : array(),
				'diagnostics'       => array(),
				'report'            => array(
					'status' => '' === trim( $block_markup ) ? 'failed' : 'success_native',
					'source' => 'markdown',
				),
			);
		}

		return array(
			'serialized_blocks' => '<!-- wp:html -->' . "\n" . $markdown . "\n" . '<!-- /wp:html -->',
			'blocks'            => array(),
			'diagnostics'       => array(
				$this->diagnostic( 'bfb_unavailable', 'warning', 'BFB is unavailable; preserved source Markdown as a core/html fallback.' ),
			),
			'report'            => array(
				'status' => 'success_with_fallbacks',
				'source' => 'markdown',
			),
		);
	}

	/**
	 * Build component candidates from explicit markers and repeated class tokens.
	 *
	 * @param  array{files:array<int,array{path:string,content:string,kind:string,bytes:int,source:string}>} $artifact   Normalized artifact.
	 * @param  string                                                                                        $entry_path Entry path.
	 * @return array<int,array<string,mixed>> Component candidates.
	 */
	private function detect_components( array $artifact, string $entry_path, array $source_document_components = array() ): array {
		$candidates = array();
		$classes    = array();

		foreach ( $source_document_components as $component ) {
			$key                = 'mdx:' . (string) ( $component['source'] ?? '' ) . ':' . (string) ( $component['name'] ?? '' );
			$candidates[ $key ] = $component;
		}

		foreach ( $artifact['files'] as $file ) {
			if ( in_array( $file['kind'], array( 'jsx', 'tsx' ), true ) ) {
				foreach ( $this->detect_jsx_file_components( $file ) as $component ) {
					$candidates[ 'jsx-file:' . (string) $component['source'] . ':' . (string) $component['name'] ] = $component;
				}
			}

			if ( 'html' !== $file['kind'] ) {
				continue;
			}

			if ( preg_match_all( '/data-component\s*=\s*(["\'])([^"\']+)\1/i', $file['content'], $matches ) ) {
				foreach ( $matches[2] as $name ) {
					$key = sanitize_key( $name );
					if ( '' !== $key ) {
						$candidates[ 'explicit:' . $key ] = array(
							'name'        => $key,
							'source'      => $file['path'],
							'signal'      => 'data-component',
							'occurrences' => ( $candidates[ 'explicit:' . $key ]['occurrences'] ?? 0 ) + 1,
						);
					}
				}
			}

			if ( preg_match_all( '/class\s*=\s*(["\'])([^"\']+)\1/i', $file['content'], $matches ) ) {
				foreach ( $matches[2] as $class_list ) {
					$class_tokens = preg_split( '/\s+/', trim( $class_list ) );
					foreach ( false === $class_tokens ? array() : $class_tokens as $class ) {
						$class = sanitize_key( $class );
						if ( '' === $class || strlen( $class ) < 3 ) {
							continue;
						}
						$classes[ $class ] = ( $classes[ $class ] ?? 0 ) + 1;
					}
				}
			}
		}

		foreach ( $classes as $class => $count ) {
			if ( $count < 2 && ! preg_match( '/(?:card|grid|hero|nav|header|footer|feature|testimonial|pricing|product|gallery|section)/', $class ) ) {
				continue;
			}

			$candidates[ 'class:' . $class ] = array(
				'name'        => $class,
				'source'      => $entry_path,
				'signal'      => 'class-token',
				'occurrences' => $count,
			);
		}

		usort(
			$candidates,
			static function ( array $left, array $right ): int {
				$occurrence_comparison = $right['occurrences'] <=> $left['occurrences'];
				return 0 !== $occurrence_comparison ? $occurrence_comparison : strcmp( (string) $left['name'], (string) $right['name'] );
			}
		);

		return array_slice( $candidates, 0, 25 );
	}

	/**
	 * Return non-entry files that SSI or another materializer may consume later.
	 *
	 * @param  array{files:array<int,array{path:string,content:string,kind:string,bytes:int,source:string,mime_type:string,provenance:array<string,mixed>}>} $artifact Normalized artifact.
	 * @return array<int,array<string,mixed>> Files.
	 */
	private function wordpress_files_from_artifact( array $artifact ): array {
		$files = array();
		foreach ( $artifact['files'] as $file ) {
			if ( 'html' === $file['kind'] ) {
				continue;
			}

			$files[] = array(
				'path'       => $file['path'],
				'kind'       => $file['kind'],
				'mime_type'  => $file['mime_type'],
				'bytes'      => $file['bytes'],
				'content'    => $file['content'],
				'provenance' => $file['provenance'],
			);
		}

		return $files;
	}

	/**
	 * Normalize a relative artifact path and reject unsafe locations.
	 */
	private function safe_relative_path( string $path ): string {
		$path = str_replace( '\\', '/', trim( $path ) );
		$path = preg_replace( '/\0+/', '', $path );
		$path = ltrim( (string) $path );
		if ( '' === $path || str_starts_with( $path, '/' ) || preg_match( '#^[a-z][a-z0-9+.-]*:#i', $path ) ) {
			return '';
		}

		$segments = array();
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				return '';
			}
			$segments[] = preg_replace( '/[^A-Za-z0-9._-]/', '-', $segment );
		}

		return implode( '/', array_filter( $segments ) );
	}

	/**
	 * Normalize content from scalar-ish inputs.
	 *
	 * @param mixed $content Raw content.
	 */
	private function normalize_content( mixed $content ): string {
		if ( is_scalar( $content ) || null === $content ) {
			return (string) $content;
		}

		$encoded = wp_json_encode( $content, JSON_UNESCAPED_SLASHES );
		return is_string( $encoded ) ? $encoded : '';
	}

	/**
	 * Normalize file kind from explicit kind, path, and content.
	 */
	private function normalize_kind( string $kind, string $path, string $content ): string {
		$kind = sanitize_key( $kind );
		if ( in_array( $kind, array( 'html', 'css', 'js', 'jsx', 'tsx', 'json', 'markdown', 'mdx', 'asset', 'blocks' ), true ) ) {
			return $kind;
		}

		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		return match ( $extension ) {
			'html', 'htm'       => 'html',
			'css'               => 'css',
			'js', 'mjs'          => 'js',
			'jsx'                => 'jsx',
			'tsx'                => 'tsx',
			'json'              => 'json',
			'md', 'markdown'    => 'markdown',
			'mdx'               => 'mdx',
			default             => str_contains( $content, '<!-- wp:' ) ? 'blocks' : 'asset',
		};
	}

	/**
	 * Return BAC-local MIME values for normalized file kinds.
	 */
	private function mime_type_for_kind( string $kind ): string {
		return match ( $kind ) {
			'html'     => 'text/html',
			'css'      => 'text/css',
			'js'       => 'text/javascript',
			'jsx'      => 'text/jsx',
			'tsx'      => 'text/tsx',
			'json'     => 'application/json',
			'markdown' => 'text/markdown',
			'mdx'      => 'text/mdx',
			'blocks'   => 'text/x-wordpress-blocks',
			default    => 'application/octet-stream',
		};
	}

	/**
	 * Build a virtual path from an arbitrary fragment source label.
	 */
	private function virtual_fragment_path( string $source, string $format ): string {
		$path      = $this->safe_relative_path( str_replace( array( ':', '#' ), '-', $source ) );
		$extension = match ( sanitize_key( $format ) ) {
			'css'      => 'css',
			'js'       => 'js',
			'markdown' => 'md',
			'mdx'      => 'mdx',
			default    => 'html',
		};

		return ( '' === $path ? 'fragment' : preg_replace( '/\.[A-Za-z0-9]+$/', '', $path ) ) . '.' . $extension;
	}

	/**
	 * Dedupe normalized paths deterministically.
	 *
	 * @param array<string,bool> $seen Seen paths.
	 */
	private function dedupe_path( string $path, array $seen ): string {
		if ( ! isset( $seen[ $path ] ) ) {
			return $path;
		}

		$extension = pathinfo( $path, PATHINFO_EXTENSION );
		$base      = '' === $extension ? $path : substr( $path, 0, -1 - strlen( $extension ) );
		$suffix    = '' === $extension ? '' : '.' . $extension;
		$index     = 2;
		while ( isset( $seen[ $base . '-' . $index . $suffix ] ) ) {
			++$index;
		}

		return $base . '-' . $index . $suffix;
	}

	/**
	 * Count normalized files by kind.
	 *
	 * @param  array<int,array{kind:string}> $files Files.
	 * @return array<string,int>
	 */
	private function count_files_by_kind( array $files ): array {
		$counts = array();
		foreach ( $files as $file ) {
			$counts[ $file['kind'] ] = ( $counts[ $file['kind'] ] ?? 0 ) + 1;
		}
		ksort( $counts );

		return $counts;
	}

	/**
	 * Split simple YAML frontmatter from a content document.
	 *
	 * @return array{frontmatter:array<string,mixed>,body:string}
	 */
	private function parse_frontmatter( string $content ): array {
		if ( ! preg_match( '/\A---\s*\R(.*?)\R---\s*\R?/s', $content, $matches ) ) {
			return array(
				'frontmatter' => array(),
				'body'        => $content,
			);
		}

		$frontmatter       = array();
		$frontmatter_lines = preg_split( '/\R/', trim( $matches[1] ) );
		foreach ( false === $frontmatter_lines ? array() : $frontmatter_lines as $line ) {
			if ( ! preg_match( '/^([A-Za-z0-9_-]+)\s*:\s*(.*)$/', $line, $pair ) ) {
				continue;
			}

			$value = trim( $pair[2] );
			$value = trim( $value, " \t\n\r\0\x0B\"'" );
			if ( preg_match( '/^\[(.*)\]$/', $value, $list ) ) {
				$value = array_values( array_filter( array_map( static fn ( string $item ): string => trim( $item, " \t\n\r\0\x0B\"'" ), explode( ',', $list[1] ) ), static fn ( string $item ): bool => '' !== $item ) );
			}

			$frontmatter[ sanitize_key( $pair[1] ) ] = $value;
		}

		return array(
			'frontmatter' => $frontmatter,
			'body'        => substr( $content, strlen( $matches[0] ) ),
		);
	}

	/**
	 * Extract MDX imports and JSX component references while producing Markdown-compatible text.
	 *
	 * @param  array<string,mixed>                         $file     Source file.
	 * @param  array{files:array<int,array<string,mixed>>} $artifact Normalized artifact.
	 * @return array{markdown_body:string,components:array<int,array<string,mixed>>,diagnostics:array<int,array<string,mixed>>}
	 */
	private function extract_mdx_semantics( string $body, array $file, array $artifact ): array {
		$imports     = $this->extract_mdx_imports( $body );
		$components  = array();
		$diagnostics = array();

		if ( preg_match_all( '/<([A-Z][A-Za-z0-9._-]*)(?:\s[^>]*)?\s*(?:>|\/>)/', $body, $matches ) ) {
			foreach ( $matches[1] as $name ) {
				$import    = $imports[ $name ] ?? null;
				$resolved  = is_array( $import ) ? $this->resolve_component_import( (string) $import['path'], (string) $file['path'], $artifact ) : '';
				$component = array(
					'name'        => $name,
					'source'      => $file['path'],
					'signal'      => 'mdx-jsx',
					'occurrences' => ( $components[ $name ]['occurrences'] ?? 0 ) + 1,
					'provenance'  => array( 'source_path' => $file['path'] ),
				);

				if ( is_array( $import ) ) {
					$component['import_path'] = $import['path'];
				}
				if ( '' !== $resolved ) {
					$component['resolved_path'] = $resolved;
				}

				$components[ $name ] = $component;

				if ( ! is_array( $import ) ) {
					$diagnostics[] = $this->diagnostic(
						'mdx_component_unresolved',
						'warning',
						'MDX component reference has no matching import.',
						array(
							'path'      => $file['path'],
							'component' => $name,
						)
					);
				} elseif ( '' === $resolved && str_starts_with( (string) $import['path'], '.' ) ) {
					$diagnostics[] = $this->diagnostic(
						'mdx_import_unresolved',
						'warning',
						'MDX component import could not be linked to a generated source file.',
						array(
							'path'        => $file['path'],
							'component'   => $name,
							'import_path' => $import['path'],
						)
					);
				}
			}
		}

		$markdown_body = preg_replace( '/^\s*import\s+[^;\r\n]+;?\s*$/m', '', $body ) ?? $body;
		$markdown_body = preg_replace( '/^\s*export\s+[^\r\n]+\s*$/m', '', $markdown_body ) ?? $markdown_body;
		$markdown_body = preg_replace( '/<([A-Z][A-Za-z0-9._-]*)(?:\s[^>]*)?\s*\/>/', '', $markdown_body ) ?? $markdown_body;
		$markdown_body = preg_replace( '/<\/?[A-Z][A-Za-z0-9._-]*(?:\s[^>]*)?>/', '', $markdown_body ) ?? $markdown_body;

		return array(
			'markdown_body' => trim( $markdown_body ),
			'components'    => array_values( $components ),
			'diagnostics'   => $this->dedupe_diagnostics( $diagnostics ),
		);
	}

	/**
	 * Extract default and named import aliases from simple MDX import lines.
	 *
	 * @return array<string,array{path:string}>
	 */
	private function extract_mdx_imports( string $body ): array {
		$imports = array();
		if ( ! preg_match_all( '/^\s*import\s+(.+?)\s+from\s+["\']([^"\']+)["\'];?\s*$/m', $body, $matches, PREG_SET_ORDER ) ) {
			return $imports;
		}

		foreach ( $matches as $match ) {
			$clause = trim( $match[1] );
			$path   = $match[2];
			if ( preg_match( '/^([A-Z][A-Za-z0-9_]*)/', $clause, $default ) ) {
				$imports[ $default[1] ] = array( 'path' => $path );
			}
			if ( preg_match( '/\{([^}]+)\}/', $clause, $named ) ) {
				foreach ( explode( ',', $named[1] ) as $name ) {
					$parts = preg_split( '/\s+as\s+/i', trim( $name ) );
					$parts = false === $parts ? array() : $parts;
					$alias = trim( (string) end( $parts ) );
					if ( preg_match( '/^[A-Z][A-Za-z0-9_]*$/', $alias ) ) {
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

		if ( preg_match_all( '/(?:export\s+default\s+)?function\s+([A-Z][A-Za-z0-9_]*)\s*\(/', $content, $matches ) ) {
			foreach ( $matches[1] as $name ) {
				$components[ $name ] = true;
			}
		}

		if ( preg_match_all( '/(?:export\s+)?(?:const|let|var)\s+([A-Z][A-Za-z0-9_]*)\s*=\s*(?:\([^)]*\)|[A-Za-z0-9_]+)\s*=>/', $content, $matches ) ) {
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
			array_keys( $components )
		);
	}

	/**
	 * Resolve a relative component import to a generated source file path when present.
	 *
	 * @param array{files:array<int,array<string,mixed>>} $artifact Normalized artifact.
	 */
	private function resolve_component_import( string $import_path, string $source_path, array $artifact ): string {
		if ( ! str_starts_with( $import_path, '.' ) ) {
			return '';
		}

		$base = dirname( $source_path );
		$path = $this->normalize_relative_import_path( ( '.' === $base ? '' : $base . '/' ) . $import_path );
		if ( '' === $path ) {
			return '';
		}

		$candidates = array( $path );
		foreach ( array( 'js', 'jsx', 'ts', 'tsx', 'mdx' ) as $extension ) {
			$candidates[] = $path . '.' . $extension;
			$candidates[] = $path . '/index.' . $extension;
		}

		foreach ( $artifact['files'] as $file ) {
			if ( in_array( $file['path'], $candidates, true ) ) {
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
		foreach ( explode( '/', str_replace( '\\', '/', $path ) ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				array_pop( $segments );
				continue;
			}
			$segments[] = preg_replace( '/[^A-Za-z0-9._-]/', '-', $segment );
		}

		return implode( '/', array_filter( $segments ) );
	}

	/**
	 * Read a scalar frontmatter value with aliases.
	 *
	 * @param array<string,mixed> $frontmatter Frontmatter map.
	 * @param array<int,string>   $keys        Keys in priority order.
	 */
	private function frontmatter_string( array $frontmatter, array $keys, string $fallback ): string {
		foreach ( $keys as $key ) {
			if ( isset( $frontmatter[ $key ] ) && is_scalar( $frontmatter[ $key ] ) && '' !== trim( (string) $frontmatter[ $key ] ) ) {
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
			if ( isset( $frontmatter[ $key ] ) ) {
				$taxonomies[ $key ] = $frontmatter[ $key ];
			}
		}

		return $taxonomies;
	}

	/**
	 * Build a stable slug from a source path.
	 */
	private function slug_from_path( string $path ): string {
		$base = preg_replace( '/\.[A-Za-z0-9]+$/', '', basename( $path ) );
		$base = '' === $base || null === $base ? 'document' : $base;
		return sanitize_key( str_replace( array( '_', '.' ), '-', $base ) );
	}

	/**
	 * Build a readable fallback title from a source path.
	 */
	private function title_from_path( string $path ): string {
		return ucwords( str_replace( '-', ' ', $this->slug_from_path( $path ) ) );
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
		if ( ! empty( $details ) ) {
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
			$details_json = wp_json_encode( $diagnostic['details'] ?? array() );
			$key          = (string) ( $diagnostic['code'] ?? '' ) . '|' . md5( false === $details_json ? '' : $details_json );
			if ( isset( $seen[ $key ] ) ) {
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
	 * @param array{files:array<int,array{path:string,content:string,kind:string,bytes:int,source:string}>} $artifact Normalized artifact.
	 */
	private function artifact_hash_payload( array $artifact ): string {
		$payload = '';
		foreach ( $artifact['files'] as $file ) {
			$payload .= $file['path'] . "\0" . $file['kind'] . "\0" . $file['content'] . "\0";
		}

		return $payload;
	}
}

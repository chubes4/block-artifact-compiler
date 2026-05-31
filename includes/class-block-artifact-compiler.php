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

	private const DEFAULT_MAX_FILES      = 200;
	private const DEFAULT_MAX_FILE_BYTES = 2097152;
	private const DEFAULT_MAX_TOTAL_BYTES = 10485760;

	/**
	 * Compile a website artifact bundle.
	 *
	 * @param array<string,mixed> $artifact Website artifact input.
	 * @param array<string,mixed> $options  Compiler options.
	 * @return array<string,mixed> Compiler result envelope.
	 */
	public function compile( array $artifact, array $options = array() ): array {
		$normalized  = $this->normalize_artifact( $artifact, $options );
		$entry       = $this->entry_file( $normalized );
		$html        = is_array( $entry ) ? $entry['content'] : '';
		$entry_path  = is_array( $entry ) ? $entry['path'] : '';
		$diagnostics = $normalized['diagnostics'];

		if ( '' === trim( $html ) ) {
			$diagnostics[] = $this->diagnostic( 'missing_entry_html', 'error', 'No HTML entry file was available to compile.' );
		}

		$conversion = '' !== trim( $html ) ? $this->convert_html_to_blocks( $html, $options ) : array(
			'serialized_blocks' => '',
			'blocks'            => array(),
			'diagnostics'       => array(),
			'report'            => array(),
		);

		$diagnostics = array_merge( $diagnostics, $conversion['diagnostics'] );
		$components  = $this->detect_components( $normalized, $entry_path );
		$files       = $this->wordpress_files_from_artifact( $normalized );

		return array(
			'schema'              => self::RESULT_SCHEMA,
			'status'              => $this->status_from_diagnostics( $diagnostics ),
			'input'               => array(
				'schema'          => self::INPUT_SCHEMA,
				'entry_path'      => $entry_path,
				'entrypoints'     => $normalized['entrypoints'],
				'file_count'      => count( $normalized['files'] ),
				'accepted_count'  => count( $normalized['files'] ),
				'rejected_count'  => $normalized['rejected_count'],
				'bytes'           => $normalized['bytes'],
				'files_by_kind'   => $this->count_files_by_kind( $normalized['files'] ),
				'files_by_role'   => $this->count_files_by_field( $normalized['files'], 'role' ),
				'files_by_mime'   => $this->count_files_by_field( $normalized['files'], 'mime_type' ),
				'original_schema' => (string) ( $artifact['schema'] ?? '' ),
			),
			'wordpress_artifacts' => array(
				'block_markup' => $conversion['serialized_blocks'],
				'blocks'       => $conversion['blocks'],
				'block_types'  => array(),
				'components'   => $components,
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
	 * @param string               $content Source content.
	 * @param string               $source  Source label or path.
	 * @param string               $format  Source format.
	 * @param array<string, mixed> $options Compiler options.
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
	 * @param array<string,mixed> $compiled Compiler result envelope.
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
	 * @param array<string,mixed> $artifact Raw artifact.
	 * @param array<string,mixed> $options  Compiler options.
	 * @return array{files:array<int,array<string,mixed>>,diagnostics:array<int,array<string,mixed>>,rejected_count:int,bytes:int,entrypoints:array<int,string>}
	 */
	private function normalize_artifact( array $artifact, array $options ): array {
		$limits = array(
			'max_files'      => max( 1, (int) ( $options['max_files'] ?? self::DEFAULT_MAX_FILES ) ),
			'max_file_bytes' => max( 1, (int) ( $options['max_file_bytes'] ?? self::DEFAULT_MAX_FILE_BYTES ) ),
			'max_total_bytes' => max( 1, (int) ( $options['max_total_bytes'] ?? self::DEFAULT_MAX_TOTAL_BYTES ) ),
		);
		$raw_entrypoints = $this->extract_entrypoints( $artifact );
		$raw_files       = $this->extract_raw_files( $artifact );
		$files       = array();
		$diagnostics = array();
		$total_bytes = 0;
		$rejected    = 0;
		$seen_paths  = array();
		$entrypoints = array();

		foreach ( $raw_entrypoints as $entrypoint ) {
			$path = $this->safe_relative_path( $entrypoint );
			if ( '' === $path ) {
				$diagnostics[] = $this->diagnostic( 'unsafe_entrypoint_path', 'warning', 'An artifact entrypoint was ignored because its path is empty, absolute, or escapes the artifact root.', array( 'path' => $entrypoint ) );
				continue;
			}
			$entrypoints[ $path ] = true;
		}

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

			$payload = $this->normalize_file_payload( $file, $path );
			$diagnostics = array_merge( $diagnostics, $payload['diagnostics'] );
			if ( ! $payload['accepted'] ) {
				++$rejected;
				continue;
			}

			$content = $payload['content'];
			$bytes   = $payload['bytes'];
			if ( $bytes > $limits['max_file_bytes'] ) {
				++$rejected;
				$diagnostics[] = $this->diagnostic( 'artifact_file_too_large', 'warning', 'An artifact file was ignored because it exceeds the per-file byte limit.', array( 'path' => $path, 'bytes' => $bytes, 'max_file_bytes' => $limits['max_file_bytes'] ) );
				continue;
			}

			if ( $total_bytes + $bytes > $limits['max_total_bytes'] ) {
				++$rejected;
				$diagnostics[] = $this->diagnostic( 'artifact_total_too_large', 'warning', 'An artifact file was ignored because the bundle byte limit was reached.', array( 'path' => $path, 'bytes' => $bytes, 'max_total_bytes' => $limits['max_total_bytes'] ) );
				continue;
			}

			$deduped_path = $this->dedupe_path( $path, $seen_paths );
			$seen_paths[ $deduped_path ] = true;
			$total_bytes += $bytes;
			$mime_type   = $this->normalize_mime_type( (string) ( $file['mime_type'] ?? $file['mime'] ?? $file['media_type'] ?? ( str_contains( (string) ( $file['type'] ?? '' ), '/' ) ? $file['type'] : '' ) ), $deduped_path );
			$kind        = $this->normalize_kind( (string) ( $file['kind'] ?? $file['type'] ?? '' ), $deduped_path, $content, $mime_type );
			$is_binary   = $payload['binary'] || $this->is_binary_mime_type( $mime_type );
			$role        = $this->normalize_role( (string) ( $file['role'] ?? '' ), $kind, $mime_type, $deduped_path );
			$intent      = $this->normalize_intent( (string) ( $file['intent'] ?? '' ), $kind, $role );
			$is_entry    = ! empty( $entrypoints[ $deduped_path ] ) || ! empty( $file['entrypoint'] ) || 'entry' === $role;
			$content_base64 = $payload['content_base64'];
			if ( $is_binary && '' === $content_base64 ) {
				$content_base64 = base64_encode( $content );
			}

			if ( $is_entry ) {
				$entrypoints[ $deduped_path ] = true;
			}

			$normalized_file = array(
				'path'    => $deduped_path,
				'content' => $content,
				'kind'    => $kind,
				'bytes'   => $bytes,
				'source'  => (string) ( $file['source'] ?? 'artifact' ),
				'mime_type' => $mime_type,
				'role'    => $role,
				'encoding' => $payload['encoding'],
				'binary'  => $is_binary,
				'entrypoint' => $is_entry,
			);

			if ( '' !== $content_base64 ) {
				$normalized_file['content_base64'] = $content_base64;
			}
			if ( '' !== $intent ) {
				$normalized_file['intent'] = $intent;
			}

			$files[] = $normalized_file;
		}

		return array(
			'files'          => $files,
			'diagnostics'    => $this->dedupe_diagnostics( $diagnostics ),
			'rejected_count' => $rejected,
			'bytes'          => $total_bytes,
			'entrypoints'    => array_keys( $entrypoints ),
		);
	}

	/**
	 * Extract file-like entries from common AI artifact shapes.
	 *
	 * @param array<string,mixed> $artifact Raw artifact.
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

		foreach ( array( 'css' => 'style.css', 'styles' => 'style.css', 'javascript' => 'site.js', 'js' => 'site.js', 'script' => 'site.js' ) as $key => $path ) {
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
	 * Extract explicit bundle entrypoints from common artifact shapes.
	 *
	 * @param array<string,mixed> $artifact Raw artifact.
	 * @return array<int,string> Entrypoint paths.
	 */
	private function extract_entrypoints( array $artifact ): array {
		$entrypoints = array();
		foreach ( array( 'entrypoint', 'entry', 'main' ) as $key ) {
			if ( isset( $artifact[ $key ] ) && is_string( $artifact[ $key ] ) ) {
				$entrypoints[] = $artifact[ $key ];
			}
		}

		if ( isset( $artifact['entrypoints'] ) && is_array( $artifact['entrypoints'] ) ) {
			foreach ( $artifact['entrypoints'] as $entrypoint ) {
				if ( is_string( $entrypoint ) ) {
					$entrypoints[] = $entrypoint;
				}
			}
		}

		return array_values( array_unique( $entrypoints ) );
	}

	/**
	 * Normalize a list or path=>content map into file entries.
	 *
	 * @param array<mixed> $collection File collection.
	 * @param string       $source     Source key.
	 * @return array<int,array<string,mixed>> Raw files.
	 */
	private function normalize_file_collection( array $collection, string $source ): array {
		$files = array();
		foreach ( $collection as $key => $file ) {
			if ( is_array( $file ) ) {
				$path = (string) ( $file['path'] ?? $file['name'] ?? $key );
				$file['path'] = $path;
				$file['source'] = (string) ( $file['source'] ?? $source );
				$files[] = $file;
				continue;
			}

			if ( is_string( $file ) ) {
				$path = is_string( $key ) ? $key : 'artifact-' . (string) $key . '.html';
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
	 * @param array{files:array<int,array<string,mixed>>,entrypoints?:array<int,string>} $artifact Normalized artifact.
	 * @return array<string,mixed>|null
	 */
	private function entry_file( array $artifact ): ?array {
		$entrypoints = isset( $artifact['entrypoints'] ) && is_array( $artifact['entrypoints'] ) ? $artifact['entrypoints'] : array();
		foreach ( $entrypoints as $entrypoint ) {
			foreach ( $artifact['files'] as $file ) {
				if ( $entrypoint === $file['path'] && 'html' === $file['kind'] && empty( $file['binary'] ) ) {
					return $file;
				}
			}
		}

		$preferred = array( 'index.html', 'index.htm', 'static-site/index.html', 'public/index.html' );
		foreach ( $preferred as $path ) {
			foreach ( $artifact['files'] as $file ) {
				if ( $path === strtolower( (string) $file['path'] ) && empty( $file['binary'] ) ) {
					return $file;
				}
			}
		}

		foreach ( $artifact['files'] as $file ) {
			if ( 'html' === $file['kind'] && empty( $file['binary'] ) ) {
				return $file;
			}
		}

		return null;
	}

	/**
	 * Convert HTML to block markup through BFB/H2BC when available.
	 *
	 * @param string              $html    Source HTML.
	 * @param array<string,mixed> $options Compiler options.
	 * @return array{serialized_blocks:string,blocks:array,diagnostics:array<int,array<string,mixed>>,report:array<string,mixed>}
	 */
	private function convert_html_to_blocks( string $html, array $options ): array {
		if ( str_contains( $html, '<!-- wp:' ) && function_exists( 'parse_blocks' ) && function_exists( 'serialize_blocks' ) ) {
			$blocks = parse_blocks( $html );
			return array(
				'serialized_blocks' => serialize_blocks( $blocks ),
				'blocks'            => $blocks,
				'diagnostics'       => array(),
				'report'            => array( 'status' => 'success_native', 'source' => 'blocks' ),
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
	 * Build component candidates from explicit markers and repeated class tokens.
	 *
	 * @param array{files:array<int,array<string,mixed>>} $artifact Normalized artifact.
	 * @param string $entry_path Entry path.
	 * @return array<int,array<string,mixed>> Component candidates.
	 */
	private function detect_components( array $artifact, string $entry_path ): array {
		$candidates = array();
		$classes    = array();

		foreach ( $artifact['files'] as $file ) {
			if ( 'html' !== $file['kind'] ) {
				continue;
			}

			if ( preg_match_all( '/data-component\s*=\s*(["\'])([^"\']+)\1/i', $file['content'], $matches ) ) {
				foreach ( $matches[2] as $name ) {
					$key = sanitize_key( $name );
					if ( '' !== $key ) {
						$candidates[ 'explicit:' . $key ] = array(
							'name'       => $key,
							'source'     => $file['path'],
							'signal'     => 'data-component',
							'occurrences' => ( $candidates[ 'explicit:' . $key ]['occurrences'] ?? 0 ) + 1,
						);
					}
				}
			}

			if ( preg_match_all( '/class\s*=\s*(["\'])([^"\']+)\1/i', $file['content'], $matches ) ) {
				foreach ( $matches[2] as $class_list ) {
					foreach ( preg_split( '/\s+/', trim( $class_list ) ) ?: array() as $class ) {
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
				return ( $right['occurrences'] <=> $left['occurrences'] ) ?: strcmp( (string) $left['name'], (string) $right['name'] );
			}
		);

		return array_slice( array_values( $candidates ), 0, 25 );
	}

	/**
	 * Return non-entry files that SSI or another materializer may consume later.
	 *
	 * @param array{files:array<int,array<string,mixed>>} $artifact Normalized artifact.
	 * @return array<int,array<string,mixed>> Files.
	 */
	private function wordpress_files_from_artifact( array $artifact ): array {
		$files = array();
		foreach ( $artifact['files'] as $file ) {
			if ( 'html' === $file['kind'] ) {
				continue;
			}

			$manifest_file = array(
				'path'    => $file['path'],
				'kind'    => $file['kind'],
				'bytes'   => $file['bytes'],
				'mime_type' => $file['mime_type'],
				'role'    => $file['role'],
				'encoding' => $file['encoding'],
				'binary'  => $file['binary'],
			);

			if ( ! empty( $file['intent'] ) ) {
				$manifest_file['intent'] = $file['intent'];
			}
			if ( ! empty( $file['content_base64'] ) ) {
				$manifest_file['content_base64'] = $file['content_base64'];
			} else {
				$manifest_file['content'] = $file['content'];
			}

			$files[] = $manifest_file;
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
	 * Normalize file payloads from text or base64 content fields.
	 *
	 * @param array<string,mixed> $file Raw file entry.
	 * @return array{accepted:bool,content:string,content_base64:string,encoding:string,binary:bool,bytes:int,diagnostics:array<int,array<string,mixed>>}
	 */
	private function normalize_file_payload( array $file, string $path ): array {
		$diagnostics = array();
		if ( isset( $file['content_base64'] ) && is_string( $file['content_base64'] ) ) {
			$base64  = preg_replace( '/\s+/', '', $file['content_base64'] ) ?? '';
			$decoded = base64_decode( $base64, true );
			if ( false === $decoded ) {
				return array(
					'accepted'       => false,
					'content'        => '',
					'content_base64' => '',
					'encoding'       => 'base64',
					'binary'         => false,
					'bytes'          => 0,
					'diagnostics'    => array( $this->diagnostic( 'invalid_base64_content', 'warning', 'An artifact file was ignored because content_base64 is not valid base64.', array( 'path' => $path ) ) ),
				);
			}

			$is_binary = $this->looks_binary( $decoded );
			if ( ! $is_binary && isset( $file['content'] ) && is_string( $file['content'] ) && '' !== $file['content'] && $file['content'] !== $decoded ) {
				$diagnostics[] = $this->diagnostic( 'content_base64_preferred', 'info', 'Both content and content_base64 were provided; decoded content_base64 was used as the canonical payload.', array( 'path' => $path ) );
			}

			return array(
				'accepted'       => true,
				'content'        => $is_binary ? '' : $decoded,
				'content_base64' => $base64,
				'encoding'       => 'base64',
				'binary'         => $is_binary,
				'bytes'          => strlen( $decoded ),
				'diagnostics'    => $diagnostics,
			);
		}

		$content = $this->normalize_content( $file['content'] ?? $file['body'] ?? $file['text'] ?? '' );
		return array(
			'accepted'       => true,
			'content'        => $content,
			'content_base64' => '',
			'encoding'       => 'text',
			'binary'         => false,
			'bytes'          => strlen( $content ),
			'diagnostics'    => array(),
		);
	}

	/**
	 * Normalize file kind from explicit kind, path, and content.
	 */
	private function normalize_kind( string $kind, string $path, string $content, string $mime_type = '' ): string {
		$kind = sanitize_key( $kind );
		if ( in_array( $kind, array( 'html', 'css', 'js', 'json', 'markdown', 'asset', 'blocks' ), true ) ) {
			return $kind;
		}
		if ( str_contains( $mime_type, '/' ) ) {
			if ( str_contains( $mime_type, 'html' ) ) {
				return 'html';
			}
			if ( 'text/css' === $mime_type ) {
				return 'css';
			}
			if ( in_array( $mime_type, array( 'application/javascript', 'text/javascript', 'application/ecmascript', 'text/ecmascript' ), true ) ) {
				return 'js';
			}
			if ( 'application/json' === $mime_type ) {
				return 'json';
			}
		}

		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		return match ( $extension ) {
			'html', 'htm'       => 'html',
			'css'               => 'css',
			'js', 'mjs'          => 'js',
			'json'              => 'json',
			'md', 'markdown'    => 'markdown',
			default             => str_contains( $content, '<!-- wp:' ) ? 'blocks' : 'asset',
		};
	}

	/**
	 * Normalize or infer a MIME type.
	 */
	private function normalize_mime_type( string $mime_type, string $path ): string {
		$mime_type = strtolower( trim( $mime_type ) );
		if ( preg_match( '#^[a-z0-9.+-]+/[a-z0-9.+-]+$#', $mime_type ) ) {
			return $mime_type;
		}

		return match ( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
			'html', 'htm'       => 'text/html',
			'css'               => 'text/css',
			'js', 'mjs'          => 'application/javascript',
			'json'              => 'application/json',
			'md', 'markdown'    => 'text/markdown',
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
		$role = sanitize_key( $role );
		if ( '' !== $role ) {
			return $role;
		}

		if ( 'html' === $kind ) {
			return preg_match( '#(^|/)index\.html?$#i', $path ) ? 'entry' : 'document';
		}
		if ( 'css' === $kind ) {
			return 'stylesheet';
		}
		if ( 'js' === $kind ) {
			return 'script';
		}
		if ( str_starts_with( $mime_type, 'image/' ) ) {
			return 'image';
		}
		if ( str_starts_with( $mime_type, 'font/' ) ) {
			return 'font';
		}
		if ( in_array( $kind, array( 'json', 'markdown' ), true ) ) {
			return 'data';
		}

		return 'asset';
	}

	/**
	 * Normalize CSS/JS intent metadata.
	 */
	private function normalize_intent( string $intent, string $kind, string $role ): string {
		$intent = sanitize_key( $intent );
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
		return str_contains( $content, "\0" );
	}

	/**
	 * Return whether a MIME type should be treated as binary in result manifests.
	 */
	private function is_binary_mime_type( string $mime_type ): bool {
		if ( str_starts_with( $mime_type, 'text/' ) ) {
			return false;
		}

		return ! in_array( $mime_type, array( 'application/json', 'application/javascript', 'image/svg+xml' ), true );
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
	 * @param array<int,array{kind:string}> $files Files.
	 * @return array<string,int>
	 */
	private function count_files_by_kind( array $files ): array {
		return $this->count_files_by_field( $files, 'kind' );
	}

	/**
	 * Count normalized files by a manifest field.
	 *
	 * @param array<int,array<string,mixed>> $files Files.
	 * @return array<string,int>
	 */
	private function count_files_by_field( array $files, string $field ): array {
		$counts = array();
		foreach ( $files as $file ) {
			$value = isset( $file[ $field ] ) ? (string) $file[ $field ] : '';
			if ( '' === $value ) {
				continue;
			}
			$counts[ $value ] = ( $counts[ $value ] ?? 0 ) + 1;
		}
		ksort( $counts );

		return $counts;
	}

	/**
	 * Build a normalized diagnostic entry.
	 *
	 * @param array<string,mixed> $details Diagnostic details.
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
	 * @param array<int,array<string,mixed>> $diagnostics Diagnostics.
	 * @return array<int,array<string,mixed>> Diagnostics.
	 */
	private function dedupe_diagnostics( array $diagnostics ): array {
		$deduped = array();
		$seen    = array();
		foreach ( $diagnostics as $diagnostic ) {
			$key = (string) ( $diagnostic['code'] ?? '' ) . '|' . md5( wp_json_encode( $diagnostic['details'] ?? array() ) ?: '' );
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
			$content = isset( $file['content_base64'] ) ? (string) $file['content_base64'] : (string) $file['content'];
			$payload .= $file['path'] . "\0" . $file['kind'] . "\0" . ( $file['mime_type'] ?? '' ) . "\0" . $content . "\0";
		}

		return $payload;
	}
}

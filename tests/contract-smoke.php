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

	fwrite( STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL );
	exit( 1 );
};

$result = bac_compile_website_artifact(
	array(
		'files' => array(
			array(
				'path'    => 'index.html',
				'content' => '<main><h1>Hello compiler</h1><p>Initial contract.</p></main>',
			),
		),
	)
);

$assert( 'chubes4/block-artifact-compiler-result/v1' === ( $result['schema'] ?? '' ), 'result exposes schema' );
$assert( 'success_with_warnings' === ( $result['status'] ?? '' ), 'fallback status reflects missing BFB in smoke test', (string) ( $result['status'] ?? '' ) );
$assert( 'index.html' === ( $result['input']['entry_path'] ?? '' ), 'entry path is captured' );
$assert( str_contains( (string) ( $result['wordpress_artifacts']['block_markup'] ?? '' ), '<!-- wp:html -->' ), 'fallback block markup is produced' );
$assert( array() === ( $result['wordpress_artifacts']['block_types'] ?? null ), 'initial contract exposes empty block type list' );
$assert( isset( $result['wordpress_artifacts']['components'] ) && is_array( $result['wordpress_artifacts']['components'] ), 'component candidates are exposed' );

$empty = bac_compile_website_artifact( array( 'files' => array() ) );
$assert( 'failed' === ( $empty['status'] ?? '' ), 'missing HTML fails explicitly' );

$messy = bac_compile_website_artifact(
	array(
		'generated_html' => '<main><section class="hero"><h1>Messy</h1></section><article class="card product-card" data-component="Product Card">A</article><article class="card product-card">B</article></main>',
		'css'            => '.card{border:1px solid currentColor}',
		'files'          => array(
			'../secret.txt' => 'nope',
			'app.js'        => 'console.log("preview only");',
			array(
				'path'    => '/absolute.html',
				'content' => 'nope',
			),
		),
	)
);
$assert( 'success_with_warnings' === ( $messy['status'] ?? '' ), 'unsafe AI artifact inputs produce warning status', (string) ( $messy['status'] ?? '' ) );
$assert( 2 === ( $messy['input']['rejected_count'] ?? null ), 'unsafe paths are rejected' );
$assert( 'index.html' === ( $messy['input']['entry_path'] ?? '' ), 'generated_html becomes index entry' );
$assert( 1 === ( $messy['input']['files_by_kind']['css'] ?? 0 ), 'css shorthand is normalized' );
$assert( 1 === ( $messy['input']['files_by_kind']['js'] ?? 0 ), 'js file is normalized' );
$assert( ! empty( $messy['wordpress_artifacts']['components'] ?? array() ), 'component candidates are detected' );

$fragment = bac_compile_fragment( '<div class="feature-card">Feature</div>', 'main:index.html' );
$assert( 'main-index.html' === ( $fragment['input']['entry_path'] ?? '' ), 'fragment source is normalized to virtual path' );

$summary = bac_summarize_result( $messy );
$assert( ( $summary['component_count'] ?? 0 ) > 0, 'summary exposes component count' );

$rich = bac_compile_website_artifact(
	array(
		'schema'      => 'example/rich-website-bundle/v1',
		'entrypoints' => array( 'pages/home.html', '../unsafe.html' ),
		'files'       => array(
			array(
				'path'           => 'pages/home.html',
				'content_base64' => base64_encode( '<main><h1>Rich bundle</h1></main>' ),
				'mime_type'      => 'text/html',
				'role'           => 'entry',
			),
			array(
				'path'    => 'assets/app.css',
				'content' => 'body{color:rebeccapurple}',
				'type'    => 'text/css',
				'intent'  => 'theme-style',
			),
			array(
				'path'           => 'assets/logo.png',
				'content_base64' => base64_encode( "\x89PNG\r\n\x1a\n" ),
				'mime_type'      => 'image/png',
				'role'           => 'brand-asset',
			),
			array(
				'path'           => 'assets/bad.bin',
				'content_base64' => 'not-valid-base64',
			),
		),
	)
);
$assert( 'pages/home.html' === ( $rich['input']['entry_path'] ?? '' ), 'explicit entrypoint selects nested HTML entry' );
$assert( in_array( 'pages/home.html', $rich['input']['entrypoints'] ?? array(), true ), 'entrypoints are normalized into input metadata' );
$assert( 1 === ( $rich['input']['files_by_role']['brand-asset'] ?? 0 ), 'explicit asset role is preserved' );
$assert( 1 === ( $rich['input']['files_by_mime']['image/png'] ?? 0 ), 'MIME counts are exposed' );
$assert( 1 === ( $rich['input']['rejected_count'] ?? null ), 'invalid base64 file is rejected without blocking the bundle' );
$has_unsafe_entrypoint = false;
foreach ( $rich['diagnostics'] ?? array() as $diagnostic ) {
	if ( 'unsafe_entrypoint_path' === ( $diagnostic['code'] ?? '' ) ) {
		$has_unsafe_entrypoint = true;
	}
}
$assert( $has_unsafe_entrypoint, 'unsafe entrypoint is diagnosed' );
$asset_files = $rich['wordpress_artifacts']['files'] ?? array();
$png_file    = null;
foreach ( $asset_files as $asset_file ) {
	if ( 'assets/logo.png' === ( $asset_file['path'] ?? '' ) ) {
		$png_file = $asset_file;
	}
}
$assert( is_array( $png_file ), 'binary asset appears in file manifest' );
$assert( ! empty( $png_file['content_base64'] ?? '' ), 'binary asset keeps base64 payload' );
$assert( true === ( $png_file['binary'] ?? null ), 'binary asset is marked binary' );

fwrite( STDOUT, "contract smoke passed\n" );

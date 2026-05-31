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

$blocks = bac_compile_website_artifact(
	array(
		'generated_html' => '<main><h1>Block artifact page</h1></main>',
		'files'          => array(
			'blocks/hero/block.json'       => wp_json_encode(
				array(
					'apiVersion'   => 3,
					'name'         => 'acme/hero',
					'title'        => 'Hero',
					'category'     => 'design',
					'editorScript' => 'file:./index.js',
					'viewScript'   => array( 'file:./view.js', 'wp-interactivity' ),
					'style'        => 'file:./style.css',
					'editorStyle'  => 'file:./editor.css',
					'render'       => 'file:./render.php',
					'attributes'   => array(
						'headline' => array( 'type' => 'string' ),
					),
					'supports'     => array( 'align' => true ),
				),
				JSON_UNESCAPED_SLASHES
			),
			'blocks/hero/index.js'         => 'import metadata from "./block.json";',
			'blocks/hero/index.asset.php'  => '<?php return array("dependencies" => array("wp-blocks"), "version" => "1");',
			'blocks/hero/view.js'          => 'console.log("front");',
			'blocks/hero/style.css'        => '.wp-block-acme-hero{padding:2rem}',
			'blocks/hero/editor.css'       => '.wp-block-acme-hero{outline:1px solid}',
			'blocks/hero/render.php'       => '<?php echo $content;',
		),
	)
);
$block_types = $blocks['wordpress_artifacts']['block_types'] ?? array();
$assert( 1 === count( $block_types ), 'block.json roots are promoted into block type artifacts' );
$hero = $block_types[0] ?? array();
$assert( 'chubes4/wordpress-block-type-artifact/v1' === ( $hero['schema'] ?? '' ), 'block type exposes contract schema' );
$assert( 'acme/hero' === ( $hero['name'] ?? '' ), 'block type preserves block.json name' );
$assert( 'blocks/hero' === ( $hero['directory'] ?? '' ), 'block type exposes source directory' );
$assert( 'blocks/hero/block.json' === ( $hero['block_json_path'] ?? '' ), 'block type exposes block.json path' );
$assert( 3 === ( $hero['metadata']['apiVersion'] ?? null ), 'block metadata preserves apiVersion' );
$assert( array( 'align' => true ) === ( $hero['metadata']['supports'] ?? null ), 'block metadata preserves supports' );
$assert( 'blocks/hero/index.js' === ( $hero['assets']['editor_script'][0]['path'] ?? '' ), 'editor script file reference resolves to generated file' );
$assert( 'wp-interactivity' === ( $hero['assets']['view_script'][1]['reference'] ?? '' ), 'script handles are preserved as dependencies/references' );
$assert( 'blocks/hero/render.php' === ( $hero['assets']['render'][0]['path'] ?? '' ), 'render file reference resolves to generated file' );
$assert( 'blocks/hero/index.asset.php' === ( $hero['dependencies']['asset_files'][0]['path'] ?? '' ), 'asset php dependency manifests are recorded' );
$assert( ! empty( $hero['provenance']['source_hash'] ?? '' ), 'block type exposes provenance hash' );
$assert( in_array( 'blocks/hero/style.css', $hero['provenance']['files'] ?? array(), true ), 'block provenance lists source files' );

$summary = bac_summarize_result( $blocks );
$assert( 1 === ( $summary['block_type_count'] ?? 0 ), 'summary exposes block type count' );

fwrite( STDOUT, "contract smoke passed\n" );

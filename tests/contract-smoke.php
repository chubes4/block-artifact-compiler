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

$markdown = bac_compile_website_artifact(
	array(
		'files' => array(
			'content/about.md'       => "---\ntitle: About Us\nslug: about\npost_type: page\ntags: [team, story]\n---\n# About\n\nPlain Markdown content.",
			'content/changelog.markdown' => "---\ntitle: Changelog\npost_type: post\n---\n# Changes",
			'assets/logo.bin'       => 'binary-ish',
		),
	)
);
$assert( 'success_with_warnings' === ( $markdown['status'] ?? '' ), 'markdown documents compile with fallback warnings when BFB is unavailable', (string) ( $markdown['status'] ?? '' ) );
$assert( 2 === ( $markdown['input']['files_by_kind']['markdown'] ?? 0 ), 'md and markdown files are classified as markdown' );
$assert( 1 === ( $markdown['input']['files_by_kind']['asset'] ?? 0 ), 'unknown assets remain assets' );
$assert( 2 === count( $markdown['wordpress_artifacts']['documents'] ?? array() ), 'markdown source documents produce WordPress document artifacts' );
$assert( 'about' === ( $markdown['wordpress_artifacts']['documents'][0]['slug'] ?? '' ), 'frontmatter slug is preserved' );
$assert( 'About Us' === ( $markdown['wordpress_artifacts']['documents'][0]['title'] ?? '' ), 'frontmatter title is preserved' );
$assert( str_contains( (string) ( $markdown['wordpress_artifacts']['documents'][0]['block_markup'] ?? '' ), '<!-- wp:html -->' ), 'markdown body is converted or preserved as block markup' );

$mdx = bac_compile_website_artifact(
	array(
		'files' => array(
			'pages/home.mdx'          => "---\ntitle: Home\nslug: home\n---\nimport Hero from '../components/Hero'\nimport { ProductGrid } from '../components/ProductGrid'\n\n# Welcome\n\n<Hero />\n<ProductGrid collection=\"featured\" />\n<MissingWidget />",
			'components/Hero.jsx'     => 'export default function Hero() { return <section />; }',
			'components/ProductGrid.tsx' => 'export function ProductGrid() { return <div />; }',
		),
	)
);
$assert( 1 === ( $mdx['input']['files_by_kind']['mdx'] ?? 0 ), 'mdx files are classified as mdx' );
$assert( 1 === ( $mdx['input']['files_by_kind']['jsx'] ?? 0 ), 'jsx files are classified as jsx component sources' );
$assert( 1 === ( $mdx['input']['files_by_kind']['tsx'] ?? 0 ), 'tsx files are classified as tsx component sources' );
$assert( 'text/mdx' === ( $mdx['wordpress_artifacts']['files'][0]['mime_type'] ?? '' ), 'mdx files preserve BAC-local MIME type in file manifest' );
$assert( 1 === count( $mdx['wordpress_artifacts']['documents'] ?? array() ), 'mdx source document produces a document artifact' );
$assert( count( $mdx['wordpress_artifacts']['components'] ?? array() ) >= 3, 'mdx JSX components produce component candidates' );
$assert( ! empty( array_filter( $mdx['wordpress_artifacts']['components'] ?? array(), static fn ( array $component ): bool => 'Hero' === ( $component['name'] ?? '' ) && 'components/Hero.jsx' === ( $component['resolved_path'] ?? '' ) ) ), 'mdx imports resolve to generated source files when present' );
$assert( ! empty( array_filter( $mdx['wordpress_artifacts']['components'] ?? array(), static fn ( array $component ): bool => 'jsx-component-file' === ( $component['signal'] ?? '' ) && 'components/Hero.jsx' === ( $component['source'] ?? '' ) ) ), 'jsx source files produce component candidates' );
$assert( ! empty( array_filter( $mdx['diagnostics'] ?? array(), static fn ( array $diagnostic ): bool => 'mdx_component_unresolved' === ( $diagnostic['code'] ?? '' ) ) ), 'unresolved mdx components emit diagnostics' );

$fragment = bac_compile_fragment( '<div class="feature-card">Feature</div>', 'main:index.html' );
$assert( 'main-index.html' === ( $fragment['input']['entry_path'] ?? '' ), 'fragment source is normalized to virtual path' );

$summary = bac_summarize_result( $messy );
$assert( ( $summary['component_count'] ?? 0 ) > 0, 'summary exposes component count' );

fwrite( STDOUT, "contract smoke passed\n" );

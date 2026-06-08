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

	$failure = 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL;
	fwrite( STDOUT, $failure );
	fwrite( STDERR, $failure );
	exit( 1 );
};

$fallback_options = array( 'allow_bfb_unavailable_fallback' => true );

$missing_bfb = bac_compile_fragment( '<main><p>Needs BFB</p></main>', 'production-fragment.html' );
$bfb_available = 'success' === ( $missing_bfb['status'] ?? '' );
if ( 'failed' === ( $missing_bfb['status'] ?? '' ) ) {
	$assert( ! empty( array_filter( $missing_bfb['diagnostics'] ?? array(), static fn ( array $diagnostic ): bool => 'bfb_unavailable' === ( $diagnostic['code'] ?? '' ) && 'error' === ( $diagnostic['severity'] ?? '' ) ) ), 'missing BFB default policy emits an error diagnostic' );
} else {
	$assert( 'success' === ( $missing_bfb['status'] ?? '' ), 'production fragment compilation succeeds when BFB is available', (string) ( $missing_bfb['status'] ?? '' ) );
}

$result = bac_compile_website_artifact(
	array(
		'files' => array(
			array(
				'path'    => 'index.html',
				'content' => '<main><h1>Hello compiler</h1><p>Initial contract.</p></main>',
			),
		),
	),
	$fallback_options
);

$assert( 'block-artifact-compiler/result/v1' === ( $result['schema'] ?? '' ), 'result exposes schema' );
$assert( 'block-artifact-compiler/website-artifact/v1' === ( $result['input']['schema'] ?? '' ), 'input metadata exposes canonical website artifact schema' );
$assert( ( $bfb_available ? 'success' : 'success_with_warnings' ) === ( $result['status'] ?? '' ), 'compile status reflects BFB availability in smoke test', (string) ( $result['status'] ?? '' ) );
$assert( 'index.html' === ( $result['input']['entry_path'] ?? '' ), 'entry path is captured' );
$assert( 3 === ( $result['input']['source_report']['html']['element_count'] ?? null ), 'source HTML element count is reported before conversion' );
$assert( 1 === ( $result['input']['source_report']['html']['landmark_counts']['main'] ?? null ), 'source landmark counts are reported before conversion' );
if ( ! $bfb_available ) {
	$assert( str_contains( (string) ( $result['wordpress_artifacts']['block_markup'] ?? '' ), '<!-- wp:html -->' ), 'fallback block markup is produced' );
}
$assert( isset( $result['wordpress_artifacts']['block_tree'] ) && is_array( $result['wordpress_artifacts']['block_tree'] ), 'generated block tree report is exposed' );
$assert( array() === ( $result['wordpress_artifacts']['block_types'] ?? null ), 'initial contract exposes empty block type list' );
$assert( isset( $result['wordpress_artifacts']['components'] ) && is_array( $result['wordpress_artifacts']['components'] ), 'component candidates are exposed' );

$empty = bac_compile_website_artifact( array( 'files' => array() ) );
$assert( 'failed' === ( $empty['status'] ?? '' ), 'missing HTML fails explicitly' );

$schema_less = bac_compile_website_artifact(
	array(
		'files' => array(
			array(
				'path'    => 'schema-less.html',
				'content' => '<main><p>No input schema required.</p></main>',
			),
		),
	),
	$fallback_options
);
$assert( 'schema-less.html' === ( $schema_less['input']['entry_path'] ?? '' ), 'bundles without schema still compile' );
$assert( '' === ( $schema_less['input']['original_schema'] ?? null ), 'omitted bundle schema is preserved as empty original schema metadata' );

$warnings = array();
set_error_handler(
	static function ( int $errno, string $errstr ) use ( &$warnings ): bool {
		$warnings[] = $errstr;
		return true;
	}
);
$nested_source = bac_compile_website_artifact(
	array(
		'files' => array(
			array(
				'path'    => 'nested-source.html',
				'content' => '<main><p>Nested source metadata.</p></main>',
				'source'  => array( 'metadata' => 'object' ),
			),
		),
	),
	$fallback_options
);
restore_error_handler();
$assert( array() === $warnings, 'non-scalar file source metadata does not emit PHP warnings', implode( '; ', $warnings ) );
$assert( 'nested-source.html' === ( $nested_source['input']['entry_path'] ?? '' ), 'non-scalar file source metadata still compiles' );

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
	),
	$fallback_options
);
$assert( 'success_with_warnings' === ( $messy['status'] ?? '' ), 'unsafe AI artifact inputs produce warning status', (string) ( $messy['status'] ?? '' ) );
$assert( 2 === ( $messy['input']['rejected_count'] ?? null ), 'unsafe paths are rejected' );
$assert( 'index.html' === ( $messy['input']['entry_path'] ?? '' ), 'generated_html becomes index entry' );
$assert( 1 === ( $messy['input']['files_by_kind']['css'] ?? 0 ), 'css shorthand is normalized' );
$assert( 1 === ( $messy['input']['files_by_kind']['js'] ?? 0 ), 'js file is normalized' );
$assert( ( $messy['input']['source_report']['html']['unique_class_count'] ?? 0 ) >= 3, 'source class inventory is reported' );
$assert( 1 === ( $messy['input']['source_report']['css']['selector_count'] ?? null ), 'source CSS selector inventory is reported' );
$assert( ! empty( $messy['wordpress_artifacts']['components'] ?? array() ), 'component candidates are detected' );

$markdown = bac_compile_website_artifact(
	array(
		'files' => array(
			'content/about.md'       => "---\ntitle: About Us\nslug: about\npost_type: page\ntags: [team, story]\n---\n# About\n\nPlain Markdown content.",
			'content/changelog.markdown' => "---\ntitle: Changelog\npost_type: post\n---\n# Changes",
			'assets/logo.bin'       => 'binary-ish',
		),
	),
	$fallback_options
);
$assert( ( $bfb_available ? 'success' : 'success_with_warnings' ) === ( $markdown['status'] ?? '' ), 'markdown document status reflects BFB availability', (string) ( $markdown['status'] ?? '' ) );
$assert( 2 === ( $markdown['input']['files_by_kind']['markdown'] ?? 0 ), 'md and markdown files are classified as markdown' );
$assert( 1 === ( $markdown['input']['files_by_kind']['asset'] ?? 0 ), 'unknown assets remain assets' );
$assert( 2 === count( $markdown['wordpress_artifacts']['documents'] ?? array() ), 'markdown source documents produce WordPress document artifacts' );
$assert( 'about' === ( $markdown['wordpress_artifacts']['documents'][0]['slug'] ?? '' ), 'frontmatter slug is preserved' );
$assert( 'About Us' === ( $markdown['wordpress_artifacts']['documents'][0]['title'] ?? '' ), 'frontmatter title is preserved' );
$assert( '' !== trim( (string) ( $markdown['wordpress_artifacts']['documents'][0]['block_markup'] ?? '' ) ), 'markdown body is converted or preserved as block markup' );

$mdx = bac_compile_website_artifact(
	array(
		'files' => array(
			'pages/home.mdx'          => "---\ntitle: Home\nslug: home\n---\nimport Hero from '../components/Hero'\nimport { ProductGrid } from '../components/ProductGrid'\n\n# Welcome\n\n<Hero />\n<ProductGrid collection=\"featured\" />\n<MissingWidget />",
			'components/Hero.jsx'     => 'export default function Hero() { return <section />; }',
			'components/ProductGrid.tsx' => 'export function ProductGrid() { return <div />; }',
		),
	),
	$fallback_options
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

$fragment = bac_compile_fragment( '<div class="feature-card">Feature</div>', 'main:index.html', 'html', $fallback_options );
$assert( 'main-index.html' === ( $fragment['input']['entry_path'] ?? '' ), 'fragment source is normalized to virtual path' );

$sibling_fragment = bac_compile_fragment( '<h1>Button Wrapper Style</h1><p><a href="#try" class="btn nav-cta">Request Access</a></p>', 'main:button-wrapper-style.html', 'html', $fallback_options );
$sibling_markup   = (string) ( $sibling_fragment['wordpress_artifacts']['block_markup'] ?? '' );
$assert( str_contains( $sibling_markup, 'Request Access' ), 'html fragments preserve sibling content before conversion', $sibling_markup );
if ( ! $bfb_available ) {
	$assert( str_contains( $sibling_markup, '</h1><p><a href="#try" class="btn nav-cta">Request Access</a></p>' ), 'html fragments preserve sibling block structure before fallback conversion', $sibling_markup );
}
$assert( ! str_contains( $sibling_markup, '<h1>Button Wrapper Style<p>' ), 'html fragments do not nest following paragraphs inside headings', $sibling_markup );

$markdown_fragment = bac_compile_fragment( '# Feature\n\nMarkdown fragment.', 'content/feature.md', 'markdown', $fallback_options );
$assert( 'content/feature.md' === ( $markdown_fragment['input']['entry_path'] ?? '' ), 'markdown fragment keeps a virtual markdown source path' );
if ( ! $bfb_available ) {
	$assert( 'markdown' === ( $markdown_fragment['bfb_report']['source'] ?? '' ), 'markdown fragment routes through BAC fallback conversion envelope' );
}
$assert( str_contains( (string) ( $markdown_fragment['wordpress_artifacts']['block_markup'] ?? '' ), 'Markdown fragment.' ), 'markdown fragment exposes top-level block markup' );

$blocks_fragment = bac_compile_fragment( '<!-- wp:paragraph --><p>Native blocks</p><!-- /wp:paragraph -->', 'content/native.blocks', 'blocks' );
$assert( 'success' === ( $blocks_fragment['status'] ?? '' ), 'serialized block fragments compile without BFB' );
$assert( str_contains( (string) ( $blocks_fragment['wordpress_artifacts']['block_markup'] ?? '' ), 'Native blocks' ), 'serialized block fragments preserve block markup' );

$full_document = bac_compile_website_artifact(
	array(
		'files' => array(
			array(
				'path'    => 'index.html',
				'content' => '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Ember & Rye</title><meta name="description" content="Wood-fired bakery"><link rel="stylesheet" href="/assets/site.css"><script src="/assets/head.js" defer></script></head><body><header class="site-header"><a href="/">Ember & Rye</a></header><main><section class="hero"><h1>Fire, flour, patience.</h1><p>Small-batch loaves.</p></section></main><script src="/assets/body.js" async></script></body></html>',
			),
		),
	),
	$fallback_options
);
$full_document_markup = (string) ( $full_document['wordpress_artifacts']['block_markup'] ?? '' );
$full_document_metadata = $full_document['wordpress_artifacts']['document_metadata'] ?? array();
$full_document_template_parts = $full_document['wordpress_artifacts']['template_parts'] ?? array();
$full_document_regions = $full_document['wordpress_artifacts']['regions'] ?? array();
$assert( ! str_contains( $full_document_markup, '<meta' ), 'full document meta tags are not emitted as block content', $full_document_markup );
$assert( ! str_contains( $full_document_markup, '<title' ), 'full document title tag is not emitted as block content', $full_document_markup );
$assert( ! str_contains( $full_document_markup, '<link' ), 'full document link tags are not emitted as block content', $full_document_markup );
$assert( ! str_contains( $full_document_markup, '<script' ), 'full document script tags are not emitted as block content', $full_document_markup );
$assert( str_contains( $full_document_markup, 'Fire, flour, patience.' ), 'full document body content is preserved in block content', $full_document_markup );
$assert( 'block-artifact-compiler/document-metadata/v1' === ( $full_document_metadata['schema'] ?? '' ), 'full document exposes metadata contract' );
$assert( 'Ember & Rye' === ( $full_document_metadata['title'] ?? '' ), 'full document title is routed to metadata contract' );
$assert( 'utf-8' === ( $full_document_metadata['meta'][0]['charset'] ?? '' ), 'charset meta is routed to metadata contract' );
$assert( 'viewport' === ( $full_document_metadata['meta'][1]['name'] ?? '' ), 'viewport meta is routed to metadata contract' );
$assert( '/assets/site.css' === ( $full_document_metadata['links'][0]['href'] ?? '' ), 'stylesheet link is routed to metadata contract' );
$assert( '/assets/head.js' === ( $full_document_metadata['scripts'][0]['src'] ?? '' ), 'head script is routed to metadata contract' );
$assert( 'head' === ( $full_document_metadata['scripts'][0]['placement'] ?? '' ), 'head script records placement' );
$assert( true === ( $full_document_metadata['scripts'][0]['defer'] ?? null ), 'head script preserves boolean defer attribute' );
$assert( '/assets/body.js' === ( $full_document_metadata['scripts'][1]['src'] ?? '' ), 'body script is routed to metadata contract' );
$assert( 'body' === ( $full_document_metadata['scripts'][1]['placement'] ?? '' ), 'body script records placement' );
$assert( true === ( $full_document_metadata['scripts'][1]['async'] ?? null ), 'body script preserves boolean async attribute' );
$assert( 1 === count( $full_document_template_parts ), 'full document header compiles into a template part artifact' );
$assert( 'header' === ( $full_document_template_parts[0]['slug'] ?? '' ), 'full document template part preserves header slug' );
$assert( 1 === count( $full_document_template_parts[0]['source_paths'] ?? array() ), 'full document template part preserves source path' );
$assert( 1 === count( $full_document['wordpress_artifacts']['site']['template_parts'] ?? array() ), 'compiled site links full document template part artifact' );
$assert( ! empty( array_filter( $full_document_regions, static fn ( array $region ): bool => 'header' === ( $region['role'] ?? '' ) ) ), 'full document exposes semantic header region evidence' );
$assert( ! empty( array_filter( $full_document_regions, static fn ( array $region ): bool => 'main' === ( $region['role'] ?? '' ) ) ), 'full document exposes semantic main region evidence' );
$assert( count( $full_document_regions ) === count( $full_document['wordpress_artifacts']['site']['regions'] ?? array() ), 'compiled site links semantic region artifacts' );

$multi_page = bac_compile_website_artifact(
	array(
		'schema'     => 'block-artifact-compiler/website-artifact/v1',
		'entrypoint' => 'website/index.html',
		'files'      => array(
			array(
				'path'    => 'website/index.html',
				'content' => '<!doctype html><html><head><title>Home Page</title></head><body><main><h1>Home</h1><p>Welcome.</p></main></body></html>',
			),
			array(
				'path'    => 'website/menu.html',
				'content' => '<!doctype html><html><head><title>Menu Page</title><meta name="description" content="Seasonal menu"></head><body><main><h1>Menu</h1><p>Pizza and small plates.</p></main></body></html>',
			),
			array(
				'path'    => 'website/about/index.html',
				'content' => '<main><h1>About</h1><p>Our story.</p></main>',
			),
		)
	),
	$fallback_options
);
$multi_documents = $multi_page['wordpress_artifacts']['documents'] ?? array();
$assert( 3 === count( $multi_documents ), 'multi-page HTML artifacts expose one document per HTML file' );
$assert( 'home' === ( $multi_documents[0]['slug'] ?? '' ), 'root index document exposes canonical home slug' );
$assert( true === ( $multi_documents[0]['entrypoint'] ?? null ), 'entry HTML document preserves entrypoint identity' );
$assert( true === ( $multi_documents[0]['front_page'] ?? null ), 'root index document exposes front-page identity' );
$assert( '/' === ( $multi_documents[0]['route_key'] ?? '' ), 'root index document exposes canonical root route key' );
$assert( '/' === ( $multi_documents[0]['link_rewrite_target'] ?? '' ), 'root index document exposes root link rewrite target' );
$assert( 'Home Page' === ( $multi_documents[0]['title'] ?? '' ), 'entry HTML document title comes from metadata' );
$assert( 'menu' === ( $multi_documents[1]['slug'] ?? '' ), 'nested HTML page slug comes from filename' );
$assert( 'menu' === ( $multi_documents[1]['route_key'] ?? '' ), 'non-index HTML page exposes extensionless route key' );
$assert( '/menu/' === ( $multi_documents[1]['link_rewrite_target'] ?? '' ), 'non-index HTML page exposes canonical link rewrite target' );
$assert( 'Menu Page' === ( $multi_documents[1]['title'] ?? '' ), 'nested HTML document title comes from metadata' );
$assert( 'Seasonal menu' === ( $multi_documents[1]['document_metadata']['meta'][0]['content'] ?? '' ), 'nested HTML document metadata is preserved' );
$assert( 'about' === ( $multi_documents[2]['slug'] ?? '' ), 'nested index document exposes directory slug instead of index' );
$assert( 'about' === ( $multi_documents[2]['route_key'] ?? '' ), 'nested index document exposes directory route key' );
$assert( '/about/' === ( $multi_documents[2]['link_rewrite_target'] ?? '' ), 'nested index document exposes directory link rewrite target' );
$assert( in_array( 'about/index.html', $multi_documents[2]['route_keys'] ?? array(), true ), 'nested index document exposes source-relative route key' );
$assert( in_array( '/about/', $multi_documents[2]['link_rewrite_keys'] ?? array(), true ), 'nested index document exposes clean directory rewrite key' );
$assert( str_contains( (string) ( $multi_documents[2]['block_markup'] ?? '' ), 'About' ), 'HTML document block markup preserves body content' );

$compiled_site = $multi_page['wordpress_artifacts']['site'] ?? array();
$assert( 'block-artifact-compiler/compiled-site/v1' === ( $compiled_site['schema'] ?? '' ), 'compiled site artifact exposes schema' );
$assert( 3 === count( $compiled_site['pages'] ?? array() ), 'compiled site artifact exposes page routes' );
$assert( 'home' === ( $compiled_site['front_page']['slug'] ?? '' ), 'compiled site exposes explicit front-page identity' );
$assert( '/' === ( $compiled_site['pages'][0]['route_key'] ?? '' ), 'compiled site root index page exposes root route key' );
$assert( 'menu' === ( $compiled_site['pages'][1]['route_key'] ?? '' ), 'compiled site non-index page exposes canonical route key' );
$assert( 'about' === ( $compiled_site['pages'][2]['route_key'] ?? '' ), 'compiled site nested index page exposes canonical directory route key' );
$assert( 'menu' === ( $compiled_site['route_map']['menu.html'] ?? '' ), 'compiled site route map includes non-index source href key' );
$assert( 'about' === ( $compiled_site['route_map']['about/index.html'] ?? '' ), 'compiled site route map includes nested index source href key' );
$assert( '/menu/' === ( $compiled_site['link_rewrite_map']['menu.html']['target_path'] ?? '' ), 'compiled site link rewrite map includes non-index target path' );
$assert( '/about/' === ( $compiled_site['link_rewrite_map']['about/index.html']['target_path'] ?? '' ), 'compiled site link rewrite map includes nested index target path' );

$shared_chrome = bac_compile_website_artifact(
	array(
		'files' => array(
			'home.html'  => '<!doctype html><html><body><header class="site-header">Shared nav</header><main><h1>Home</h1></main><footer>Shared footer</footer></body></html>',
			'about.html' => '<!doctype html><html><body><header class="site-header">Shared nav</header><main><h1>About</h1></main><footer>Shared footer</footer></body></html>',
			'site.css'   => 'body{font-family:sans-serif}',
			'site.js'    => 'console.log("site")',
		),
	),
	$fallback_options
);
$shared_regions = $shared_chrome['wordpress_artifacts']['site']['shared_regions'] ?? array();
$semantic_regions = $shared_chrome['wordpress_artifacts']['regions'] ?? array();
$site_regions = $shared_chrome['wordpress_artifacts']['site']['regions'] ?? array();
$assert( count( $semantic_regions ) === count( $site_regions ), 'compiled site links all semantic region artifacts' );
$assert( ! empty( array_filter( $shared_regions, static fn ( array $region ): bool => 'header' === ( $region['role'] ?? '' ) && 2 === count( $region['source_paths'] ?? array() ) ) ), 'compiled site artifact exposes shared header chrome candidates' );
$assert( ! empty( array_filter( $shared_regions, static fn ( array $region ): bool => 'footer' === ( $region['role'] ?? '' ) && 2 === count( $region['source_paths'] ?? array() ) ) ), 'compiled site artifact exposes shared footer chrome candidates' );
$shared_template_parts = $shared_chrome['wordpress_artifacts']['template_parts'] ?? array();
$assert( 2 === count( $shared_template_parts ), 'shared header/footer regions compile into template part artifacts' );
$assert( 'block-artifact-compiler/template-part/v1' === ( $shared_template_parts[0]['schema'] ?? '' ), 'template part artifact exposes schema' );
$assert( 2 === count( $shared_template_parts[0]['source_paths'] ?? array() ), 'template part artifact preserves shared source paths' );
$assert( '' !== trim( (string) ( $shared_template_parts[0]['block_markup'] ?? '' ) ), 'template part artifact exposes block markup' );
if ( ! $bfb_available ) {
	$assert( str_contains( (string) ( $shared_template_parts[0]['block_markup'] ?? '' ), '<!-- wp:html -->' ), 'template part artifact exposes fallback block markup when H2BC is unavailable' );
}
$assert( 2 === count( $shared_chrome['wordpress_artifacts']['site']['template_parts'] ?? array() ), 'compiled site artifact links template part artifacts' );
$assert( 1 === count( $shared_chrome['wordpress_artifacts']['site']['theme_assets']['styles'] ?? array() ), 'compiled site artifact exposes theme style assets' );
$assert( 1 === count( $shared_chrome['wordpress_artifacts']['site']['theme_assets']['scripts'] ?? array() ), 'compiled site artifact exposes theme script assets' );

if ( ! function_exists( 'html_to_blocks_convert_fragment' ) ) {
	function html_to_blocks_convert_fragment( string $html, array $args = array() ): array {
		unset( $args );
		$svg_icon_artifacts = array();
		$diagnostics        = array();
		if ( str_contains( $html, '<svg' ) && str_contains( $html, '<script' ) ) {
			$diagnostics[] = array(
				'code'     => 'unsafe_inline_svg',
				'severity' => 'warning',
				'message'  => 'Inline SVG was preserved as core/html because it did not pass safe icon artifact classification.',
				'context'  => array( 'svg_reason' => 'disallowed_tag' ),
			);
		} elseif ( str_contains( $html, '<svg' ) ) {
			$svg_icon_artifacts[] = array(
				'id'         => 'svg-icon-test-' . substr( hash( 'sha256', $html ), 0, 8 ),
				'type'       => 'svg-icon',
				'content'    => str_contains( $html, '<symbol' ) ? '<svg viewBox="0 0 24 24"><defs><symbol id="shape"><path d="M1 1h22v22H1z"/></symbol></defs><use href="#shape"/></svg>' : '<svg viewBox="0 0 24 24"><path d="M4 12h14"/></svg>',
				'metadata'   => array( 'kind' => 'inline-svg-icon' ),
				'block_path' => array( 0 ),
			);
		}

		return array(
			'block_markup'          => '<!-- wp:paragraph --><p>' . strip_tags( $html ) . '</p><!-- /wp:paragraph -->',
			'blocks'                => array(),
			'diagnostics'           => $diagnostics,
			'fallbacks'             => array(),
			'asset_references'      => str_contains( $html, 'logo.svg' ) ? array( array( 'attribute' => 'src', 'url' => 'assets/logo.svg' ) ) : array(),
			'svg_icon_artifacts'    => $svg_icon_artifacts,
			'navigation_candidates' => str_contains( $html, '<nav' ) ? array(
				array(
					'source' => 'nav[0]',
					'label'  => 'Primary',
					'links'  => array(
						array( 'url' => '/', 'label' => 'Home', 'class_name' => '' ),
					),
				),
			) : array(),
			'visual_repair_metadata' => array(
				'schema'     => 'html-to-blocks-converter/visual-repair-metadata/v1',
				'categories' => array(
					'groups'     => array( array( 'path' => '0', 'block_name' => 'core/group', 'class_name' => 'content-shell', 'classes' => array( 'content-shell' ), 'tag_name' => 'main' ) ),
					'images'     => array( array( 'path' => '0.1', 'block_name' => 'core/image', 'class_name' => 'brand-logo', 'classes' => array( 'brand-logo' ), 'tag_name' => '' ) ),
					'forms'      => array( array( 'path' => '0.2', 'block_name' => 'core/html', 'class_name' => 'newsletter-form', 'classes' => array( 'newsletter-form' ), 'tag_name' => 'form' ) ),
					'navigation' => array( array( 'path' => '0.3', 'block_name' => 'core/group', 'class_name' => 'primary-nav', 'classes' => array( 'primary-nav' ), 'tag_name' => 'nav' ) ),
					'buttons'    => array( array( 'path' => '0.4', 'block_name' => 'core/button', 'class_name' => 'btn cta-button', 'classes' => array( 'btn', 'cta-button' ), 'tag_name' => '' ) ),
					'decorative' => array( array( 'path' => '0.5', 'block_name' => 'core/group', 'class_name' => 'glow-orb', 'classes' => array( 'glow-orb' ), 'tag_name' => '' ) ),
					'fallbacks'  => array(),
				),
			),
			'selector_provenance'   => str_contains( $html, '<nav' ) ? array(
				array(
					'source'          => array(
						'selector' => 'nav[aria-label="Primary"]',
						'tag'      => 'nav',
					),
					'generated_block' => array(
						'type'    => 'core/navigation',
						'targets' => array(
							array(
								'name'     => 'navigation-wrapper',
								'selector' => '.wp-block-navigation',
							),
						),
					),
				),
			) : array(),
			'metrics'               => array( 'total_ms' => 1.0 ),
			'source'                => array( 'bytes' => strlen( $html ), 'context' => 'block_artifact_compiler' ),
		);
	}
}

$h2bc_result = bac_compile_website_artifact(
	array(
		'files' => array(
			'home.html'  => '<!doctype html><html><body><header><nav aria-label="Primary"><a href="/">Home</a></nav><img src="assets/logo.svg" alt=""></header><main><h1>Home</h1><form class="newsletter-form"><button>Join</button></form><div class="glow-orb"></div><a class="btn cta-button" href="/book/">Book</a></main></body></html>',
			'about.html' => '<!doctype html><html><body><header><nav aria-label="Primary"><a href="/">Home</a></nav><img src="assets/logo.svg" alt=""></header><main><h1>About</h1></main></body></html>',
			'style.css'  => 'nav{display:flex}.btn{background:#111;color:#fff}.glow-orb{position:absolute}.newsletter-form{display:grid}.reveal{opacity:0;transform:translateY(1rem)}',
		),
	)
);
$assert( 'success' === ( $h2bc_result['status'] ?? '' ), 'H2BC result API path compiles without fallback policy' );
$assert( 1 === ( $h2bc_result['bfb_report']['h2bc_result']['asset_reference_count'] ?? null ), 'BAC report includes H2BC asset reference count' );
$assert( 1 === ( $h2bc_result['bfb_report']['h2bc_result']['navigation_candidate_count'] ?? null ), 'BAC report includes H2BC navigation candidate count' );
$assert( 1 === ( $h2bc_result['bfb_report']['h2bc_result']['selector_provenance_count'] ?? null ), 'BAC report includes H2BC selector provenance count' );
$assert( ! empty( $h2bc_result['wordpress_artifacts']['asset_references'] ?? array() ), 'BAC exposes merged H2BC asset references' );
$assert( ! empty( $h2bc_result['wordpress_artifacts']['navigation_candidates'] ?? array() ), 'BAC exposes merged H2BC navigation candidates' );
$assert( 'nav[aria-label="Primary"]' === ( $h2bc_result['wordpress_artifacts']['selector_provenance'][0]['source']['selector'] ?? '' ), 'BAC exposes entry H2BC selector provenance' );
$assert( ! empty( $h2bc_result['wordpress_artifacts']['documents'][0]['asset_references'] ?? array() ), 'document artifacts preserve H2BC asset references' );
$assert( 'nav[aria-label="Primary"]' === ( $h2bc_result['wordpress_artifacts']['documents'][0]['selector_provenance'][0]['source']['selector'] ?? '' ), 'document artifacts preserve H2BC selector provenance' );
$assert( ! empty( $h2bc_result['wordpress_artifacts']['template_parts'][0]['navigation_candidates'] ?? array() ), 'template part artifacts preserve H2BC navigation candidates' );
$repair = $h2bc_result['wordpress_artifacts']['visual_repair'] ?? array();
$repair_metadata = $h2bc_result['wordpress_artifacts']['visual_repair_metadata'] ?? array();
$repair_styles = $repair['styles'] ?? array();
$repair_css = implode( "\n", array_map( static fn ( array $style ): string => (string) ( $style['content'] ?? '' ), $repair_styles ) );
$assert( 'block-artifact-compiler/visual-repair-artifacts/v1' === ( $repair['schema'] ?? '' ), 'BAC exposes visual repair artifact schema' );
$assert( ! empty( $repair_metadata['categories']['groups'] ?? array() ), 'BAC preserves group repair metadata' );
$assert( ! empty( $repair_metadata['categories']['images'] ?? array() ), 'BAC preserves image repair metadata' );
$assert( ! empty( $repair_metadata['categories']['forms'] ?? array() ), 'BAC preserves form repair metadata' );
$assert( ! empty( $repair_metadata['categories']['navigation'] ?? array() ), 'BAC preserves navigation repair metadata' );
$assert( ! empty( $repair_metadata['categories']['buttons'] ?? array() ), 'BAC preserves button repair metadata' );
$assert( ! empty( $repair_metadata['categories']['decorative'] ?? array() ), 'BAC preserves decorative repair metadata' );
$assert( str_contains( $repair_css, '.wp-block-group.is-layout-flow' ), 'BAC emits group layout repair CSS', $repair_css );
$assert( str_contains( $repair_css, '.wp-block-group.primary-nav' ), 'BAC emits navigation selector bridge CSS', $repair_css );
$assert( str_contains( $repair_css, '.wp-block-button.btn .wp-block-button__link' ), 'BAC emits button wrapper bridge CSS', $repair_css );
$assert( str_contains( $repair_css, '.editor-styles-wrapper .wp-block-group.glow-orb' ), 'BAC emits decorative editor repair CSS', $repair_css );
$assert( 'nav[aria-label="Primary"]' === ( $h2bc_result['wordpress_artifacts']['template_parts'][0]['selector_provenance'][0]['source']['selector'] ?? '' ), 'template part artifacts preserve H2BC selector provenance' );

$inline_icon_result = bac_compile_website_artifact(
	array(
		'generated_html' => '<main><svg class="icon" viewBox="0 0 24 24"><path d="M4 12h14"/></svg></main>',
	)
);
$inline_icons = $inline_icon_result['wordpress_artifacts']['svg_icon_artifacts'] ?? array();
$assert( 1 === ( $inline_icon_result['bfb_report']['h2bc_result']['svg_icon_artifact_count'] ?? null ), 'BAC report includes H2BC SVG icon artifact count' );
$assert( ! empty( $inline_icons ), 'BAC exposes merged SVG icon artifacts' );
$assert( 'entry' === ( $inline_icons[0]['scope'] ?? '' ), 'entry SVG artifact gets scope' );
$assert( str_contains( (string) ( $inline_icons[0]['content'] ?? '' ), '<path' ), 'entry SVG artifact preserves sanitized content' );
$assert( ( $inline_icons[0]['metadata']['kind'] ?? '' ) === 'inline-svg-icon', 'entry SVG artifact preserves metadata' );
$assert( count( $inline_icons ) === ( bac_summarize_result( $inline_icon_result )['svg_icon_artifact_count'] ?? null ), 'summary exposes SVG icon artifact count' );

$unsafe_svg_result = bac_compile_website_artifact(
	array(
		'generated_html' => '<main><svg viewBox="0 0 24 24"><script>alert(1)</script><path d="M0 0h1"/></svg></main>',
	)
);
$assert( ! empty( array_filter( $unsafe_svg_result['diagnostics'] ?? array(), static fn ( array $diagnostic ): bool => 'unsafe_inline_svg' === ( $diagnostic['code'] ?? '' ) ) ), 'unsafe SVG diagnostic bubbles through BAC' );
$assert( empty( $unsafe_svg_result['wordpress_artifacts']['svg_icon_artifacts'] ?? array() ), 'unsafe SVG emits no BAC icon artifact' );

$symbol_sprite_result = bac_compile_website_artifact(
	array(
		'files' => array(
			'home.html' => '<!doctype html><html><body><header><svg viewBox="0 0 24 24"><defs><symbol id="shape"><path d="M1 1h22v22H1z"/></symbol></defs><use href="#shape"/></svg></header><main><h1>Home</h1></main></body></html>',
		),
	)
);
$symbol_icons = $symbol_sprite_result['wordpress_artifacts']['svg_icon_artifacts'] ?? array();
$assert( ! empty( $symbol_icons ), 'symbol sprite SVG artifact is exposed' );
$assert( ! empty( array_filter( $symbol_icons, static fn ( array $artifact ): bool => str_contains( (string) ( $artifact['content'] ?? '' ), '<symbol id="shape"' ) && str_contains( (string) ( $artifact['content'] ?? '' ), '<use href="#shape"' ) ) ), 'local use reference survives in BAC artifact' );
$assert( ! empty( array_filter( $symbol_icons, static fn ( array $artifact ): bool => 'template_part' === ( $artifact['scope'] ?? '' ) && 'header' === ( $artifact['source_path'] ?? '' ) ) ), 'template part SVG artifact gets source scope' );

$summary = bac_summarize_result( $messy );
$assert( ( $summary['component_count'] ?? 0 ) > 0, 'summary exposes component count' );
$assert( ( $summary['source_element_count'] ?? 0 ) > 0, 'summary exposes source element count' );
$assert( ( $summary['source_css_selector_count'] ?? 0 ) > 0, 'summary exposes source CSS selector count' );

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
	),
	$fallback_options
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

$blocks = bac_compile_website_artifact(
	array(
		'generated_html' => '<main><h1>Block artifact page</h1></main>',
		'files'          => array(
			'blocks/hero/block.json'       => bac_json_encode(
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
	),
	$fallback_options
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

$plugin_bundle = bac_compile_website_artifact(
	array(
		'generated_html' => '<main><!-- wp:acme/hero {"headline":"Plugin block"} /--><!-- wp:vendor/card /--></main>',
		'files'          => array(
			'plugins/acme-blocks/acme-blocks.php'        => "<?php\n/**\n * Plugin Name: Acme Blocks\n * Description: Generated custom blocks.\n * Version: 0.1.0\n * Requires PHP: 8.1\n * Text Domain: acme-blocks\n */",
			'plugins/acme-blocks/blocks/hero/block.json' => bac_json_encode(
				array(
					'apiVersion' => 3,
					'name'       => 'acme/hero',
					'title'      => 'Plugin Hero',
					'category'   => 'design',
				),
				JSON_UNESCAPED_SLASHES
			),
			'plugins/acme-blocks/blocks/hero/index.js'   => 'wp.blocks.registerBlockType("acme/hero", {});',
		),
	),
	$fallback_options
);
$plugins = $plugin_bundle['wordpress_artifacts']['plugins'] ?? array();
$assert( 1 === count( $plugins ), 'plugin header files are promoted into plugin artifacts' );
$plugin = $plugins[0] ?? array();
$assert( 'chubes4/wordpress-plugin-artifact/v1' === ( $plugin['schema'] ?? '' ), 'plugin artifact exposes contract schema' );
$assert( 'acme-blocks' === ( $plugin['slug'] ?? '' ), 'plugin artifact exposes inferred slug' );
$assert( 'Acme Blocks' === ( $plugin['headers']['name'] ?? '' ), 'plugin artifact preserves Plugin Name header' );
$assert( '8.1' === ( $plugin['headers']['requires_php'] ?? '' ), 'plugin artifact preserves Requires PHP header' );
$assert( 'plugins/acme-blocks/acme-blocks.php' === ( $plugin['plugin_file'] ?? '' ), 'plugin artifact exposes primary plugin file' );
$assert( 'acme/hero' === ( $plugin['blocks'][0]['name'] ?? '' ), 'plugin artifact links generated block types in the plugin directory' );

$requirements = $plugin_bundle['wordpress_artifacts']['requirements'] ?? array();
$assert( 1 === count( $requirements['plugins'] ?? array() ), 'requirements expose provided plugin artifacts' );
$assert( 'provided' === ( $requirements['plugins'][0]['status'] ?? '' ), 'plugin requirements mark generated plugin artifacts as provided' );
$provided_block = null;
$external_block = null;
foreach ( $requirements['custom_blocks'] ?? array() as $requirement ) {
	if ( 'acme/hero' === ( $requirement['name'] ?? '' ) ) {
		$provided_block = $requirement;
	}
	if ( 'vendor/card' === ( $requirement['name'] ?? '' ) ) {
		$external_block = $requirement;
	}
}
$assert( is_array( $provided_block ), 'requirements include custom block usage satisfied by generated block.json' );
$assert( 'provided' === ( $provided_block['status'] ?? '' ), 'provided custom block requirement is marked provided' );
$assert( is_array( $external_block ), 'requirements include external custom block usage' );
$assert( 'external' === ( $external_block['status'] ?? '' ), 'external custom block requirement stays external for downstream resolution' );

$summary = bac_summarize_result( $plugin_bundle );
$assert( 1 === ( $summary['plugin_artifact_count'] ?? 0 ), 'summary exposes plugin artifact count' );
$assert( 2 === ( $summary['custom_block_requirement_count'] ?? 0 ), 'summary exposes custom block requirement count' );

fwrite( STDOUT, "contract smoke passed\n" );

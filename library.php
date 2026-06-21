<?php
/**
 * Block Artifact Compiler library bootstrap.
 *
 * @package BlockArtifactCompiler
 */

$bac_autoload = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $bac_autoload ) ) {
	require_once $bac_autoload;
}

require_once __DIR__ . '/includes/class-block-artifact-compiler.php';
require_once __DIR__ . '/includes/functions.php';

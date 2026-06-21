<?php
/**
 * Plugin Name: Block Artifact Compiler
 * Description: Legacy BAC compatibility facade; new consumers should use automattic/blocks-engine-php-transformer directly.
 * Version: 0.1.2
 * Author: Chris Huber
 * License: GPL-2.0-or-later
 * Text Domain: block-artifact-compiler
 *
 * @package BlockArtifactCompiler
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/library.php';

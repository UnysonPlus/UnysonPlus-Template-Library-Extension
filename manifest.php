<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

$manifest = [];

$manifest['name']        = __( 'Template Library', 'fw' );
$manifest['slug']        = 'unysonplus-template-library';
$manifest['description'] = __(
	'A browsable library of premade page-builder templates — sections, columns and whole pages. Install one from the catalog and it appears, ready to drop in, under the builder\'s Templates menu. Downloaded templates live in your uploads folder, so the plugin stays lean.',
	'fw'
);

$manifest['version']    = '1.1.1';
$manifest['display']     = true;
$manifest['standalone']  = true;
$manifest['thumbnail']   = 'thumbnail.svg';

// This extension only makes sense alongside the page builder (it feeds premade
// layouts into the builder's Templates menu), so pull in the shortcodes +
// page-builder extensions when it is activated.
$manifest['requirements'] = [
	'extensions' => [
		'shortcodes'   => [],
		'page-builder' => [],
	],
];

// Repository Info
$manifest['github_update'] = 'UnysonPlus/UnysonPlus-Template-Library-Extension';
$manifest['github_repo']   = 'https://github.com/UnysonPlus/UnysonPlus-Template-Library-Extension';
$manifest['github_branch'] = 'master';

// Author Info
$manifest['author']     = 'UnysonPlus';
$manifest['author_uri'] = 'https://www.lastimosa.com.ph/unysonplus';

// Meta
$manifest['license']      = 'GPL-2.0-or-later';
$manifest['text_domain']  = 'fw';
$manifest['requires_php'] = '7.4';
$manifest['requires_wp']  = '5.8';

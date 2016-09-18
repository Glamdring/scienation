<?php
/*
Plugin Name: Scienation
Plugin URI:  http://scienation.com/plugins/wordpress
Description: The scienation plugin turns a wordpress installation into a tool for scientific publishing. That way every scientist can have his own "journal". It adds the necessary semantic annotations on the content and enables additional features like peer review.
Version:     0.1
Author:      Bozhidar Bozhanov
Author URI:  http://techblog.bozho.net
License:     GPL3
License URI: https://www.gnu.org/licenses/gpl-3.0.html
*/

// don't call this class directly
if ( ! class_exists( 'WP' ) ) {
	die();
}
defined( 'ABSPATH' ) or die( 'Can\'t be invoked directly' );

//add_option("scienation_orcid", $value, $deprecated, $autoload);

add_shortcode('abstract', 'abstract_shortcode');

new Scienation_Plugin();

const PREFIX = "sc_";

class Scienation_Plugin {
	public function __construct() {
		add_action( 'wp_head', array( &$this, 'wp_head' ) );		
		add_action( 'add_meta_boxes', array( &$this, 'metaboxes' ) );
		add_action( 'save_post', array( &$this, 'post_submit_handler' ) );
		add_action( 'the_content', array( &$this, 'output_post_meta' ) );
	}
	
	public function wp_head () {
		if (is_single()) {
			$post = get_post(get_the_ID());
			echo '<script type="application/ld+json">' . PHP_EOL;
			echo '{' . PHP_EOL;
			echo '	"@context": "http://schema.org",' . PHP_EOL;
			echo '	"@type": "ScholarlyArticle",' . PHP_EOL;
			echo '	"content": ' . json_encode($post->post_content) . ',' . PHP_EOL;
			echo '	"abstract": ' . json_encode(get_post_meta($post->ID, PREFIX . 'abstract', true)) . ',' . PHP_EOL;
			echo '	"url": ' . json_encode(get_permalink()) . ',' . PHP_EOL;
			echo '}' . PHP_EOL;
			echo '</script>' . PHP_EOL;
		}
	}

	public function metaboxes() {
		add_meta_box(
			PREFIX . 'metabox',
			__( 'Scienation details', 'scienation' ),
			array(&$this, 'metabox_content'),
			'post',
			'advanced',
			'high');
			
		add_meta_box(
			PREFIX . 'abstract_metabox',
			__( 'Publication abstract', 'abstract' ),
			array(&$this, 'abstract_metabox_content'),
			'post',
			'advanced',
			'high');
			
	}
	
	public function metabox_content() {
		global $post_ID;
		$exists = in_array($PREFIX . 'enabled', get_post_custom_keys($post_ID));
		$enabled = !exists || get_post_meta($post_ID, PREFIX . 'enabled', true);
		$checked = $enabled ? ' checked' : '';
		echo '<input type="checkbox"' . $checked . ' name="'. PREFIX . 'enabled' . '" id="' . PREFIX . 'enabled' . '" /><label for="' 
			. PREFIX . 'enabled' . '">This is a scientific publication</label><br />';
			
		//TODO orcid
		echo '<label for="' . PREFIX . 'authors">Authors (comma-separated): </label><input type="text" size="40" name="'. PREFIX . 'authors' . '" id="' 
			. PREFIX . 'authors' . '" value="' . get_post_meta($post_ID, PREFIX . 'authors', true) . '"/>';
	}
	
	public function abstract_metabox_content() {
		global $post_ID;
		$abstract = get_post_meta($post_ID, PREFIX . 'abstract', true);
		wp_editor($abstract, PREFIX . "abstract", array("textarea_rows" => 5));
	}
	
	public function output_post_meta($post) {
		global $post_ID;
		$post = get_post($post_ID);
		$content = $post->post_content;
		$content_meta = "";
		if( is_single() || is_page() ) {
			$abstract = get_post_meta($post->ID, PREFIX . 'abstract', true);
			$authors = get_post_meta($post->ID, PREFIX . 'authors', true);
			  
			$content_meta .= "<strong>Authors</strong> " . $authors . "<br />";
			$content_meta .= "<h2>Abstract</h2>" . $abstract . "<br /><br /><br />";
		}
		return $content_meta . $content;
	}
	public function post_submit_handler($post_id) {
		if ( !current_user_can('edit_post', $post_id) ) { return $post_id; }
		$enabled = $_POST[PREFIX . 'enabled'];
		update_post_meta($post_id, PREFIX . 'enabled', $enabled);
		if ($enabled) {
			// TODO science branches
			update_post_meta($post_id, PREFIX . 'authors', $_POST[PREFIX . 'authors']);
			update_post_meta($post_id, PREFIX . 'abstract', $_POST[PREFIX . 'abstract']);
		}
	}
	
	//TODO bibliographic references - store just DOI/URI (canonical)
}
?>
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

define('PREFIX', 'sc_');
define('ORCID_API_URL', 'https://pub.orcid.org/v1.2/');

new Scienation_Plugin();

class Scienation_Plugin {
		
	private $publication_types = array("Original research", 
		"Review/Survey", 
		"Replication", 
		"Meta analysis", 
		"Essay/Commentary/Opinion", 
		"Research preregistration",
		"Report",		
		"Research notes",
		"Letter/communication",
		"Lecture",
		"Poster",
		"Dissertation/thesis",
		"Monopgraph"
	);
	
	private $orcid_opts = array(
	  'http' => array(
		'method' => "GET",
		'header' => "Accept: application/json"
	  )
	);
	
	public function __construct() {
		add_action( 'wp_head', array( &$this, 'wp_head' ) );		
		add_action( 'add_meta_boxes', array( &$this, 'metaboxes' ) );
		add_action( 'save_post', array( &$this, 'post_submit_handler' ) );
		add_action( 'the_content', array( &$this, 'output_post_meta' ) );
	}
	
	public function wp_head () {
		if (is_single()) {
			$post = get_post(get_the_ID());
			?>
<script type="application/ld+json">
{
	"@context": "http://schema.org",
	"@type": "ScholarlyArticle",
    "@id": "<?php echo get_permalink(); ?>",
	"authors": [
			<?php
			$list = explode(",", get_post_meta($post->ID, PREFIX . 'authors', true));
			
			$context = stream_context_create($this->orcid_opts);
			foreach ($list as $authorORCID) {
				$response = file_get_contents(ORCID_API_URL . $authorORCID, false, $context);
				if ($response) {
					$names = $this->get_author_names($response);
			?>
		{
			"@id": "http://orcid.org/<?php echo $authorORCID; ?>",
			"@type": "Person",
			"name": <?php echo json_encode($names); ?>,
		}
					<?php
				}
			}
					?>
	],
    "name": <?php echo json_encode($post->post_title); ?>,
	"about": <?php echo json_encode(get_post_meta($post->ID, PREFIX . 'abstract', true)); ?>,
    "description": <?php echo json_encode($post->post_content); ?>,
    "genre": <?php echo json_encode(get_post_meta($post->ID, PREFIX . 'publicationType', true)); ?>,
	"url": "<?php echo get_permalink(); ?>",
}
</script>
			<?php
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
			
		$authors = get_post_meta($post_ID, PREFIX . 'authors', true);
		echo '<label style="width: 230px; display: inline-block;" for="' . PREFIX . 'authors">Authors (comma-separated ORCID): </label><input type="text" size="40" name="'. PREFIX . 'authors' . '" id="' 
			. PREFIX . 'authors' . '" value="' . $authors . '"/>';
		
		$this->print_authors_names($authors);
        echo "<br />";
        
        echo '<label style="width: 230px; display: inline-block;" for="' . PREFIX . 'publicationType">Publication type: </label>';
        echo '<select name="' . PREFIX . 'publicationType" id="' . PREFIX . 'publicationType">';
        $publication_type = get_post_meta($post_ID, PREFIX . 'publicationType', true);
        foreach ($this->publication_types as $type) {
            $val = str_replace("/", "-", strtolower($type));
            $selected = "";
            if ($val == $publication_type) {
                $selected = " selected";
            }
            echo '<option value="' . $val . '"' . $selected . '>' . $type . '</option>';
        }
        echo '</select>';
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
            // TODO figures, data (Figshare) and code (Github)
            $this->update_meta($post_id, 'authors');
            $this->update_meta($post_id, 'abstract');
            $this->update_meta($post_id, 'publicationType');
        }
    }
    
    private function update_meta($post_id, $key) {
        update_post_meta($post_id, PREFIX . $key, $_POST[PREFIX . $key]);
    }
    private function print_authors_names($authors) {
        if ($authors) {
			$list = explode(",", $authors);

			if (!empty($list)) {
				echo " (";
			}
			$context = stream_context_create($this->orcid_opts);
			$delimiter = "";
			foreach ($list as $authorORCID) {
				$response = file_get_contents(ORCID_API_URL . $authorORCID, false, $context);
				if ($response) {
					echo $delimiter . $this->get_author_names($response);
					$delimiter = ", ";
				}
			}
			if (!empty($list)) {
				echo ")";
			}
		}
    }
    
    private function get_author_names($response) {
        $json = json_decode($response, true);
        $personal_details = $json["orcid-profile"]["orcid-bio"]["personal-details"];
        $given_name = $personal_details["given-names"]["value"];
        $family_name = $personal_details["family-name"]["value"];
        
        return $given_name . " " . $family_name;
    }
    //TODO bibliographic references - store just DOI/URI (canonical)
}
?>
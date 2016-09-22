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
include("includes/branches.php");

defined( 'ABSPATH' ) or die( 'Can\'t be invoked directly' );

//add_option("scienation_orcid", $value, $deprecated, $autoload);

add_shortcode('abstract', 'abstract_shortcode');

define('PREFIX', 'sc_');
define('ORCID_API_URL', 'https://pub.orcid.org/v1.2/');

new Scienation_Plugin();

class Scienation_Plugin {
	
    //TODO cache ocdit responses
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
		add_action( 'the_content', array( &$this, 'print_post_meta' ) );
        if (true || $this->is_edit_page()) {
            add_action( 'admin_enqueue_scripts', array( &$this, 'add_static_resources' ) );
			add_action( 'admin_init', array(&$this, 'button_init'));
        }
	}
	
    public function add_static_resources() {
		wp_enqueue_style( PREFIX . 'jquery-ui-style', 'http://code.jquery.com/ui/1.10.1/themes/base/jquery-ui.css');
        wp_enqueue_style( PREFIX . 'tree_style', plugins_url('jquery.tree.min.css', __FILE__));
        
        wp_enqueue_script( PREFIX . 'jquery-ui-script', 'http://code.jquery.com/ui/1.10.2/jquery-ui.js');
        wp_enqueue_script( PREFIX . 'tree_script', plugins_url('jquery.tree.min.js', __FILE__));
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
			PREFIX . 'abstract_metabox',
			__( 'Publication abstract', 'abstract' ),
			array(&$this, 'abstract_metabox_content'),
			'post',
			'advanced',
			'high');
            
		add_meta_box(
			PREFIX . 'metabox',
			__( 'Scienation details', 'scienation' ),
			array(&$this, 'metabox_content'),
			'post',
			'advanced',
			'high');
	}
	
	public function metabox_content() {
		global $post_ID;
		$exists = in_array($PREFIX . 'enabled', get_post_custom_keys($post_ID));
		$enabled = !exists || get_post_meta($post_ID, PREFIX . 'enabled', true);
		$checked = $enabled ? ' checked' : '';
		echo '<input type="checkbox"' . $checked . ' name="'. PREFIX . 'enabled' . '" id="' . PREFIX . 'enabled' . '" value="true" /><label for="' 
			. PREFIX . 'enabled' . '">This is a scientific publication</label><br />';
		
        //TODO default ORCID configuration
		$authors = get_post_meta($post_ID, PREFIX . 'authors', true);
		echo '<label style="width: 230px; display: inline-block;" for="' . PREFIX . 'authors">Authors (comma-separated ORCID): </label><input type="text" size="40" name="'. PREFIX . 'authors' . '" id="' 
			. PREFIX . 'authors' . '" value="' . $authors . '"/>';
		
		$this->print_authors_names($authors, true);
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
        echo '<br />';
        
        print_branches_html(get_post_meta($post_ID, PREFIX . 'scienceBranch'));
	}
	
	public function abstract_metabox_content() {
		global $post_ID;
		$abstract = get_post_meta($post_ID, PREFIX . 'abstract', true);
		wp_editor($abstract, PREFIX . "abstract", array("textarea_rows" => 5));
	}
    
    public function print_post_meta($post) {
        global $post_ID;
        $post = get_post($post_ID);
        $content = $post->post_content;
        $content_meta = "";
        if( (is_single() || is_page()) && get_post_meta($post_ID, PREFIX . 'enabled', true) == true) {
            $abstract = get_post_meta($post->ID, PREFIX . 'abstract', true);
            $authors = get_post_meta($post->ID, PREFIX . 'authors', true);
              
            $content_meta .= "<strong>Authors</strong> " . $this->print_authors_names($authors, false) . "<br />";
            $content_meta .= "<h2>Abstract</h2>" . $abstract . "<br /><br /><br />";
        }
        return $content_meta . $content;
    }
	
	public function button_init() {
		add_filter( 'mce_external_plugins', array( &$this, 'register_tinmymce_js' ) );
		add_filter( 'mce_buttons', array( &$this, 'register_editor_buttons' ) );
		//QTags.addButton( PREFIX . "insert-reference-button", "DOI", '<a href="https://dx.doi.org/" data-reference="true"', '</a>', '', 'Insert DOI reference', priority, instance );
	}
	
	public function register_tinmymce_js($plugin_array) {
		$plugin_array['scienation'] = plugins_url( 'tinymce3/tinymce-doi-plugin.js', __FILE__ );
		return $plugin_array;
	}
	
	public function register_editor_buttons($buttons) {
		array_push( $buttons, 'scienation' );
		return $buttons;
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
			
			// cleanup the multi-value meta first
			delete_post_meta($post_id, PREFIX . "scienceBranch");
			// then add all submitted values
			if ($_POST['scienceBranch']) {
				foreach ($_POST['scienceBranch'] as $branch) {
					add_post_meta($post_id, PREFIX . "scienceBranch", $branch);
				}
			}
        }
    }
    
    private function update_meta($post_id, $key) {
        update_post_meta($post_id, PREFIX . $key, $_POST[PREFIX . $key]);
    }
    private function print_authors_names($authors, $parentheses) {
        if ($authors) {
			$list = explode(",", $authors);

			if ($parentheses && !empty($list)) {
				echo " (";
			}
			$context = stream_context_create($this->orcid_opts);
			$delimiter = "";
			foreach ($list as $authorORCID) {
				$response = file_get_contents(ORCID_API_URL . $authorORCID, false, $context);
				if ($response) {
					echo $delimiter . '<a href="http://orcid.org/' . $authorORCID . '" target="_blank">' . $this->get_author_names($response) . '</a>';
					$delimiter = ", ";
				}
			}
			if ($parentheses && !empty($list)) {
				echo ")";
			}
		} else {
            echo 'Don\'t have an ORCID? <a href="http://orcid.org/" target="_blank">Get one in 30 seconds here.</a>';
        }
    }
    
    private function get_author_names($response) {
        $json = json_decode($response, true);
        $personal_details = $json["orcid-profile"]["orcid-bio"]["personal-details"];
        $given_name = $personal_details["given-names"]["value"];
        $family_name = $personal_details["family-name"]["value"];
        
        return $given_name . " " . $family_name;
    }
    
    private function is_edit_page($new_edit = null){
        global $pagenow;
        //make sure we are on the backend
        if (!is_admin()) {
            return false;
        }


        if($new_edit == "edit") {
            return in_array( $pagenow, array( 'post.php',  ) );
        } elseif($new_edit == "new") { //check for new post page
            return in_array( $pagenow, array( 'post-new.php' ) );
        }  else { //check for either new or edit
            return in_array( $pagenow, array( 'post.php', 'post-new.php' ) );
        }
    }
    //TODO bibliographic references - store just DOI/URI (canonical)
	//TODO peer review
}
?>
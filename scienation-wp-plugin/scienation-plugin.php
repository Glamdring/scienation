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
include(plugin_dir_path( __FILE__ ) . "includes/branches.php");
include( plugin_dir_path( __FILE__ ) . "includes/options.php");
defined( 'ABSPATH' ) or die( 'Can\'t be invoked directly' );

//add_option("scienation_orcid", $value, $deprecated, $autoload);

add_shortcode('abstract', 'abstract_shortcode');

define('PREFIX', 'sc_');
define('ORCID_API_URL', 'https://pub.orcid.org/v1.2/');
define('ORCID_MESSAGE', 'Don\'t have an ORCID? <a href="http://orcid.org/" target="_blank">Get one in 30 seconds here.</a>');

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
		"Monopgraph",
		"Other"
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
		add_action( 'comment_form', array (&$this, 'extend_comment_form') );
		add_action( 'wp_insert_comment', array (&$this, 'comment_submit_handler') );
        if ($this->is_edit_page()) {
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
    
	// JSON-LD output
	// ================================================
	
	public function wp_head () {
		if (is_single()) {
			$post = get_post(get_the_ID());
			?>
<script type="application/ld+json">
{
	"@context": "http://schema.org",
	"@type": "ScholarlyArticle",
    "@id": "<?php echo get_permalink(); ?>",
	"author": [
			<?php
			$list = explode(",", get_post_meta($post->ID, PREFIX . 'authors', true));
			$separator = "";
			$context = stream_context_create($this->orcid_opts);
			foreach ($list as $authorORCID) {
				echo $separator;
				$separator = ",";
				$response = file_get_contents(ORCID_API_URL . $authorORCID, false, $context);
				if ($response) {
					$names = $this->get_author_names($response);
			?>{
			"@id": "http://orcid.org/<?php echo $authorORCID; ?>",
			"@type": "Person",
			"name": <?php echo json_encode($names); ?>
		}
					<?php
				}
			}
					?>
	],
	"citation": [
			<?php
			$list = get_post_meta($post->ID, PREFIX . 'reference');
			$separator = "";
			foreach ($list as $reference) {
				echo $separator;
				$separator = ",";
			?>{
				"@type": "ScholarlyArticle",
				"@id": "<?php echo $reference; ?>",
				"url": "<?php echo $reference; ?>"
			}
			<?php
			}
			?>
	],
	"review": [
		<?php
		$list = get_comments('post_id=' . $post->ID);
		$separator = "";
		foreach ($list as $comment) {
		echo $separator;
		$separator = ",";
		$context = stream_context_create($this->orcid_opts);
		$orcid = get_comment_meta($comment->comment_ID, "reviewer_orcid", true);
		if ($orcid) {
			$response = file_get_contents(ORCID_API_URL . $orcid, false, $context);
			if ($response) {
				$names = $this->get_author_names($response);
		?>{
			"@type": "Review",
			"@id": "<?php echo get_permalink() . '#comment-' . $comment->comment_ID; ?>",
			"url": "<?php echo get_permalink() . '#comment-' . $comment->comment_ID; ?>",
			"author": {
				"@id": "http://orcid.org/<?php echo $authorORCID; ?>",
				"@type": "Person",
				"name": <?php echo json_encode($names); ?>
			},
			"reviewBody": <?php echo json_encode($comment->comment_content); ?>,
			"parameters": {
				"meetsScientificStandards": <?php echo get_comment_meta($comment->comment_ID, "meets_scientific_standards", true); ?>,
				"clarityOfBackground": <?php echo get_comment_meta($comment->comment_ID, "clarity_of_background", true); ?>,
				"significance": <?php echo get_comment_meta($comment->comment_ID, "significance", true); ?>,
				"studyAndDesignMethods": <?php echo get_comment_meta($comment->comment_ID, "study_design_and_methods", true); ?>,
				"noveltyOfConclusions": <?php echo get_comment_meta($comment->comment_ID, "novelty_of_conclusions", true); ?>,
				"qualityOfPresentation": <?php echo get_comment_meta($comment->comment_ID, "quality_of_presentation", true); ?>,
				"qualityOfDataAnalysis": <?php echo get_comment_meta($comment->comment_ID, "quality_of_data_analysis", true); ?>
			}
		}
		<?php
				} // end of response check
			} // end of orcid check
		} // end of loop
		?>
	],
    "name": <?php echo json_encode($post->post_title); ?>,
	"about": <?php echo json_encode(get_post_meta($post->ID, PREFIX . 'abstract', true)); ?>,
    "description": <?php echo json_encode($post->post_content); ?>,
    "genre": <?php echo json_encode(get_post_meta($post->ID, PREFIX . 'publicationType', true)); ?>,
	"datePublished": "<?php echo date('c', strtotime($post->post_date)); ?>",
	"url": "<?php echo get_permalink(); ?>"
}
</script>
			<?php
		}
	}

	// Metaboxes on edit page
	// ================================================
	
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
		$enabled_by_default = get_option("enabled_by_default", true);
		
		$enabled = (!$exists && $enabled_by_default) || get_post_meta($post_ID, PREFIX . 'enabled', true);
		
		$checked = $enabled ? ' checked' : '';
		echo '<input type="checkbox"' . $checked . ' name="'. PREFIX . 'enabled' . '" id="' . PREFIX . 'enabled' . '" value="true" /><label for="' 
			. PREFIX . 'enabled' . '">This is a scientific publication</label><br />';
		
        
		$authors = get_post_meta($post_ID, PREFIX . 'authors', true);
		if (empty($authors)) {
			$authors = get_option("orcid");
		}
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
    
	// Post page output
	// ================================================
	
    public function print_post_meta($post) {
        global $post_ID;
        $post = get_post($post_ID);
        $content = $post->post_content;
        $content_meta = "";
		$enabled = get_post_meta($post->ID, PREFIX . 'enabled', true);
        if( (is_single() || is_page()) && $enabled) {
            $abstract = get_post_meta($post->ID, PREFIX . 'abstract', true);
            $authors = get_post_meta($post->ID, PREFIX . 'authors', true);
              
            $content_meta .= "<strong>Authors</strong> " . $this->print_authors_names($authors, false) . "<br />";
            $content_meta .= "<h2>Abstract</h2>" . $abstract . "<br /><br /><br />";
        }
        return $content_meta . $content;
    }
	
	// Comment form with peer review controls
	// ================================================
	
    public function extend_comment_form($post_id) {
		$post = get_post($post_id);
		if (!get_post_meta($post_id, PREFIX . 'enabled', true)) {
			// only show peer review controls to scientific-enabled articles
			return;
		}
		?>
		<script type="text/javascript">
			function update_output(target, value) {
				var id = jQuery(target).attr("id");
				jQuery("#" + id + "_output").val(value);
			}
		</script>
		<input type="checkbox" name="peer_review_enabled" id="peer_review_enabled" checked onchange="jQuery('#peer_review_comment').toggle();" style="width: 25px;" />
		<label for="peer_review_enabled">This comment is a peer review</label>
		<div id="peer_review_comment">
			<br />
			<label for="reviewer_orcid" style="width: 230px; display: inline-block;">Your ORCID</label>
			<input type="text" name="reviewer_orcid" id="reviewer_orcid" />
			<br /><?php echo ORCID_MESSAGE; ?>
			
			<br />
			<br />
			<input type="checkbox" name="meets_scientific_standards" id="meets_scientific_standards" style="width: 25px;" value="true" />
			<label for="meets_scientific_standards" style="width: 230px; display: inline-block;">Meets basic scientific standards</label>
			
			<br />
			<label for="clarity_of_background" style="width: 230px; display: inline-block;">Clarity of background</label>
			<input type="range" id="clarity_of_background" name="clarity_of_background" min="1" value="1" max="5" step="1" oninput="update_output(this, value);" />
			<output for="clarity_of_background" id="clarity_of_background_output">1</output>
			
			<br />
			<label for="significance" style="width: 230px; display: inline-block;">Significance</label>
			<input type="range" id="significance" name="significance" min="1" value="1" max="5" step="1" oninput="update_output(this, value);" oninput="update_output(this, value);" />
			<output for="significance" id="significance_output">1</output>
			
			<br />
			<label for="study_design_and_methods" style="width: 230px; display: inline-block;">Study design and methods</label>
			<input type="range" id="study_design_and_methods" name="study_design_and_methods" min="1" value="1" max="5" step="1" oninput="update_output(this, value);" />
			<output for="study_design_and_methods" id="study_design_and_methods_output">1</output>
			
			<br />
			<label for="novelty_of_conclusions" style="width: 230px; display: inline-block;">Novelty of conclusions</label>
			<input type="range" id="novelty_of_conclusions" name="novelty_of_conclusions" min="1" value="1" max="5" step="1" oninput="update_output(this, value);" />
			<output for="novelty_of_conclusions" id="novelty_of_conclusions_output">1</output>
			
			<br />
			<label for="quality_of_presentation" style="width: 230px; display: inline-block;">Quality of presentation</label>
			<input type="range" id="quality_of_presentation" name="quality_of_presentation" min="1" value="1" max="5" step="1" oninput="update_output(this, value);" />
			<output for="quality_of_presentation" id="quality_of_presentation_output">1</output>
			
			<br />
			<label for="quality_of_data_analysis" style="width: 230px; display: inline-block;">Quality of data analysis</label>
			<input type="range" id="quality_of_data_analysis" name="quality_of_data_analysis" min="1" value="1" max="5" step="1" oninput="update_output(this, value);" />
			<output for="quality_of_data_analysis" id="quality_of_data_analysis_output">1</output>
		</div>
		<?php
	}
	
	public function comment_submit_handler($comment_id) {
		if ($_POST['peer_review_enabled']) {
			$clarity_of_background = intval($_POST['clarity_of_background']);
			$significance = intval($_POST['significance']);
			$study_design_and_methods = intval($_POST['study_design_and_methods']);
			$novelty_of_conclusions = intval($_POST['novelty_of_conclusions']);
			$quality_of_presentation = intval($_POST['quality_of_presentation']);
			$quality_of_data_analysis = intval($_POST['quality_of_data_analysis']);
			$meets_scientific_standards = $_POST['meets_scientific_standards'];
			$reviewer_orcid = $_POST['reviewer_orcid'];
			
			add_comment_meta($comment_id, 'clarity_of_background', $clarity_of_background, true);
			add_comment_meta($comment_id, 'significance', $significance, true);
			add_comment_meta($comment_id, 'study_design_and_methods', $study_design_and_methods, true);
			add_comment_meta($comment_id, 'novelty_of_conclusions', $novelty_of_conclusions, true);
			add_comment_meta($comment_id, 'quality_of_presentation', $quality_of_presentation, true);
			add_comment_meta($comment_id, 'quality_of_data_analysis', $quality_of_data_analysis, true);
			add_comment_meta($comment_id, 'reviewer_orcid', $reviewer_orcid, true);
			if ($meets_scientific_standards) {
				add_comment_meta($comment_id, 'meets_scientific_standards', "true", true);
			} else {
				add_comment_meta($comment_id, 'meets_scientific_standards', "false", true);
			}
		}
	}
	// TinyMCE reference button
	// ================================================
	
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
	
	// Submitting functions
	// ================================================
    public function post_submit_handler($post_id) {
        if ( !current_user_can('edit_post', $post_id) ) { return $post_id; }
        $enabled = $_POST[PREFIX . 'enabled'];
        update_post_meta($post_id, PREFIX . 'enabled', $enabled);
        if ($enabled) {
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
			
			$this->store_references($post_id);
        }
    }
    
	private function store_references($post_id) {
		$content = str_replace("&nbsp;", "", stripslashes($_POST['content']));
		
		//$references = preg_match_all('/<a href="([\s\S]+)" data-reference="true"/', $content);
		
		$xml = simplexml_load_string("<html>" . $content . "</html>"); //appending start and end tags to make the xml parser work
		$list = $xml->xpath("//a[@data-reference]/@href");
		delete_post_meta($post_id, PREFIX . "reference");
		foreach ($list as $reference) {
			add_post_meta($post_id, PREFIX . "reference", (string) $reference);
		}
	}
	
    private function update_meta($post_id, $key) {
        update_post_meta($post_id, PREFIX . $key, $_POST[PREFIX . $key]);
    }
	
	// Author names
	// ================================================
	
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
            echo ORCID_MESSAGE;
        }
    }
    
    private function get_author_names($response) {
        $json = json_decode($response, true);
        $personal_details = $json["orcid-profile"]["orcid-bio"]["personal-details"];
        $given_name = $personal_details["given-names"]["value"];
        $family_name = $personal_details["family-name"]["value"];
        
        return $given_name . " " . $family_name;
    }
    
	// Utilities
	// ================================================
	
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
}

//TODO register at scienation
?>
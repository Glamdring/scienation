<?php
/*
Plugin Name: Scienation
Plugin URI:	 http://scienation.com/plugins/wordpress
Description: The scienation plugin turns a wordpress installation into a tool for scientific publishing. That way every scientist can have his own "journal". It adds the necessary semantic annotations on the content and enables additional features like peer review.
Version:	 0.1
Author:		 Bozhidar Bozhanov
Author URI:	 http://techblog.bozho.net
License:	 GPL3
License URI: https://www.gnu.org/licenses/gpl-3.0.html
*/

// don't call this class directly
if ( ! class_exists( 'WP' ) ) {
	die();
}
include_once(plugin_dir_path( __FILE__ ) . "includes/options.php");
include_once(plugin_dir_path( __FILE__ ) . "dompdf/autoload.inc.php");

defined( 'ABSPATH' ) or die( 'Can\'t be invoked directly' );

define('SCN_PREFIX', 'scn_');
define('SCN_ORCID_API_URL', 'https://pub.orcid.org/v1.2/');
define('SCN_ORCID_MESSAGE', 'Don\'t have an ORCID? <a href="http://orcid.org/" target="_blank">Get one in 30 seconds here.</a>');
define('SCN_DOWNLOAD_PDF_URL', 'index.php?scienation=generate-pdf');

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

	private $review_params = array("clarity_of_background" => "Clarity of background", 
				"significance" => "Significance", 
				"study_design_and_methods" => "Study design and methods",
				"novelty_of_conclusions" => "Novelty of conclusions",
				"quality_of_presentation" => "Quality of presentation",
				"quality_of_data_analysis" => "Quality of data analysis");
				
	private $orcid_opts = array(
	  'http' => array(
		'method' => "GET",
		'header' => "Accept: application/json"
	  )
	);
	
	public function __construct() {
		add_action( 'wp_head', array( &$this, 'wp_head' ) );		
		add_action( 'add_meta_boxes', array( &$this, 'metaboxes' ) );
		add_action( 'save_post_post', array( &$this, 'post_submit_handler' ), 10, 2 );
		add_action( 'the_content', array( &$this, 'print_post_meta' ) );
		add_action( 'comment_form', array ( &$this, 'extend_comment_form') );
		add_filter( 'comment_text', array ( &$this, 'extended_comment_view'), 10, 2 );
		add_action( 'wp_insert_comment', array ( &$this, 'comment_submit_handler') );
		add_action( 'parse_request', array	(&$this, 'parse_request') );
		add_filter( 'query_vars', array	 (&$this, 'query_vars') );
		if ($this->is_edit_page()) {
			add_action( 'admin_enqueue_scripts', array( &$this, 'add_static_resources' ) );
			add_action( 'admin_init', array( &$this, 'button_init') );
		}
		wp_enqueue_style( SCN_PREFIX . 'style', plugins_url('css/main.css', __FILE__));
		wp_enqueue_script( SCN_PREFIX . 'scripts', plugins_url('scripts/scripts.js', __FILE__));
	}
	
	public function add_static_resources() {
	
		wp_enqueue_style( 'jquery-ui-style', plugins_url('css/jquery-ui.css', __FILE__));
		wp_enqueue_style( 'jstree-style', plugins_url('css/jquery.tree.min.css', __FILE__));
		
		wp_enqueue_script( 'jstree', plugins_url('scripts/jquery.tree.min.js', __FILE__), 
			array("jquery-ui-core", "jquery-ui-widget", "jquery-ui-draggable", "jquery-effects-core", "jquery-effects-blind") );
		wp_enqueue_script( SCN_PREFIX . 'branches', plugins_url('scripts/branches.js', __FILE__));
	}
	
	// JSON-LD output
	// ================================================
	
	public function wp_head () {
		rewind_posts();
		echo '<script type="application/ld+json">';
		$output = array();
		while (have_posts()) {
			the_post();
			$post = get_post();
			if (get_post_meta($post->ID, SCN_PREFIX . 'enabled', true)) {
				$post_output = array();
				$post_output['@context'] = 'http://schema.org';
				$post_output['@type'] = 'ScholarlyArticle';
				$post_output['@id'] = get_permalink();
			
				// Authors
				$authors_output = array();
				$list = explode(",", get_post_meta($post->ID, SCN_PREFIX . 'authorNames', true));
				foreach ($list as $author) {
					$author_output = array();
					$author_details = explode(':', $author);
					$names = $author_details[1];
					$author_output['@id'] = 'http://orcid.org/' . $author_details[0];
					$author_output['@type'] = 'Person';
					$author_output['name'] = $names;
					
					$authors_output[] = $author_output;
				}
				$post_output['author'] = $authors_output;
				
				// Citations/references
				$references_output = array();
				$list = get_post_meta($post->ID, SCN_PREFIX . 'reference');
				foreach ($list as $reference) {
					$reference_output = array();
					$reference_output['@type'] = "ScholarlyArticle";
					$reference_output['@id'] = $reference;
					$reference_output['url'] = $reference;
					
					$references_output[] = $reference_output;
				}
				$post_output['citation'] = $references_output;
		
				// Branches
				$branches_output = array();
				$list = get_post_meta($post->ID, SCN_PREFIX . 'scienceBranch');
				foreach ($list as $branch) {
					$branches_output[] = $branch;
				}
				$post_output['articleSection'] = $branches_output;
			
				$reviews_output = array();
				$list = get_comments('post_id=' . $post->ID);
				foreach ($list as $comment) {
					$reviewer_details = get_comment_meta($comment->comment_ID, SCN_PREFIX . "reviewer_details", true);
					if ($reviewer_details) {
						$review_output = array();
						$details = explode(":", $reviewer_details);
						$names = $details[1];
						$authorORCID = $details[0];
						
						$review_output['@type'] = "Review";
						$review_output['@id'] = get_permalink() . '#comment-' . $comment->comment_ID;;
						$review_output['@url'] = get_permalink() . '#comment-' . $comment->comment_ID;;
						$review_output['author'] = array(
							'@id' => 'http://orcid.org/' . $authorORCID,
							'@type' => "Person",
							'name' => $names
						);
						$review_output['reviewBody'] = $comment->comment_content;
						$review_params_output = array();
						foreach ($this->review_params as $review_param => $value) {
							$review_params_output[$this->camel_case($value)] = get_comment_meta($comment->comment_ID, SCN_PREFIX . $review_param, true);
						}
						$review_params_output['meetsScientificStandards'] = get_comment_meta($comment->comment_ID, SCN_PREFIX . "meets_scientific_standards", true) ? 'true' : 'false';
						$review_output['parameters'] = $review_params_output;
						
						$reviews_output[] = $review_output;
					}
				}
				$post_output['review'] = $reviews_output;
				
				$post_output['name'] = $post->post_title;
				$post_output['description'] = get_post_meta($post->ID, SCN_PREFIX . 'abstract', true);
				$post_output['genre'] = get_post_meta($post->ID, SCN_PREFIX . 'publicationType', true);
				$post_output['datePublished'] = date('c', strtotime($post->post_date));
				$post_output['url'] = get_permalink();
				$post_output['alternateName'] = get_post_meta($post->ID, SCN_PREFIX . 'hash', true);
	
				$output[] = $post_output;
			} //end of if-enabled check
		} //end of loop
		
		if (is_single()) {
			$output = $output[0];
		}
		$json = json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		if (version_compare(PHP_VERSION, "5.4", "<")) {
			// older versions don't have an option to unescape slashes, so we do it manually
			$json = str_replace("\\/", "/", $json);
		}
		echo $json;
	
		echo '</script>';
	}

	// Metaboxes on edit page
	// ================================================
	
	public function metaboxes() {
		add_meta_box(
			SCN_PREFIX . 'abstract_metabox',
			__( 'Publication abstract', 'abstract' ),
			array(&$this, 'abstract_metabox_content'),
			'post',
			'advanced',
			'high');
			
		add_meta_box(
			SCN_PREFIX . 'metabox',
			__( 'Scienation details', 'scienation' ),
			array(&$this, 'metabox_content'),
			'post',
			'advanced',
			'high');
	}
	
	public function metabox_content() {
		global $post_ID;
		$exists = in_array($SCN_PREFIX . 'enabled', get_post_custom_keys($post_ID));
		$enabled_by_default = get_option("enabled_by_default", true);
		
		$enabled = (!$exists && $enabled_by_default) || get_post_meta($post_ID, SCN_PREFIX . 'enabled', true);
		
		$checked = $enabled ? ' checked' : '';
		echo '<div><input type="checkbox"' . $checked . ' name="'. SCN_PREFIX . 'enabled' . '" id="' . SCN_PREFIX . 'enabled' . '" value="true" /><label for="' 
			. SCN_PREFIX . 'enabled' . '">This is a scientific publication</label></div>';
		
		
		$authors = get_post_meta($post_ID, SCN_PREFIX . 'authors', true);
		if (empty($authors)) {
			$authors = get_option("orcid");
		}
		echo '<div><label class="scn_metaboxLabel" for="' . SCN_PREFIX . 'authors">Authors (comma-separated ORCID): </label><input type="text" size="40" name="'. SCN_PREFIX . 'authors' . '" id="' 
			. SCN_PREFIX . 'authors' . '" value="' . $authors . '"/>';
		
		$author_names = get_post_meta($post_ID, SCN_PREFIX . 'authorNames', true);
		if ($author_names) {
			echo ' (' . $this->get_linked_author_names($author_names) . ')';
		} else {
			echo SCN_ORCID_MESSAGE;
		}
		echo '</div>';
		
		echo '<div><label class="scn_metaboxLabel" for="' . SCN_PREFIX . 'publicationType">Publication type: </label>';
		echo '<select name="' . SCN_PREFIX . 'publicationType" id="' . SCN_PREFIX . 'publicationType">';
		$publication_type = get_post_meta($post_ID, SCN_PREFIX . 'publicationType', true);
		foreach ($this->publication_types as $type) {
			$val = str_replace("/", "-", strtolower($type));
			$selected = "";
			if ($val == $publication_type) {
				$selected = " selected";
			}
			echo '<option value="' . $val . '"' . $selected . '>' . $type . '</option>';
		}
		echo '</select>';
		echo '</div>';
		
		$this->print_branches_html(get_post_meta($post_ID, SCN_PREFIX . 'scienceBranch'));
	}
	
	public function abstract_metabox_content() {
		global $post_ID;
		$abstract = get_post_meta($post_ID, SCN_PREFIX . 'abstract', true);
		wp_editor($abstract, SCN_PREFIX . "abstract", array("textarea_rows" => 5));
	}
	
	// Submitting functions
	// ================================================
	public function post_submit_handler($post_id, $post) {
		if ( !current_user_can('edit_post', $post_id) ) { return $post_id; }
		$enabled = sanitize_text_field($_POST[SCN_PREFIX . 'enabled']);
		$this->update_meta($post_id, 'enabled');
		if ($enabled) {
			$this->update_meta($post_id, 'authors', false);
			$this->update_meta($post_id, 'abstract', false, wp_kses_post($_POST[SCN_PREFIX . 'abstract']));
			$this->update_meta($post_id, 'publicationType');
			$this->update_meta($post_id, 'hash', false, hash('sha256', strip_tags($post->post_content)));
			$this->update_meta($post_id, 'authorNames', false, $this->fetch_authors_names(sanitize_text_field($_POST[SCN_PREFIX . 'authors'])));
			
			$terms = wp_get_post_tags($post_id);
			$tags = array();
			
			// the collection of tags returned is of type WP_term, whereas wp_set_post_tags require a string array (go figure..)
			// we cleanup the branch tags, and we re-add them later (thus allowing deletion)
			$existing_branches = get_post_meta($post_id, SCN_PREFIX . "scienceBranch");
			
			foreach ($terms as $term) {
				$remove = false;
				foreach ($existing_branches as $branch) {
					if ($term->name == $branch) {
						$remove = true;
					}
				}
				if (!$remove) {
					$tags[] = $term->name;
				}
			}
			
			// cleanup the multi-value meta first			
			delete_post_meta($post_id, SCN_PREFIX . "scienceBranch");
			
			// then add all submitted values
			if ($_POST['scienceBranch']) {
				foreach ($_POST['scienceBranch'] as $branch) {
					add_post_meta($post_id, SCN_PREFIX . "scienceBranch", sanitize_text_field($branch));
					$tags[] = str_replace(',', ' ', $branch);
				}
			}
			$this->store_references($post_id);
			
			// set the tags
			wp_set_post_tags($post_id, $tags, false);
		}
	}
	
	private function store_references($post_id) {
		$content = str_replace("&nbsp;", "", stripslashes($_POST['content']));

		$xml = simplexml_load_string("<html>" . $content . "</html>"); //appending start and end tags to make the xml parser work
		$list = $xml->xpath("//a[@data-reference]/@href");
		delete_post_meta($post_id, SCN_PREFIX . "reference");
		foreach ($list as $reference) {
			add_post_meta($post_id, SCN_PREFIX . "reference", (string) $reference);
		}
	}
	
	private function update_meta($post_id, $key, $sanitize = true, $value = null) {
		if ($value == null) {
			if ($sanitize) {
				$value = sanitize_text_field($_POST[SCN_PREFIX . $key]);
			} else {
				$value = $_POST[SCN_PREFIX . $key];
			}
		}
		update_post_meta($post_id, SCN_PREFIX . $key, $value);
	}
	
	// Post page output (content & comments)
	// ================================================
	
	public function print_post_meta($post) {
		global $post_ID;
		$post = get_post($post_ID); //TODO test if needed
		$content = $post->post_content;
		$content_meta = "";
		$enabled = get_post_meta($post->ID, SCN_PREFIX . 'enabled', true);
		if($enabled) {
			$abstract = get_post_meta($post->ID, SCN_PREFIX . 'abstract', true);
			$authors = get_post_meta($post->ID, SCN_PREFIX . 'authorNames', true);
			
			$content_meta .= '<div><strong>Authors:</strong> ' . $this->get_linked_author_names($authors) . '</div>';
			$content_meta .= '<div class="scn_abstract"><h3>Abstract</h3>' . $abstract . '</div>';
		}
		
		$pdf_download = '<div class="scn_pdfDownload"><a href="' . SCN_DOWNLOAD_PDF_URL . '&post_id=' . $post->ID . '">Download PDF</a></div>';
		return $content_meta . $content . $pdf_download;
	}
	
	public function extended_comment_view($text, $comment) {
		if (get_comment_meta($comment->comment_ID, SCN_PREFIX . "reviewer_details", true)) {
			$text .= '<div><span class="scn_metaboxLabel">Meets basic scientific standards?</span>: ' . (get_comment_meta($comment->comment_ID, SCN_PREFIX . 'meets_scientific_standards', true) ? 'Yes' : 'No') . '</div>';
			foreach ($this->review_params as $id => $title) {
				$text .= '<div><span class="scn_metaboxLabel">' . $title . '</span>: ' . esc_html(get_comment_meta($comment->comment_ID, SCN_PREFIX . $id, true)) . '</div>';
			}
		}
		return $text;
	}
	
	// Comment form with peer review controls
	// ================================================
	
	public function extend_comment_form($post_id) {
		$post = get_post($post_id);
		if (!get_post_meta($post_id, SCN_PREFIX . 'enabled', true)) {
			// only show peer review controls to scientific-enabled articles
			return;
		}
	?>
		<input type="checkbox" name="peer_review_enabled" id="peer_review_enabled" checked onchange="jQuery('#scn_peerReviewComment').toggle();" style="width: 25px;" />
		<label for="peer_review_enabled">This comment is a peer review</label>
		<div id="scn_peerReviewComment">
	  
			<label for="reviewer_orcid" class="scn_metaboxLabel">Your ORCID</label>
			<input type="text" name="reviewer_orcid" id="reviewer_orcid" />
	  
			<div><?php echo SCN_ORCID_MESSAGE; ?></div>
			
			<div>
				<input type="checkbox" name="meets_scientific_standards" id="meets_scientific_standards" style="width: 25px;" value="true" />
				<label for="meets_scientific_standards" style="width: 230px; display: inline-block;">Meets basic scientific standards</label>
			</div>
		
			<?php
			foreach ($this->review_params as $id => $title) {
				?>
				<div>
					<label for="<?php echo $id; ?>" class="scn_metaboxLabel"><?php echo $title; ?></label>
					<input type="range" id="<?php echo $id; ?>" name="<?php echo $id; ?>" min="1" value="1" max="5" step="1" oninput="update_output(this, value);" />
					<output for="<?php echo $id; ?>" id="<?php echo $id; ?>_output">1</output>
				</div>
				<?php
			}
			?>
		</div>
		<?php
	}
	
	public function comment_submit_handler($comment_id) {
		//TODO orcid oauth login
		if ($_POST['peer_review_enabled']) {
			foreach ($this->review_params as $key => $value) {
				$param = intval($_POST[$key]);
				add_comment_meta($comment_id, SCN_PREFIX . $key, $param, true);			  
			}
			
			$meets_scientific_standards = $_POST['meets_scientific_standards'];
			$reviewer_orcid = sanitize_text_field($_POST['reviewer_orcid']);

			add_comment_meta($comment_id, SCN_PREFIX . 'reviewer_orcid', $reviewer_orcid, true);
			add_comment_meta($comment_id, SCN_PREFIX . 'reviewer_details', $this->fetch_authors_names($reviewer_orcid), true);
			if ($meets_scientific_standards) {
				add_comment_meta($comment_id, SCN_PREFIX . 'meets_scientific_standards', "true", true);
			} else {
				add_comment_meta($comment_id, SCN_PREFIX . 'meets_scientific_standards', "false", true);
			}
		}
	}
	// TinyMCE reference button
	// ================================================
	
	public function button_init() {
		add_filter( 'mce_external_plugins', array( &$this, 'register_tinmymce_js' ) );
		add_filter( 'mce_buttons', array( &$this, 'register_editor_buttons' ) );
	}
	
	public function register_tinmymce_js($plugin_array) {
		$plugin_array['scienation'] = plugins_url( 'tinymce3/tinymce-doi-plugin.js', __FILE__ );
		return $plugin_array;
	}
	
	public function register_editor_buttons($buttons) {
		array_push( $buttons, 'scienation' );
		return $buttons;
	}
	
	// Author names
	// ================================================
	
	private function fetch_authors_names($authors) {
		$result = "";
		if ($authors) {
			$list = explode(",", sanitize_text_field($authors));

			$context = stream_context_create($this->orcid_opts);
			$delimiter = "";
			foreach ($list as $authorORCID) {
				$response = file_get_contents(SCN_ORCID_API_URL . $authorORCID, false, $context);
				if ($response) {
					$result .= $delimiter . $authorORCID . ':' . $this->get_author_names($response);
					$delimiter = ", ";
				}
			}
		}
		return $result;
	}
	
	private function get_linked_author_names($author_names) {
		$result = '';
		$list = explode(',', $author_names);
		$delimiter = '';
		foreach ($list as $author) {
			$author_details = explode(':', $author);
			$result .= $delimiter . '<a href="http://orcid.org/' . $author_details[0] . '" target="_blank">' . $author_details[1] . '</a>';
			$delimiter = ', ';
		}
		return $result;
	}
	private function get_author_names($response) {
		$json = json_decode($response, true);
		$personal_details = $json["orcid-profile"]["orcid-bio"]["personal-details"];
		$given_name = $personal_details["given-names"]["value"];
		$family_name = $personal_details["family-name"]["value"];
		
		return $given_name . " " . $family_name;
	}
	
	// PDF generation
	// ================================================
	
	public function parse_request($wp) {
		// only process requests with "scienation=generate-pdf&"
		if (array_key_exists('scienation', $wp->query_vars) 
			&& $wp->query_vars['scienation'] == 'generate-pdf') {

			$posts = get_posts(array('include' => $wp->query_vars['post_id']));
			$post = reset($posts);
			$this->generate_pdf($post);
		}
	}

	public function query_vars($vars) {
		array_push($vars, 'scienation', 'post_id');
		return $vars;
	}

	private function generate_pdf($post) {
		$content = $post->post_content;
		$title = $post->post_title;
		$permalink = get_permalink($post);
		$authors = get_post_meta($post->ID, SCN_PREFIX . "authors", true);
	
		ob_start(); 
		?>
		<html>
			<h1 style="text-align: center;"><?php echo $title; ?></h1>
			<?php
			$list = explode(',', get_post_meta($post->ID, SCN_PREFIX . "authorNames", true));
			foreach ($list as $author) {
			?>
				<h3><?php 
				$authorDetails = explode(':', $author);
				echo $authorDetails[1]; 
				?></h3>
			<?php } ?>
			<h3>Abstract</h3><?php echo get_post_meta($post->ID, SCN_PREFIX . "abstract", true);?>
			<hr style="margin-top: 10px; margin-bottom: 10px;"/>
			<div style="margin-bottom: 10px;">
			<?php echo $content; ?>
			</div>
			<a href="<?php echo $permalink; ?>">You can peer-review this publication here</a>
		</html>
		<?php
		$dompdf = new Dompdf\Dompdf();
		$dompdf->loadHtml(ob_get_clean());

		// (Optional) Setup the paper size and orientation
		$dompdf->setPaper('A4', 'portrait');

		// Render the HTML as PDF
		$dompdf->render();

		// Output the generated PDF to Browser
		$dompdf->stream($title);
	}
  
	private function print_branches_html($selected) {
		$selectedJSArray = '';
		$delimiter = '';
		foreach ($selected as $branch) {
			$selectedJSArray .= $delimiter . '"' . $branch . '"';
			$delimiter = ',';
		}
		?>
		<script type="text/javascript">
			var scienationOptions = {
				selected: [<?php echo $selectedJSArray; ?>],
				branchesUrl: "<?php echo plugins_url('includes/branches.json', __FILE__); ?>"
			};
		</script>
		<input type="text" style="width: 433px;" id="scn_branchSearchBox" placeholder="Select branches of science..." />
		<div id="scn_branches" style="height: 310px; overflow: auto;"></div>		  
		<?php
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
	
	public function camel_case($str) {
		// non-alpha and non-numeric characters become spaces
		$str = preg_replace('/[^a-z0-9]+/i', ' ', $str);
		$str = trim($str);
		// uppercase the first character of each word
		$str = ucwords($str);
		$str = str_replace(" ", "", $str);
		$str = lcfirst($str);

		return $str;
	}
}
?>
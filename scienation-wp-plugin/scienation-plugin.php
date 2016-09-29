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
include_once(plugin_dir_path( __FILE__ ) . "includes/branches.php");
include_once(plugin_dir_path( __FILE__ ) . "includes/options.php");
include_once(plugin_dir_path( __FILE__ ) . "dompdf/autoload.inc.php");

defined( 'ABSPATH' ) or die( 'Can\'t be invoked directly' );

add_shortcode('abstract', 'abstract_shortcode');

define('PREFIX', 'sc_');
define('ORCID_API_URL', 'https://pub.orcid.org/v1.2/');
define('ORCID_MESSAGE', 'Don\'t have an ORCID? <a href="http://orcid.org/" target="_blank">Get one in 30 seconds here.</a>');
define('DOWNLOAD_PDF_URL', 'index.php?scienation=generate-pdf');

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
        add_action( 'parse_request', array  (&$this, 'parse_request') );
        add_filter( 'query_vars', array  (&$this, 'query_vars') );
        if ($this->is_edit_page()) {
            add_action( 'admin_enqueue_scripts', array( &$this, 'add_static_resources' ) );
            add_action( 'admin_init', array( &$this, 'button_init') );
        }
		wp_enqueue_style( PREFIX . 'style', plugins_url('css/main.css', __FILE__));
		wp_enqueue_script( PREFIX . 'scripts', plugins_url('scripts/scripts.js', __FILE__));
    }
    
    public function add_static_resources() {
      /*
        Also, did you just load 1MB of assets so that an admin can set a
        checkbox?
        http://microjs.com/#tree
       */
        wp_enqueue_style( 'jquery-ui-style', 'http://code.jquery.com/ui/1.11.3/themes/smoothness/jquery-ui.css');
        wp_enqueue_style( 'jstree-style', plugins_url('css/jquery.tree.min.css', __FILE__));
        
		// prefix needed due to conflicts. Can't use built-in jquery-ui as it doesn't work with jstree
		wp_enqueue_script( PREFIX . 'jquery-ui', 'http://code.jquery.com/ui/1.11.3/jquery-ui.js');
        wp_enqueue_script( 'jstree', plugins_url('scripts/jquery.tree.min.js', __FILE__));
        wp_enqueue_script( PREFIX . 'branches', plugins_url('scripts/branches.js', __FILE__));
    }
    
    // JSON-LD output
    // ================================================
    
    public function wp_head () {
    // NOTE: If my syntax highlighter gets confused, your code is too complex.
    // PHP/WP supports templates.
    // Also, PHP can generate JSON
        if ($this->is_edit_page()) {
            print_branches_html(get_post_meta($post_ID, PREFIX . 'scienceBranch'));
        }
        
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
            $list = explode(",", get_post_meta($post->ID, PREFIX . 'authorNames', true));
            $separator = "";
            foreach ($list as $author) {
                echo $separator;
                $separator = ",";
                $author_details = explode(':', $author);
                $names = $author_details[1];
            ?>{
            "@id": "http://orcid.org/<?php echo $author_details[0]; ?>",
            "@type": "Person",
            "name": <?php echo json_encode($names); ?>
        }
                    <?php
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
    "articleSection": [
            <?php
            $list = get_post_meta($post->ID, PREFIX . 'scienceBranch');
            $separator = "";
            foreach ($list as $branch) {
                echo $separator . json_encode($branch);
                $separator = ",";
            }
            ?>
    ],
    "review": [
        <?php
        $list = get_comments('post_id=' . $post->ID);
        $separator = "";
        foreach ($list as $comment) {
            $reviewer_details = get_comment_meta($comment->comment_ID, PREFIX . "reviewer_details", true);
            if ($reviewer_details) {
				echo $separator;
				$separator = ",";
				$details = explode(":", $reviewer_details);
				$names = $details[1];
				$authorORCID = $details[0];
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
                    "meetsScientificStandards": <?php echo get_comment_meta($comment->comment_ID, PREFIX . "meets_scientific_standards", true); ?>,
                    "clarityOfBackground": <?php echo get_comment_meta($comment->comment_ID, PREFIX . "clarity_of_background", true); ?>,
                    "significance": <?php echo get_comment_meta($comment->comment_ID, PREFIX . "significance", true); ?>,
                    "studyAndDesignMethods": <?php echo get_comment_meta($comment->comment_ID, PREFIX . "study_design_and_methods", true); ?>,
                    "noveltyOfConclusions": <?php echo get_comment_meta($comment->comment_ID, PREFIX . "novelty_of_conclusions", true); ?>,
                    "qualityOfPresentation": <?php echo get_comment_meta($comment->comment_ID, PREFIX . "quality_of_presentation", true); ?>,
                    "qualityOfDataAnalysis": <?php echo get_comment_meta($comment->comment_ID, PREFIX . "quality_of_data_analysis", true); ?>
                }
            }
            <?php
                } // end of metadata check
            } // end of loop
            ?>
    ],
    "name": <?php echo json_encode($post->post_title); ?>,
    "description": <?php echo json_encode(get_post_meta($post->ID, PREFIX . 'abstract', true)); ?>,
    "text": <?php echo json_encode($post->post_content); ?>,
    "genre": <?php echo json_encode(get_post_meta($post->ID, PREFIX . 'publicationType', true)); ?>,
    "datePublished": "<?php echo date('c', strtotime($post->post_date)); ?>",
    "url": "<?php echo get_permalink(); ?>",
    "alternateName": <?php echo json_encode(get_post_meta($post->ID, PREFIX . 'hash', true)); ?>
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
        echo '<div><input type="checkbox"' . $checked . ' name="'. PREFIX . 'enabled' . '" id="' . PREFIX . 'enabled' . '" value="true" /><label for="' 
            . PREFIX . 'enabled' . '">This is a scientific publication</label></div>';
        
        
        $authors = get_post_meta($post_ID, PREFIX . 'authors', true);
        if (empty($authors)) {
            $authors = get_option("orcid");
        }
        echo '<div><label class="metaboxLabel" for="' . PREFIX . 'authors">Authors (comma-separated ORCID): </label><input type="text" size="40" name="'. PREFIX . 'authors' . '" id="' 
            . PREFIX . 'authors' . '" value="' . $authors . '"/>';
        
        $author_names = get_post_meta($post_ID, PREFIX . 'authorNames', true);
        if ($author_names) {
			echo ' (' . $this->get_linked_author_names($author_names) . ')';
        } else {
            echo ORCID_MESSAGE;
        }
        echo '</div>';
		
        echo '<div><label class="metaboxLabel" for="' . PREFIX . 'publicationType">Publication type: </label>';
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
        echo '</div>';
        
        print_branches_html(get_post_meta($post_ID, PREFIX . 'scienceBranch'));
    }
    
    public function abstract_metabox_content() {
        global $post_ID;
        $abstract = get_post_meta($post_ID, PREFIX . 'abstract', true);
        wp_editor($abstract, PREFIX . "abstract", array("textarea_rows" => 5));
    }
    
    // Submitting functions
    // ================================================
    public function post_submit_handler($post_id, $post) {
        if ( !current_user_can('edit_post', $post_id) ) { return $post_id; }
        $enabled = $_POST[PREFIX . 'enabled'];
        $this->update_meta($post_id, 'enabled');
        if ($enabled) {
            $this->update_meta($post_id, 'authors');
            $this->update_meta($post_id, 'abstract');
            $this->update_meta($post_id, 'publicationType');
            $this->update_meta($post_id, 'hash', hash('sha256', strip_tags($post->post_content)));
            $this->update_meta($post_id, 'authorNames', $this->fetch_authors_names($_POST[PREFIX . 'authors']));
            
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

        $xml = simplexml_load_string("<html>" . $content . "</html>"); //appending start and end tags to make the xml parser work
        $list = $xml->xpath("//a[@data-reference]/@href");
        delete_post_meta($post_id, PREFIX . "reference");
        foreach ($list as $reference) {
            add_post_meta($post_id, PREFIX . "reference", (string) $reference);
        }
    }
    
    private function update_meta($post_id, $key, $value = null) {
        if ($value == null) {
            $value = $_POST[PREFIX . $key];
        }
        update_post_meta($post_id, PREFIX . $key, $value);
    }
    
    // Post page output (content & comments)
    // ================================================
    
    public function print_post_meta($post) {
        global $post_ID;
        $post = get_post($post_ID); //TODO test if needed
        $content = $post->post_content;
        $content_meta = "";
        $enabled = get_post_meta($post->ID, PREFIX . 'enabled', true);
        if( (is_single() || is_page()) && $enabled) {
            $abstract = get_post_meta($post->ID, PREFIX . 'abstract', true);
            $authors = get_post_meta($post->ID, PREFIX . 'authorNames', true);
			
            $content_meta .= '<div><strong>Authors:</strong> ' . $this->get_linked_author_names($authors) . '</div>';
            $content_meta .= '<div class="abstract"><h3>Abstract</h3>' . $abstract . '</div>';
        }
        
        $pdf_download = '<div class="pdfDownload"><a href="' . DOWNLOAD_PDF_URL . '&post_id=' . $post->ID . '">Download PDF</a></div>';
        return $content_meta . $content . $pdf_download;
    }
    
    public function extended_comment_view($text, $comment) {
		if (get_comment_meta($comment->comment_ID, PREFIX . "reviewer_details", true)) {
			$text .= '<div><span class="metaboxLabel">Meets basic scientific standards?</span>: ' . (get_comment_meta($comment->comment_ID, PREFIX . 'meets_scientific_standards', true) ? 'Yes' : 'No') . '</div>';
			foreach ($this->review_params as $id => $title) {
				$text .= '<div><span class="metaboxLabel">' . $title . '</span>: ' . get_comment_meta($comment->comment_ID, PREFIX . $id, true) . '</div>';
			}
		}
		return $text;
    }
    
    // Comment form with peer review controls
    // ================================================
    
    public function extend_comment_form($post_id) {
        $post = get_post($post_id);
        if (!get_post_meta($post_id, PREFIX . 'enabled', true)) {
            // only show peer review controls to scientific-enabled articles
            return;
        }

    /*
    NOTE: 
    $(document).on('change', '#peer_review_enabled', function() {
      ...
      return false;
    });
    */
    ?>
        <input type="checkbox" name="peer_review_enabled" id="peer_review_enabled" checked onchange="jQuery('#peer_review_comment').toggle();" style="width: 25px;" />
        <label for="peer_review_enabled">This comment is a peer review</label>
        <div id="peerReviewComment">
      
            <label for="reviewer_orcid" class="metaboxLabel">Your ORCID</label>
            <input type="text" name="reviewer_orcid" id="reviewer_orcid" />
      
            <div><?php echo ORCID_MESSAGE; ?></div>
            
            <div>
                <input type="checkbox" name="meets_scientific_standards" id="meets_scientific_standards" style="width: 25px;" value="true" />
                <label for="meets_scientific_standards" style="width: 230px; display: inline-block;">Meets basic scientific standards</label>
            </div>
        
            <?php
            foreach ($this->review_params as $id => $title) {
                ?>
                <div>
                    <label for="<?php echo $id; ?>" class="metaboxLabel"><?php echo $title; ?></label>
                    <input type="range" id="<?php echo $id; ?>" name="clarity_of_background" min="1" value="1" max="5" step="1" oninput="update_output(this, value);" />
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
            $clarity_of_background = intval($_POST['clarity_of_background']);
            $significance = intval($_POST['significance']);
            $study_design_and_methods = intval($_POST['study_design_and_methods']);
            $novelty_of_conclusions = intval($_POST['novelty_of_conclusions']);
            $quality_of_presentation = intval($_POST['quality_of_presentation']);
            $quality_of_data_analysis = intval($_POST['quality_of_data_analysis']);
            $meets_scientific_standards = $_POST['meets_scientific_standards'];
            $reviewer_orcid = $_POST['reviewer_orcid'];

            add_comment_meta($comment_id, PREFIX . 'clarity_of_background', $clarity_of_background, true);
            add_comment_meta($comment_id, PREFIX . 'significance', $significance, true);
            add_comment_meta($comment_id, PREFIX . 'study_design_and_methods', $study_design_and_methods, true);
            add_comment_meta($comment_id, PREFIX . 'novelty_of_conclusions', $novelty_of_conclusions, true);
            add_comment_meta($comment_id, PREFIX . 'quality_of_presentation', $quality_of_presentation, true);
            add_comment_meta($comment_id, PREFIX . 'quality_of_data_analysis', $quality_of_data_analysis, true);
            add_comment_meta($comment_id, PREFIX . 'reviewer_orcid', $reviewer_orcid, true);
			add_comment_meta($comment_id, PREFIX . 'reviewer_details', $this->fetch_authors_names($reviewer_orcid), true);
            if ($meets_scientific_standards) {
                add_comment_meta($comment_id, PREFIX . 'meets_scientific_standards', "true", true);
            } else {
                add_comment_meta($comment_id, PREFIX . 'meets_scientific_standards', "false", true);
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
            $list = explode(",", $authors);

            $context = stream_context_create($this->orcid_opts);
            $delimiter = "";
            foreach ($list as $authorORCID) {
                $response = file_get_contents(ORCID_API_URL . $authorORCID, false, $context);
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
        $authors = get_post_meta($post->ID, PREFIX . "authors", true);
    
        ob_start(); 
        ?>
        <html>
            <h1 style="text-align: center;"><?php echo $title; ?></h1>
            <?php
            $list = explode(',', get_post_meta($post->ID, PREFIX . "authorNames", true));
            foreach ($list as $author) {
            ?>
                <h3><?php 
                $authorDetails = explode(':', $author);
                echo $authorDetails[1]; 
                ?></h3>
            <?php } ?>
            <h3>Abstract</h3><?php echo get_post_meta($post->ID, PREFIX . "abstract", true);?>
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
?>
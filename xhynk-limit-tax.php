<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Only allow WP Access

class XhynkLimitTax {
	static $instance, $file_name;

	public static function get_instance(){
		if( ! self::$instance )
			self::$instance = new self();

		return self::$instance;
	}

	/**
	 * Define Constant Vars
	 * 
	 * Use Static Vars intead of Constant, PHP 5.4 doesn't like CONST arrays.
	 */
	public static $meta_boxes = array(
		'post' => array(
			'categorydiv',
			// Add Additional Taxonomies for Post
		),
		// Additional 'post_type' => array( 'taxonomy1div', 'taxonomy2div', 'custom-meta-box-id' );
	);

	public static $panels = array(
		'category',
		// Add Additional Panels
	);

	public static $custom_boxes = array(
		'post' => array(
			'category'
		),
		//Additional 'post_type' => array( 'taxonomy-slug', 'taxonomy-2-slug' );
	);

	/**
	 * Class Constructor - Runs Action Hooks
	 */
	public function __construct(){
		self::$file_name = basename(__FILE__, '.php');

		// AJAX Handler for Tax Terms
		add_action( 'wp_ajax_term_query_advanced',     array( $this, 'term_query_advanced' ) );
		add_action( 'wp_ajax_add_term_to_object',      array( $this, 'add_term_to_object' ) );
		add_action( 'wp_ajax_remove_term_from_object', array( $this, 'remove_term_from_object' ) );

		// Manage Assets
		add_action( 'init', array( $this, 'block_editor_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
		
		// Remove Metabox from old editor
		add_action( 'admin_menu', array( $this, 'remove_meta_boxes' ), 100 );

		// Add New Metaboxes
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ), 11, 2 );

		// Hide from Quick Edit
		add_filter( 'quick_edit_show_taxonomy', array( $this, 'hide_taxonomies_from_quick_bulk_edit'), 10, 3 );

		// Add to Quick Edit
		//add_action( 'quick_edit_custom_box', array( $this, 'custom_quick_edit_taxonomy_box' ), 10, 0 );

		// Pass to quick edit
		// add_filter( 'editable_slug', array( $this, 'custom_quick_edit_fields' ) );
	}

	public function hide_taxonomies_from_quick_bulk_edit( $show_in_quick_edit, $taxonomy_name, $post_type ){
		foreach( self::$custom_boxes as $post_type => $taxonomies ){
			foreach( $taxonomies as $taxonomy ){
				return false;
			}
		}
	}

	/**
	 * Remove Metaboxes
	 */
	public function remove_meta_boxes(){
		foreach( self::$meta_boxes as $post_type => $meta_boxes ){
			foreach( $meta_boxes as $meta_box ){
				remove_meta_box( $meta_box, $post_type, 'side' );
			}
		}
	}

	function block_editor_assets(){
		wp_register_script( 'xhynk-block-script', plugin_dir_url( __FILE__ ) .self::$file_name.'/js/block-script.js', array( 'wp-blocks', 'wp-edit-post' ), filemtime( plugin_dir_path( __FILE__ ) . self::$file_name.'/js/block-script.js' ) );
		register_block_type( self::$file_name.'/x-block-files', array( 'editor_script' => 'xhynk-block-script' ) );
		wp_localize_script( 'xhynk-block-script', 'removeablePanels', self::$panels );
	}

	public function admin_assets(){
		wp_enqueue_script( 'xhynk-ajax-handler', plugin_dir_url( __FILE__ ) . self::$file_name.'/js/ajax-handler.js', array(), filemtime( plugin_dir_path( __FILE__ ) . self::$file_name.'/js/ajax-handler.js' ), true );
		wp_localize_script( 'xhynk-block-script', 'ajaxurl', admin_url('admin-ajax.php') );

		wp_enqueue_style( 'xhynk-styles', plugin_dir_url( __FILE__ ) . self::$file_name.'/css/xhynk.css', array(), filemtime( plugin_dir_path( __FILE__ ) . self::$file_name.'/css/xhynk.css' ) );
	}

	public function custom_meta_box( $post, $taxonomy, $title ){
		$terms = wp_get_object_terms( $post->ID, $taxonomy );

		echo '<div class="xhynk-meta-box">';
			printf( '<strong>Current %s:</strong>', $title );
			echo '<div class="tag-container term-target">';
				if( !empty( $terms ) ){
					foreach( $terms as $term ){
						$remove = sprintf( '<span class="close" onclick="xhynkRemoveTermFromObject(this,event,%d);" data-term-id="%d" data-tax="%s"><svg viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></span>', $post->ID, $term->term_id, $taxonomy );
						printf( '<span class="tag removable">%s%s</span>', $term->name, $remove );
					}
				}
				printf( '<span class="tag no-rows-found gray">No %s Found</span>', $title );
			echo '</div>';
			printf( '<h4 style="margin-bottom: 6px;">Add %s:</h4>', $title );
			echo '<div>';
				printf( '<input style="width:100%%;" onkeyup="xhynkTaxTermQuery(this,event,%d);" data-post-type="%s" data-tax="%s" type="search" placeholder="Search…" />', $post->ID, $post->post_type, $taxonomy );
				echo '<div class="target term-search-target span-all"></div>';
			echo '</div>';
		echo '</div>';
	}

	public function add_meta_boxes( $post_type, $post ){
		foreach( self::$meta_boxes as $post_type => $meta_boxes ){
			foreach( $meta_boxes as $meta_box ){
				$id    = sanitize_title( "xhynk-selector-{$post_type}-{$meta_box}" );
				$taxonomy   = (substr($meta_box,-3)=='div') ? substr($meta_box, 0, -3) : $meta_box;
				$title = ucwords( str_replace( array('-','_'), ' ', $taxonomy ) );

				add_meta_box( $id, $title, function($post) use( $taxonomy, $title ){
					$this->custom_meta_box( $post, $taxonomy, $title );
				}, $post_type, 'side', 'high' );
			}
		}
	}

	public function term_query_advanced(){
		if( ! wp_doing_ajax() )
			return false;

		$args = array_map( function($v){
			return json_decode(stripslashes($v));
		}, $_POST);
		
		extract( $args );

		if( !isset( $post_type ) )
			wp_send_json( 'Post Type is required', 400 );

		if( !isset( $taxonomy ) )
			wp_send_json( 'Taxonomy is required', 400 );

		if( !isset( $search ) )
			wp_send_json( 'Search term is required', 400 );

		$term_query_args = array(
			'post_type'     => $post_type,
			'taxonomy'      => $taxonomy,
			'orderby'       => 'name',
			'order'         => 'ASC',
			'hide_empty'    => false,
			'number'        => 20,
			'fields'        => 'id=>name',
			'name__like'    => $search
		);

		$term_query = new WP_Term_Query( $term_query_args );

		if( !empty( $term_query->terms ) ){
			if( isset($check_terms_for) ){
				$terms_array = array();
					
				foreach( $term_query->terms as $id => $name ){
					if( has_term($id, $taxonomy, $check_terms_for) ){
						$terms_array[] = array('id' => $id, 'name' => $name, 'has_term' => true);
					} else {
						$terms_array[] = array('id' => $id, 'name' => $name, 'has_term' => false);
					}
				}

				$term_query->terms = $terms_array;
			}
			$terms = $term_query->terms;
		} else {
			$terms = null;	
		}
		
		wp_send_json( $terms, 200 );
	}

	public function add_term_to_object(){
		if( ! wp_doing_ajax() )
			return false;

		$args = array_map( function($v){
			return json_decode(stripslashes($v));
		}, $_POST);
		
		extract( $args );

		if( !isset( $post_id ) )
			wp_send_json( 'Post ID is required', 400 );

		if( !isset( $taxonomy ) )
			wp_send_json( 'Taxonomy is required', 400 );

		if( !isset( $term_id ) )
			wp_send_json( 'Term ID is required', 400 );
		
		$result = wp_set_object_terms( absint($post_id), absint($term_id), $taxonomy, true );

		if( $result && !is_wp_error($result) ){
			wp_send_json( 'Success', 200 );
		} else {
			wp_send_json( 'Success', 400 );
		}
	}

	public function remove_term_from_object(){
		if( ! wp_doing_ajax() )
			return false;

		$args = array_map( function($v){
			return json_decode(stripslashes($v));
		}, $_POST);
		
		extract( $args );

		if( !isset( $post_id ) )
			wp_send_json( 'Post ID is required', 400 );

		if( !isset( $taxonomy ) )
			wp_send_json( 'Taxonomy is required', 400 );

		if( !isset( $term_id ) )
			wp_send_json( 'Term ID is required', 400 );
		
		$result = wp_remove_object_terms( absint( $post_id ), absint( $term_id ), trim( $taxonomy ) );

		if( $result && !is_wp_error($result) ){
			wp_send_json( 'Success', 200 );
		} else {
			wp_send_json( 'Success', 400 );
		}
	}

	private function custom_quick_edit_fields( $post_name ){
		return $post_name.'</div><div class="ID">'.get_the_ID();
	}

	private function custom_quick_edit_taxonomy_box(){
		echo '<fieldset>';
			echo '<div style="display: grid; grid-gap: 1em; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));">';
				foreach( self::$custom_boxes as $post_type => $taxonomies ){
					foreach( $taxonomies as $taxonomy ){
						$title = ucwords( str_replace( array('-','_'), ' ', $taxonomy ) );
						printf( '<div id="custom-%s-%s-box" class="inline-edit-col">', $post_type, $taxonomy );
							$this->custom_meta_box( $post, $taxonomy, $title );
						echo '</div>';
					echo '</div>';
				}
			}
			echo '</div>';
		echo '</fieldset>';
	}
}

add_action( 'plugins_loaded', array('XhynkLimitTax', 'get_instance') );
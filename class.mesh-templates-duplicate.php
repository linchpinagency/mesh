<?php
/**
 * Handle duplicating our mesh template structure
 *
 * This class will handle the following
 *
 * - Copy Mesh Sections (mesh_section) and child mesh_sections AKA blocks/columns
 *      - Excluding Revisions
 * - Copy post meta for each mesh_section
 *
 * Inspired by https://wordpress.org/plugins/duplicate-post/ for ideas
 *
 * @since      1.1
 * @package    Mesh
 * @subpackage Templates_Duplicate
 */

/**
 * Class Mesh_Templates_Duplicate
 */
class Mesh_Templates_Duplicate {

	/**
	 * Mesh_Templates_Duplicate constructor.
	 */
	function __construct() {
		// Duplicate Post Meta.
		add_action( 'mesh_duplicate_template_section', array( $this, 'duplicate_post_meta' ), 10, 2 );

		// Duplicate Taxonomies.
		add_action( 'mesh_duplicate_template_section', array( $this, 'duplicate_taxonomies' ), 10, 2 );

		// Duplicate Children and Attachments.
		add_action( 'mesh_duplicate_template_section', array( $this, 'duplicate_children' ), 10, 2 );
	}

	/**
	 * Duplicate all the template's sections
	 *
	 * @param int  $template_id    Template ID.
	 * @param int  $post_id        Target Post ID.
	 * @param bool $include_drafts Include drafts.
	 *
	 * @return string
	 */
	function duplicate_sections( $template_id, $post_id, $include_drafts = false ) {

		$template_id = absint( $template_id );
		$template_post = get_post( $template_id );

		if ( ! empty( $template_post ) ) {

			$children = $this->duplicate_children( $post_id, $template_post, $include_drafts );

			if ( ! empty( $children ) ) {

				$markup = '';

				foreach ( $children as $section_id ) {
					$markup .= mesh_add_section_admin_markup( $section_id, false, true );
				}

				return $markup;

			} else {
				return esc_html__( 'created nothing', 'mesh' );
			}
		}

		return esc_html__( 'no template found', 'mesh' );
	}

	/**
	 * Duplicate the post attachments to the new section
	 * This does not actually duplicate the files.
	 *
	 * @thanks https://plugins.svn.wordpress.org/duplicate-post/
	 *
	 * @param int    $new_id         New Post ID.
	 * @param object $template_post  Original Post Object.
	 * @param bool   $include_drafts Include Drafts.
	 *
	 * @return array $duplicate_children Array of IDs
	 */
	function duplicate_children( $new_id, $template_post, $include_drafts = false ) {

		$post_status = array(
			'publish',
		);

		if ( $include_drafts ) {
			$post_status[] = 'draft';
		}

		$children = new WP_Query( array(
			'post_type'      => array( 'mesh_section', 'attachment' ),
			'posts_per_page' => apply_filters( 'mesh_templates_per_page', 50 ),
			'post_status'    => $post_status,
			'post_parent'    => $template_post->ID,
			'order_by'       => 'menu_order',
			'order'          => 'ASC',
		) );

		$duplicated_children = array();

		if ( $children->have_posts() ) {
			while ( $children->have_posts() ) {
				global $post;

				$children->the_post();

				$duplicated_child = $this->duplicate_section( $post, $new_id );

				if ( ! empty( $duplicated_child ) ) {
					$duplicated_children[] = $duplicated_child;
				}
			}

			wp_reset_postdata();
		}

		return $duplicated_children;
	}

	/**
	 * Duplicate the section
	 *
	 * @param object $post      Post Object.
	 * @param string $parent_id Parent Post ID.
	 *
	 * @return int|mixed|WP_Error
	 */
	function duplicate_section( $post, $parent_id = '' ) {

		// Skip Revisions.
		if ( wp_is_post_revision( $post ) || 'revision' === $post->post_type ) {
			return;
		}

		$status = 'draft';

		if ( 'attachment' !== $post->post_type ) {
			if ( empty( $status ) ) {
				$status = 'draft';
			}
		} else {
			$status = 'publish';
		}

		$new_date = current_time( 'Y-m-d H:i:s' );

		$new_post_parent = empty( $parent_id ) ? $post->post_parent : $parent_id;

		$new_post = array(
			'menu_order'     => $post->menu_order,
			'post_author'    => $post->post_author,
			'post_content'   => $post->post_content,
			'post_excerpt'   => $post->post_excerpt,
			'post_mime_type' => $post->post_mime_type,
			'post_parent'    => $new_post_parent,
			'post_status'    => $status, // Always set a published section to draft. Exclude attachments.
			'post_title'     => $post->post_title,
			'post_type'      => $post->post_type,
			'post_date'      => $new_date,
			'post_date_gmt'  => get_gmt_from_date( $new_date ),
		);

		$new_post_id = wp_insert_post( $new_post );

		// Create a new slug.
		$post_name = wp_unique_post_slug( $post->post_name, $new_post_id, $status, $post->post_type, $new_post_parent );

		$new_post = array(
			'ID'        => $new_post_id,
			'post_name' => $post_name,
		);

		// Update our new post with the correct slug and information.
		wp_update_post( $new_post );

		do_action( 'mesh_duplicate_template_section', $new_post_id, $post );

		delete_post_meta( $new_post_id, '_mesh_template_original' );
		add_post_meta( $new_post_id, '_mesh_template_original', $post->ID );

		$parent_post_type = get_post_type( $post->post_parent );

		if ( $post->post_parent && 'mesh_section' !== $parent_post_type ) {
			return $new_post_id;
		} else {
			return '';
		}
	}

	/**
	 * Copy the taxonomies of a post to another post
	 *
	 * @param int    $new_id New Post ID.
	 * @param object $post   Post Object.
	 */
	function duplicate_taxonomies( $new_id, $post ) {
		global $wpdb;

		if ( isset( $wpdb->terms ) ) {

			wp_set_object_terms( $new_id, null, 'category' );

			$taxonomies = get_object_taxonomies( $post->post_type );

			foreach ( $taxonomies as $taxonomy ) {

				$post_terms = wp_get_object_terms( $post->ID, $taxonomy,
					array(
						'orderby' => 'term_order',
					)
				);
				$terms = array();
				$term_length = count( $post_terms );

				for ( $i = 0; $i < $term_length; $i++ ) {
					$terms[] = $post_terms[ $i ]->slug;
				}

				wp_set_object_terms( $new_id, $terms, $taxonomy );
			}
		}
	}

	/**
	 * Duplicate the post meta to the new section
	 *
	 * @param int    $new_id New Post ID.
	 * @param object $post   Original Post Object.
	 */
	function duplicate_post_meta( $new_id, $post ) {
		$post_meta_keys = get_post_custom_keys( $post->ID );

		if ( empty( $post_meta_keys ) ) {
			return;
		}

		foreach ( $post_meta_keys as $meta_key ) {

			$meta_values = get_post_custom_values( $meta_key, $post->ID );

			foreach ( $meta_values as $meta_value ) {
				$meta_value = maybe_unserialize( $meta_value );

				update_post_meta( $new_id, $meta_key, $meta_value );
			}
		}
	}
}

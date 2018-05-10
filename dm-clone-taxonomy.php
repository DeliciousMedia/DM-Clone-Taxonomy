<?php
/**
 * Plugin Name: DM Clone Taxonomy
 * Plugin URI:  https://www.deliciousmedia.co.uk/
 * Description: Provides the WP CLI command clonetax, to clone taxonomy data including terms, term meta and post relationships.
 * Version:     1.0.2
 * Author:      Delicious Media Limited
 * Author URI:  https://www.deliciousmedia.co.uk/
 * Text Domain: dm-clonetax
 * License:     GPLv3 or later
 *
 * @package dm-clonetax
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'WP_CLI' ) ) {

	/**
	* Clones taxonomy data (terms, term meta and post relationships) from one taxonomy to another.
	*
	* ## OPTIONS
	*
	* <source_taxonomy>
	* : The source taxonomy to copy data from.
	*
	* <target_taxonomy>
	* : The target taxonomy to copy data in to. This taxonomy cannot contain any existing terms.
	*
	* [--post_type=<post_type_name>]
	* : Name of the post type to copy term relationships for (default: post).
	*
	* [--skip_meta_keys=<key1,key2>]
	* : Comma separated list of term meta keys that should not be copied (default: none).
	*
	* ## EXAMPLE
	*
	*     wp clonetax product_category new_product_category --post_type=product --skip_meta=category_alt_name,category_colour
	*
	* @when after_wp_load
	*/
	WP_CLI::add_command(
		'clonetax', function( $args, $assoc_args ) {

			$source_tax = sanitize_text_field( $args[0] );
			$target_tax = sanitize_text_field( $args[1] );
			$post_type = isset( $assoc_args['post_type'] ) ? sanitize_text_field( $assoc_args['post_type'] ) : 'post';

			if ( isset( $assoc_args['skip_meta_keys'] ) ) {
				$skip_meta_keys = explode( ',', $assoc_args['skip_meta_keys'] );
				$skip_meta_keys = array_map( 'sanitize_key', $skip_meta_keys );
			} else {
				$skip_meta_keys = [];
			}

			if ( ! taxonomy_exists( $source_tax ) ) {
				WP_CLI::error( new WP_Error( 'missing_source_tax', 'Source taxonomy ' . esc_html( $source_tax ) . ' does not exist.' ) );
			}

			if ( ! taxonomy_exists( $target_tax ) ) {
				WP_CLI::error( new WP_Error( 'missing_target_tax', 'Target taxonomy ' . esc_html( $target_tax ) . ' does not exist.' ) );
			}

			if ( ! post_type_exists( $post_type ) ) {
				WP_CLI::error( new WP_Error( 'missing_post_type', 'Post type ' . esc_html( $post_type ) . ' does not exist.' ) );
			}

			if ( wp_count_terms( $target_tax, [ 'hide_empty' => false ] ) > 0 ) {
				WP_CLI::error( new WP_Error( 'target_tax_not_empty', 'Target taxonomy ' . esc_html( $target_tax ) . ' is not empty.' ) );
			}

			$source_terms = get_terms(
				[
					'taxonomy'   => $source_tax,
					'hide_empty' => false,
					'fields'     => 'all',
					'orderby'    => 'term_id',
				]
			);

			$total_terms = count( $source_terms );

			WP_CLI::line( 'Cloning ' . absint( $total_terms ) . ' terms from taxonomy ' . esc_html( $source_tax ) . ' to taxonomy ' . esc_html( $target_tax ) );

			$progress = WP_CLI\Utils\make_progress_bar( 'Cloning terms', $total_terms, 100 );

			$stats = [
				'terms'               => 0,
				'meta_pairs'          => 0,
				'meta_values'         => 0,
				'meta_values_skipped' => 0,
				'post_relationships'  => 0,
			];

			foreach ( $source_terms as $id => $source_term ) {

				WP_CLI::debug( '== Processing source term id ' . esc_html( $source_term->term_id ) . ', name: ' . esc_html( $source_term->name ) );
				WP_CLI::debug( '-- Inserting term ' . esc_html( $source_term->name ) . ' in taxonomy ' . esc_html( $target_tax ) );

				$parent = isset( $term_map[ $source_term->parent ] ) ? $term_map[ $source_term->parent ] : 0;

				$target_term = wp_insert_term(
					$source_term->name,
					$target_tax,
					[
						'description' => $source_term->description,
						'slug'        => $source_term->slug,
						'parent'      => $parent,
					]
				);

				if ( is_wp_error( $target_term ) ) {
					WP_CLI::error( $target_term );
				}

				$stats['terms']++;
				WP_CLI::debug( ' - term id: ' . esc_html( $target_term['term_id'] ) . ', tax term id: ' . esc_html( $target_term['term_taxonomy_id'] ) );

				$term_map[ $source_term->term_id ]  = $target_term['term_id'];
				$source_term_meta = get_metadata( 'term', $source_term->term_id );

				foreach ( $source_term_meta as $meta_key => $meta_values ) {
					foreach ( $meta_values as $id => $value ) {
						if ( in_array( $meta_key, $skip_meta_keys ) ) {
							WP_CLI::debug( ' - Skipping term meta, key: ' . esc_html( $meta_key ) . ', value: ' . esc_html( $value ) );
							$stats['meta_values_skipped']++;
							continue;
						}
						WP_CLI::debug( ' - Inserting term meta, key: ' . esc_html( $meta_key ) . ', value: ' . esc_html( $value ) );
						add_term_meta( $target_term['term_id'], $meta_key, $value, false );
						$stats['meta_values']++;
					}

					$stats['meta_pairs']++;
				}

				$source_term_posts = get_posts(
					[
						'post_type'        => $post_type,
						'posts_per_page'   => -1,
						'fields'           => 'ids',
						'no_found_rows'    => true,
						'tax_query'        => [
							[
								'taxonomy'         => $source_tax,
								'field'            => 'term_id',
								'terms'            => $source_term->term_id,
								'operator'         => 'IN',
								'include_children' => false,
							],
						],
					]
				);

				foreach ( $source_term_posts as $id => $post_id ) {
					WP_CLI::debug( ' - Adding term to post ' . esc_html( $post_id ) );
					wp_set_post_terms( $post_id, (array) $target_term['term_id'], $target_tax, true );
					$stats['post_relationships']++;
				}

				$progress->tick();

			}

			$progress->finish();

			WP_CLI::success( sprintf( 'Done! Cloned %s terms, with %s meta values copied and %s skipped (total %s) and %s post relationships duplicated.', $stats['terms'], $stats['meta_values'], $stats['meta_values_skipped'], $stats['meta_pairs'], $stats['post_relationships'] ) );
		}
	);

}

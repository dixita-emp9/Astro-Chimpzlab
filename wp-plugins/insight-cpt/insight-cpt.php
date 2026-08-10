<?php
/**
 * Plugin Name: ChimpzLab Insights CPT
 * Description: Registers the "insights" custom post type (REST API) that the new
 * chimpzlab.com site uses for blogs. Keeps new blogs separate from old Posts.
 * Version: 1.0.0
 * Author: ChimpzLab
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'init', function () {
    register_post_type(
        'insight',
        array(
            'labels'       => array(
                'name'          => 'Blogs',
                'singular_name' => 'Blog',
                'add_new'       => 'Add New Blog',
                'edit_item'     => 'Edit Blog',
            ),
            'public'       => true,
            'has_archive'  => false,
            'menu_icon'    => 'dashicons-format-aside',
            'menu_position' => 5,
            'supports'     => array(
                'title',
                'editor',
                'excerpt',
                'thumbnail',
                'author',
                'custom-fields',
            ),
            'taxonomies'   => array( 'category', 'post_tag' ),
            'show_in_rest' => true,
            'rest_base'    => 'insights',
            'rewrite'      => array( 'slug' => 'insights' ),
        )
    );
}, 5 );

// Clean rewrite rules once so /wp-json/wp/v2/insights is reachable immediately.
add_action( 'init', function () {
    if ( get_option( 'chimpzlab_insights_flushed' ) !== '1' ) {
        flush_rewrite_rules();
        update_option( 'chimpzlab_insights_flushed', '1' );
    }
}, 99 );

// Custom meta fields exposed via the REST API so the new site can render the
// original publish date ("July 2026") and read time exactly as before.
register_post_meta(
    'insight',
    'readtime',
    array(
        'show_in_rest' => true,
        'single'       => true,
        'type'         => 'string',
    )
);
register_post_meta(
    'insight',
    'date',
    array(
        'show_in_rest' => true,
        'single'       => true,
        'type'         => 'string',
    )
);

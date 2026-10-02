<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class DT_Network_Chart extends DT_Metrics_Chart_Base {
    // vars for url path and sidebar menu
    public $base_slug = 'network-metrics';
    public $base_title = 'Network';
    public $title = 'Network Graph';
    public $slug = 'network';
    // js file for chart
    public $js_object_name = 'wp_js_object';
    public $js_file_name = 'network-chart.js';
    // permissions
    public $permissions = [ 'dt_all_access_contacts', 'view_project_metrics' ];

    public function __construct() {
        parent::__construct();
        add_action( 'rest_api_init', [ $this, 'add_api_routes' ] ); // call method on object $this
        if ( ! $this->has_permission() ) { return; }
        if ( "metrics/$this->base_slug/$this->slug" === dt_get_url_path( true ) ) {
            add_action( 'wp_enqueue_scripts', [ $this, 'scripts' ], 99 );
        }
    }

    public function add_api_routes() {
        register_rest_route( "$this->base_slug/$this->slug", '/graph', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'get_graph' ],
            'permission_callback' => function () {
                return $this->has_permission();
            },
        ] );
    }

    private function allowed_post_types() {
        return DT_Posts::get_post_types();
        // $allowed = [];
        // foreach ( DT_Posts::get_post_types() as $post_type ) {
        //     if ( current_user_can( 'view_any_' . $post_type ) ) {
        //         $allowed[] = $post_type;
        //     }
        // }
        // return $allowed;
    }

    public function get_p2p_types() {
        $p2p_types = [];
        foreach ( $this->allowed_post_types() as $post_type ) {
            $settings = DT_Posts::get_post_field_settings( $post_type );
            $p2p_keys = [];
            foreach ( $settings as $field ) {
                if ( ( $field['type'] ?? '' ) === 'connection'
                    && !empty( $field['p2p_key'] )
                    && ( $field['post_type'] ?? '' ) === $post_type  // same type on both sides
                ) {
                    $p2p_keys[ $field['p2p_key'] ] ??= [
                        'key'   => $field['p2p_key'],
                        'label' => $field['name'],
                    ]; // set if unset
                }
            }
            if( count($p2p_keys) > 0 ) {
                $p2p_types[] = [
                    'key'       => $post_type,
                    'label'     => DT_Posts::get_label_for_post_type( $post_type, true ), // true: plural
                    'p2p_keys'  => array_values( $p2p_keys ),
                ];
            }
        }
        return $p2p_types;
    }

    public function get_graph( WP_REST_Request $request ) {
        global $wpdb;

        $post_type = sanitize_key( $request->get_param( 'post_type' ) );
        $p2p_key   = sanitize_text_field( $request->get_param( 'p2p_key' ) );

        $valid = false;
        foreach ( $this->get_p2p_types() as $type ) {
            if ( $type['key'] !== $post_type ) { continue; }
            foreach ( $type['p2p_keys'] as $k ) {
                if ( $k['key'] === $p2p_key ) { $valid = true; }
            }
        }
        if ( ! $valid ) {
            return new WP_Error( 'invalid_selection', 'Invalid post type or connection type.', [ 'status' => 400 ] );
        }

        $allowed = $this->allowed_post_types();
        $in = implode( ',', array_fill( 0, count( $allowed ), '%s' ) );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare( "
            SELECT e.p2p_from AS from_id, a.post_title AS from_title, a.post_type AS from_type,
                    e.p2p_to   AS to_id,   b.post_title AS to_title,   b.post_type AS to_type
            FROM {$wpdb->prefix}p2p e
            JOIN $wpdb->posts a ON a.ID = e.p2p_from
            JOIN $wpdb->posts b ON b.ID = e.p2p_to
            WHERE e.p2p_type = %s
                AND a.post_status = 'publish' AND b.post_status = 'publish'
                AND a.post_type IN ($in) AND b.post_type IN ($in)
        ", array_merge( [ $p2p_key ], $allowed, $allowed ) ), ARRAY_A );
        // phpcs:enable

        $nodes = [];
        $edges = [];
        foreach ( ( $rows ?: [] ) as $r ) {
            $nodes[ (int) $r['from_id'] ] = [
                'id' => (int) $r['from_id'],
                'label' => $r['from_title'],
                'group' => $r['from_type']
            ];
            $nodes[ (int) $r['to_id'] ] = [
                'id' => (int) $r['to_id'],
                'label' => $r['to_title'],
                'group' => $r['to_type']
            ];
            $edges[] = [
                'from' => (int) $r['from_id'],
                'to' => (int) $r['to_id']
            ];
        }

        return [
            'nodes' => array_values( $nodes ),
            'edges' => $edges
        ];
    }

    public function scripts() {
        $vis_url = plugin_dir_url( __DIR__ ) . 'dist/vis-network.min.js';
        $vis_deps = [];
        $vis_version = '10.1.2';

        $js_url = plugin_dir_url( __FILE__ ) . $this->js_file_name;
        $js_deps = [ 'jquery', 'vis-network' ];
        $js_version = filemtime( plugin_dir_path( __FILE__ ) . $this->js_file_name );

        wp_register_script( 'vis-network', $vis_url, $vis_deps, $vis_version, true ); // true: footer script
        wp_enqueue_script( 'dt_' . $this->slug . '_script', $js_url, $js_deps, $js_version, true ); // true: footer script

        // pass php data and translations to js script
        wp_localize_script( 'dt_' . $this->slug . '_script', $this->js_object_name, [
            'base_slug' => $this->base_slug,
            'types'     => $this->get_p2p_types(),
            'rest_url'  => rest_url( "$this->base_slug/$this->slug/graph" ),
            'nonce'     => wp_create_nonce( 'wp_rest' ), // cryptographic token for rest API
            'home_url'  => home_url( '/' ),
            'translations' => [
                'title' => $this->title,
                'none'  => __( 'No connection types available.', 'dt-network' ),
                'empty' => __( 'No connections of this type.', 'dt-network' ),
                'error' => __( 'Could not load the connections.', 'dt-network' ),
            ],
        ] );
    }
}

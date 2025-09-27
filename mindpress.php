<?php
/**
 * Plugin Name: MindPress – Mind Map to Post
 * Description: Plan blog posts visually in WP Admin. Reuse one mind map to generate unlimited draft posts or insert into the current post.
 * Version: 0.2.7
 * Author: You
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * Text Domain: mindpress
 */
if (!defined('ABSPATH')) { exit; }

class MindPressPlugin {
    const CPT   = 'mp_mindmap';
    const NONCE = 'mp_nonce';

    public function __construct() {
        add_action('init',                         [$this, 'register_cpt']);
        add_action('add_meta_boxes',               [$this, 'add_metaboxes']);
        add_action('admin_enqueue_scripts',        [$this, 'enqueue']);
        add_action('save_post',                    [$this, 'save'], 10, 2);

        // AJAX endpoints for the builder
        add_action('wp_ajax_mp_save_tree',         [$this, 'ajax_save_tree']);     // autosave JSON
        add_action('wp_ajax_mp_get_tree',          [$this, 'ajax_get_tree']);      // load fallback
        add_action('wp_ajax_mp_generate_post',     [$this, 'ajax_generate_post']); // generate from editor
        add_action('wp_ajax_mp_insert_into',       [$this, 'ajax_insert_into']);   // insert into current post

        // Admin actions for reusable workflow
        add_filter('post_row_actions',             [$this, 'row_actions'], 10, 2); // list links
        add_action('admin_post_mp_gen_from_map',   [$this, 'admin_gen_from_map']); // Generate Draft
        add_action('admin_post_mp_duplicate_map',  [$this, 'admin_duplicate_map']); // Duplicate

        // List columns: Times Generated / Last Generated (+sortable)
        add_filter('manage_edit-'.self::CPT.'_columns',        [$this, 'columns']);
        add_action('manage_'.self::CPT.'_posts_custom_column', [$this, 'columns_output'], 10, 2);
        add_filter('manage_edit-'.self::CPT.'_sortable_columns', [$this, 'columns_sortable']);
        add_action('pre_get_posts',               [$this, 'columns_sort_query']);
    }

    public function register_cpt() {
        register_post_type(self::CPT, [
            'labels' => [
                'name'          => __('Mind Maps', 'mindpress'),
                'singular_name' => __('Mind Map', 'mindpress'),
                'add_new_item'  => __('Add Mind Map', 'mindpress'),
            ],
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => true,
            'menu_icon'    => 'dashicons-networking',
            'supports'     => ['title'],
        ]);
    }

    public function add_metaboxes() {
        add_meta_box(
            'mp_builder',
            __('Mind Map Builder', 'mindpress'),
            [$this, 'render_builder_metabox'],
            [ self::CPT, 'post' ],
            'normal',
            'high'
        );

        add_meta_box(
            'mp_template',
            __('Template', 'mindpress'),
            [$this, 'render_template_metabox'],
            self::CPT,
            'side',
            'low'
        );
    }

    public function enqueue() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !in_array($screen->base, ['post', 'post-new'], true)) {
            return;
        }
        $ptype = isset($screen->post_type) ? $screen->post_type : '';
        if (!in_array($ptype, [ self::CPT, 'post' ], true)) {
            return;
        }

        wp_enqueue_style ('mp-admin', plugin_dir_url(__FILE__) . 'assets/admin.css', [], '0.2.7');
        wp_enqueue_script('mp-admin', plugin_dir_url(__FILE__) . 'assets/admin.js', ['jquery'], '0.2.7', true);

        $post = get_post();
        $post_id = $post ? (int) $post->ID : 0;

        wp_localize_script('mp-admin', 'MindPress', [
            'nonce'  => wp_create_nonce(self::NONCE),
            'ajax'   => admin_url('admin-ajax.php'),
            'postId' => $post_id,
            'ptype'  => $post ? $post->post_type : $ptype,
            'i18n'   => [
                'root'       => __('Root Idea', 'mindpress'),
                'addChild'   => __('Add child', 'mindpress'),
                'addSibling' => __('Add sibling', 'mindpress'),
                'delete'     => __('Delete', 'mindpress'),
                'generate'   => __('Generate Draft Post', 'mindpress'),
                'insert'     => __('Insert into this post', 'mindpress'),
                'search'     => __('Search...', 'mindpress'),
                'exported'   => __('Exported JSON downloaded', 'mindpress'),
                'importErr'  => __('Invalid JSON', 'mindpress'),
                'saving'     => __('Saving…', 'mindpress'),
                'saved'      => __('Saved ✓', 'mindpress'),
            ],
        ]);
    }

    /** Builder UI */
    public function render_builder_metabox($post) {
        $json = get_post_meta($post->ID, '_mp_tree', true);
        wp_nonce_field(self::NONCE, self::NONCE);

        echo '<div class="mp-toolbar">
                <input type="text" id="mp-search" class="mp-search" placeholder="'.esc_attr__('Search...', 'mindpress').'" />
                <div class="mp-toolbar-buttons">
                    <button type="button" class="button" id="mp-expand-all">'.esc_html__('Expand all','mindpress').'</button>
                    <button type="button" class="button" id="mp-collapse-all">'.esc_html__('Collapse all','mindpress').'</button>
                    <button type="button" class="button" id="mp-export">'.esc_html__('Export','mindpress').'</button>
                    <button type="button" class="button" id="mp-import">'.esc_html__('Import','mindpress').'</button>
                    <span class="mp-status" id="mp-status" aria-live="polite"></span>
                </div>
              </div>';

        echo '<div id="mp-app" class="mp-wrap" data-json="' . esc_attr($json ?: '') . '"></div>';
        echo '<input type="hidden" name="_mp_tree" id="_mp_tree" value="' . esc_attr($json ?: '') . '" />';

        echo '<p class="mp-actions">';
        echo '<button type="button" class="button button-primary" id="mp-generate">' . esc_html__('Generate Draft Post', 'mindpress') . '</button> ';
        if ($post->post_type === 'post') {
            echo '<button type="button" class="button" id="mp-insert">' . esc_html__('Insert into this post', 'mindpress') . '</button> ';
        }
        echo '</p>';

        echo '<p class="description">'.esc_html__(
            'This map is reusable. Click “Generate Draft Post” any time—each click creates a fresh draft; the map stays unchanged.',
            'mindpress'
        ).'</p>';
    }

    /** Template checkbox */
    public function render_template_metabox($post) {
        $is_template = get_post_meta($post->ID, '_mp_is_template', true) === '1';
        echo '<label><input type="checkbox" name="_mp_is_template" value="1" '.checked($is_template, true, false).' /> ';
        echo esc_html__('Use this Mind Map as a template', 'mindpress').'</label>';
    }

    /** Save handler: map JSON + template flag */
    public function save($post_id, $post) {
        if (!in_array($post->post_type, [ self::CPT, 'post' ], true)) {
            return;
        }
        $nonce = (string) filter_input(INPUT_POST, self::NONCE, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        if (!$nonce || !wp_verify_nonce($nonce, self::NONCE)) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Save JSON
        $json_raw = filter_input(INPUT_POST, '_mp_tree', FILTER_DEFAULT);
        $json_raw = (null !== $json_raw) ? wp_unslash($json_raw) : '';
        if ($json_raw !== '') {
            $arr = json_decode($json_raw, true);
            if (is_array($arr)) {
                update_post_meta($post_id, '_mp_tree', wp_json_encode($arr));
            } else {
                delete_post_meta($post_id, '_mp_tree');
            }
        } else {
            delete_post_meta($post_id, '_mp_tree');
        }

        // Save template flag (CPT only)
        if ($post->post_type === self::CPT) {
            $is_tpl = filter_input(INPUT_POST, '_mp_is_template', FILTER_SANITIZE_NUMBER_INT);
            update_post_meta($post_id, '_mp_is_template', $is_tpl ? '1' : '0');
        }
    }

    /** AJAX: autosave JSON */
    public function ajax_save_tree() {
        check_ajax_referer(self::NONCE, 'nonce');
        $post_id = (int) filter_input(INPUT_POST, 'post_id', FILTER_SANITIZE_NUMBER_INT);
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
        }
        $raw = filter_input(INPUT_POST, 'tree', FILTER_DEFAULT);
        $raw = (null !== $raw) ? wp_unslash($raw) : '';
        $arr = json_decode($raw, true);
        if (!is_array($arr)) {
            wp_send_json_error(['message' => 'Invalid JSON'], 400);
        }
        update_post_meta($post_id, '_mp_tree', wp_json_encode($arr));
        wp_send_json_success(['ok' => true]);
    }

    /** AJAX: load existing JSON */
    public function ajax_get_tree() {
        check_ajax_referer(self::NONCE, 'nonce');
        $post_id = (int) filter_input(INPUT_GET, 'post_id', FILTER_SANITIZE_NUMBER_INT);
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
        }
        $tree = get_post_meta($post_id, '_mp_tree', true);
        wp_send_json_success(['tree' => $tree]);
    }

    /** AJAX: generate new draft post (editor button) */
    public function ajax_generate_post() {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
        }

        $raw_tree = filter_input(INPUT_POST, 'tree', FILTER_DEFAULT);
        $raw_tree = (null !== $raw_tree) ? wp_unslash($raw_tree) : '';
        $map      = json_decode($raw_tree, true);

        $title_in = filter_input(INPUT_POST, 'title', FILTER_UNSAFE_RAW);
        $title    = $title_in ? sanitize_text_field( wp_unslash($title_in) ) : 'MindPress Draft';

        if (!is_array($map)) {
            wp_send_json_error(['message' => 'Invalid map'], 400);
        }

        $content = $this->tree_to_content($map);
        $new_id = wp_insert_post([
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => 'draft',
            'post_type'    => 'post',
        ], true);

        if (is_wp_error($new_id)) {
            wp_send_json_error(['message' => $new_id->get_error_message()]);
        }
        wp_send_json_success(['post_id' => $new_id, 'edit_link' => get_edit_post_link($new_id, 'raw')]);
    }

    /** AJAX: insert into current post */
    public function ajax_insert_into() {
        check_ajax_referer(self::NONCE, 'nonce');
        $post_id  = (int) filter_input(INPUT_POST, 'post_id', FILTER_SANITIZE_NUMBER_INT);
        $raw_tree = filter_input(INPUT_POST, 'tree', FILTER_DEFAULT);
        $raw_tree = (null !== $raw_tree) ? wp_unslash($raw_tree) : '';
        $map      = json_decode($raw_tree, true);

        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
        }
        if (!is_array($map)) {
            wp_send_json_error(['message' => 'Invalid map'], 400);
        }

        $content = $this->tree_to_content($map);
        $res = wp_update_post([
            'ID'           => $post_id,
            'post_content' => $content,
        ], true);

        if (is_wp_error($res)) {
            wp_send_json_error(['message' => $res->get_error_message()]);
        }
        wp_send_json_success(['post_id' => $post_id, 'edit_link' => get_edit_post_link($post_id, 'raw')]);
    }

    /** Row actions: Generate Draft / Duplicate */
    public function row_actions($actions, $post) {
        if ($post->post_type !== self::CPT) {
            return $actions;
        }
        if (!current_user_can('edit_post', $post->ID)) {
            return $actions;
        }

        $gen_url = wp_nonce_url(
            add_query_arg(
                array(
                    'action'  => 'mp_gen_from_map',
                    'post_id' => $post->ID,
                ),
                admin_url('admin-post.php')
            ),
            self::NONCE,
            'nonce'
        );

        $dup_url = wp_nonce_url(
            add_query_arg(
                array(
                    'action'  => 'mp_duplicate_map',
                    'post_id' => $post->ID,
                ),
                admin_url('admin-post.php')
            ),
            self::NONCE,
            'nonce'
        );

        $actions['mp-generate']  = '<a href="' . esc_url($gen_url) . '"><span class="dashicons dashicons-edit-page" style="vertical-align:text-bottom;"></span> ' . esc_html__('Generate Draft', 'mindpress') . '</a>';
        $actions['mp-duplicate'] = '<a href="' . esc_url($dup_url) . '"><span class="dashicons dashicons-admin-page" style="vertical-align:text-bottom;"></span> ' . esc_html__('Duplicate', 'mindpress') . '</a>';

        return $actions;
    }

    /** Admin action: Generate Draft from a stored map (reusable) */
    public function admin_gen_from_map() {
        $nonce   = (string) filter_input(INPUT_GET, 'nonce',   FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $post_id = (int)    filter_input(INPUT_GET, 'post_id', FILTER_SANITIZE_NUMBER_INT);

        if (!$nonce || !wp_verify_nonce($nonce, self::NONCE)) {
            wp_die(esc_html__('Invalid request (nonce).', 'mindpress'));
        }
        $map_post = get_post($post_id);
        if (!$map_post || $map_post->post_type !== self::CPT) {
            wp_die(esc_html__('Invalid Mind Map.', 'mindpress'));
        }
        if (!current_user_can('edit_post', $post_id) || !current_user_can('edit_posts')) {
            wp_die(esc_html__('Permission denied.', 'mindpress'));
        }

        $tree = get_post_meta($post_id, '_mp_tree', true);
        $arr  = json_decode($tree, true);
        if (!is_array($arr)) {
            wp_die(esc_html__('This Mind Map has no valid content to generate.', 'mindpress'));
        }

        $content = $this->tree_to_content($arr);
        $base  = $map_post->post_title ?: __('MindPress Draft', 'mindpress');
        $stamp = wp_date( get_option('date_format', 'M j, Y') . ' ' . get_option('time_format', 'H:i') );
        $title = sprintf('%s – %s', $base, $stamp);

        $new_id = wp_insert_post([
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => 'draft',
            'post_type'    => 'post',
        ], true);
        if (is_wp_error($new_id)) {
            wp_die(esc_html($new_id->get_error_message()));
        }

        // Track usage
        $count = (int) get_post_meta($post_id, '_mp_generated_count', true);
        update_post_meta($post_id, '_mp_generated_count', (string) ($count + 1));
        update_post_meta($post_id, '_mp_generated_last',  (string) time());

        wp_safe_redirect( get_edit_post_link($new_id, 'raw') );
        exit;
    }

    /** Admin action: Duplicate an existing Mind Map */
    public function admin_duplicate_map() {
        $nonce   = (string) filter_input(INPUT_GET, 'nonce',   FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $post_id = (int)    filter_input(INPUT_GET, 'post_id', FILTER_SANITIZE_NUMBER_INT);

        if (!$nonce || !wp_verify_nonce($nonce, self::NONCE)) {
            wp_die(esc_html__('Invalid request (nonce).', 'mindpress'));
        }
        $map_post = get_post($post_id);
        if (!$map_post || $map_post->post_type !== self::CPT) {
            wp_die(esc_html__('Invalid Mind Map.', 'mindpress'));
        }
        if (!current_user_can('edit_post', $post_id)) {
            wp_die(esc_html__('Permission denied.', 'mindpress'));
        }

        $tree   = get_post_meta($post_id, '_mp_tree', true);
        $title  = $map_post->post_title ? $map_post->post_title . ' – ' . __('Copy', 'mindpress') : __('Mind Map Copy', 'mindpress');
        $is_tpl = get_post_meta($post_id, '_mp_is_template', true) === '1';

        $new_id = wp_insert_post([
            'post_title'  => $title,
            'post_status' => 'draft',
            'post_type'   => self::CPT,
        ], true);
        if (is_wp_error($new_id)) {
            wp_die(esc_html($new_id->get_error_message()));
        }

        if (!empty($tree)) {
            update_post_meta($new_id, '_mp_tree', $tree);
        }
        update_post_meta($new_id, '_mp_is_template', $is_tpl ? '1' : '0');

        wp_safe_redirect( get_edit_post_link($new_id, 'raw') );
        exit;
    }

    /** Columns: add Times Generated / Last Generated */
    public function columns($cols) {
        // Keep cb, title, date; insert two new columns in between
        $new = [];
        foreach ($cols as $key => $label) {
            $new[$key] = $label;
            if ('title' === $key) {
                $new['mp_generated_count'] = __('Times Generated', 'mindpress');
                $new['mp_generated_last']  = __('Last Generated', 'mindpress');
            }
        }
        return $new;
    }

    public function columns_output($column, $post_id) {
        if ('mp_generated_count' === $column) {
            $count = (int) get_post_meta($post_id, '_mp_generated_count', true);
            echo esc_html( (string) $count );
        } elseif ('mp_generated_last' === $column) {
            $ts = (int) get_post_meta($post_id, '_mp_generated_last', true);
            if ($ts > 0) {
                // Format using site settings
                $fmt = get_option('date_format', 'M j, Y') . ' ' . get_option('time_format', 'H:i');
                echo esc_html( date_i18n($fmt, $ts) );
            } else {
                echo '—';
            }
        }
    }

    public function columns_sortable($columns) {
        $columns['mp_generated_count'] = 'mp_generated_count';
        $columns['mp_generated_last']  = 'mp_generated_last';
        return $columns;
    }

    public function columns_sort_query($query) {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        // Defensive: only touch our CPT list table
        if (!$screen || $screen->post_type !== self::CPT) {
            return;
        }
        $orderby = $query->get('orderby');
        if ('mp_generated_count' === $orderby) {
            $query->set('meta_key', '_mp_generated_count');
            $query->set('orderby',  'meta_value_num');
        } elseif ('mp_generated_last' === $orderby) {
            $query->set('meta_key', '_mp_generated_last');
            $query->set('orderby',  'meta_value_num');
        }
    }

    /** Map → HTML */
    private function tree_to_content($tree) {
        $lines = [];
        $walk = function($node, $level) use (&$walk, &$lines) {
            $text = isset($node['text']) ? wp_strip_all_tags($node['text']) : '';
            if ($text !== '') {
                $prefix  = str_repeat('#', min(6, $level + 1));
                $lines[] = $prefix . ' ' . $text;
                if (!empty($node['notes'])) {
                    $lines[] = '';
                    $lines[] = wp_strip_all_tags($node['notes']);
                }
                $lines[] = '';
            }
            if (!empty($node['children']) && is_array($node['children'])) {
                foreach ($node['children'] as $child) {
                    $walk($child, $level + 1);
                }
            }
        };
        $walk($tree, 1);
        return $this->markdown_like_to_html(implode("\n", $lines));
    }

    private function markdown_like_to_html($md) {
        $html  = '';
        foreach (preg_split('/\r?\n/', $md) as $line) {
            if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m)) {
                $h    = strlen($m[1]);
                $html .= '<h' . $h . '>' . esc_html($m[2]) . '</h' . $h . '>';
            } elseif (trim($line) === '') {
                $html .= "\n";
            } else {
                $html .= '<p>' . esc_html($line) . '</p>';
            }
        }
        return $html;
    }
}
new MindPressPlugin();
<?php

if (! defined('ABSPATH')) {
    exit;
}

final class PTACT_Plugin
{
    private const OPTION_KEY = 'ptact_column_config';

    private static ?PTACT_Plugin $instance = null;

    private array $settings = [];

    private array $acf_fields_cache = [];

    private array $column_lookup_cache = [];

    public static function instance(): PTACT_Plugin
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        $saved_settings = get_option(self::OPTION_KEY, []);
        $this->settings = is_array($saved_settings) ? $saved_settings : [];

        add_action('admin_menu', [$this, 'register_settings_page']);
        add_action('admin_init', [$this, 'handle_settings_save']);
        add_action('admin_init', [$this, 'register_list_table_hooks']);
    }

    public function register_settings_page(): void
    {
        add_options_page(
            __('Post Type Columns', 'ptact'),
            __('Post Type Columns', 'ptact'),
            'manage_options',
            'ptact-columns',
            [$this, 'render_settings_page']
        );
    }

    public function handle_settings_save(): void
    {
        if (! isset($_POST['ptact_action']) || $_POST['ptact_action'] !== 'save') {
            return;
        }

        if (! current_user_can('manage_options')) {
            return;
        }

        check_admin_referer('ptact_save_settings', 'ptact_nonce');

        $raw_settings = $_POST['ptact'] ?? [];
        $clean_settings = $this->sanitize_settings($raw_settings);

        update_option(self::OPTION_KEY, $clean_settings, false);

        $this->settings = $clean_settings;
        $this->column_lookup_cache = [];

        $redirect_url = add_query_arg(
            [
                'page' => 'ptact-columns',
                'updated' => '1',
            ],
            admin_url('options-general.php')
        );

        wp_safe_redirect($redirect_url);
        exit;
    }

    private function sanitize_settings($raw_settings): array
    {
        if (! is_array($raw_settings)) {
            return [];
        }

        $clean_settings = [];

        foreach ($raw_settings as $post_type => $config) {
            $post_type = sanitize_key((string) $post_type);

            if (! post_type_exists($post_type) || ! is_array($config)) {
                continue;
            }

            $acf_fields = [];
            $taxonomies = [];

            if (isset($config['acf']) && is_array($config['acf'])) {
                foreach ($config['acf'] as $field_name) {
                    $field_name = sanitize_text_field(wp_unslash((string) $field_name));

                    if ($field_name === '' || ! preg_match('/^[A-Za-z0-9_-]+$/', $field_name)) {
                        continue;
                    }

                    $acf_fields[] = $field_name;
                }
            }

            if (isset($config['tax']) && is_array($config['tax'])) {
                foreach ($config['tax'] as $taxonomy) {
                    $taxonomy = sanitize_key(wp_unslash((string) $taxonomy));

                    if ($taxonomy === '' || ! taxonomy_exists($taxonomy)) {
                        continue;
                    }

                    if (! is_object_in_taxonomy($post_type, $taxonomy)) {
                        continue;
                    }

                    $taxonomies[] = $taxonomy;
                }
            }

            $acf_fields = array_values(array_unique($acf_fields));
            $taxonomies = array_values(array_unique($taxonomies));

            if (! empty($acf_fields) || ! empty($taxonomies)) {
                $clean_settings[$post_type] = [
                    'acf' => $acf_fields,
                    'tax' => $taxonomies,
                ];
            }
        }

        return $clean_settings;
    }

    public function register_list_table_hooks(): void
    {
        $post_types = get_post_types(['show_ui' => true], 'names');

        foreach ($post_types as $post_type) {
            if ($post_type === 'attachment') {
                continue;
            }

            if ($post_type === 'page') {
                add_filter('manage_pages_columns', function (array $columns) use ($post_type): array {
                    return $this->filter_list_table_columns($columns, $post_type);
                }, 20);
            }

            add_filter("manage_{$post_type}_posts_columns", function (array $columns) use ($post_type): array {
                return $this->filter_list_table_columns($columns, $post_type);
            }, 20);

            add_action("manage_{$post_type}_posts_custom_column", function (string $column_name, int $post_id) use ($post_type): void {
                $this->render_custom_column($column_name, $post_id, $post_type);
            }, 10, 2);
        }
    }

    private function filter_list_table_columns(array $columns, string $post_type): array
    {
        if (! array_key_exists($post_type, $this->settings)) {
            return $columns;
        }

        $config = $this->get_post_type_config($post_type);

        $selected_acf_fields = $config['acf'];
        $selected_taxonomies = $config['tax'];

        $new_columns = [];

        if (! empty($selected_acf_fields)) {
            $available_acf_fields = $this->get_available_acf_fields($post_type);

            foreach ($selected_acf_fields as $field_name) {
                $field_label = $available_acf_fields[$field_name]['label'] ?? $field_name;
                $new_columns[$this->acf_column_key($field_name)] = sprintf(
                    /* translators: %s: ACF field label. */
                    __('ACF: %s', 'ptact'),
                    $field_label
                );
            }
        }

        if (! empty($selected_taxonomies)) {
            foreach ($selected_taxonomies as $taxonomy) {
                $native_key = 'taxonomy-' . $taxonomy;

                if (isset($columns[$native_key])) {
                    continue;
                }

                $taxonomy_object = get_taxonomy($taxonomy);

                $new_columns[$this->taxonomy_column_key($taxonomy)] =
                    $taxonomy_object && isset($taxonomy_object->labels->singular_name)
                        ? $taxonomy_object->labels->singular_name
                        : $taxonomy;
            }
        }

        if (empty($new_columns)) {
            return $columns;
        }

        return $this->insert_columns_before_date($columns, $new_columns);
    }

    private function insert_columns_before_date(array $columns, array $new_columns): array
    {
        $final_columns = [];
        $inserted = false;

        foreach ($columns as $column_key => $column_label) {
            if ($column_key === 'date') {
                foreach ($new_columns as $new_key => $new_label) {
                    $final_columns[$new_key] = $new_label;
                }
                $inserted = true;
            }

            $final_columns[$column_key] = $column_label;
        }

        if (! $inserted) {
            foreach ($new_columns as $new_key => $new_label) {
                $final_columns[$new_key] = $new_label;
            }
        }

        return $final_columns;
    }

    private function render_custom_column(string $column_name, int $post_id, string $post_type): void
    {
        $column_lookup = $this->get_column_lookup($post_type);

        if (! isset($column_lookup[$column_name])) {
            return;
        }

        $column_definition = $column_lookup[$column_name];

        if ($column_definition['type'] === 'acf') {
            $value = $this->get_acf_value($post_id, $column_definition['name']);
            echo esc_html($this->stringify_value($value));
            return;
        }

        if ($column_definition['type'] === 'tax') {
            $taxonomy = $column_definition['name'];
            $terms = get_the_terms($post_id, $taxonomy);

            if (empty($terms) || is_wp_error($terms)) {
                echo '-';
                return;
            }

            $term_links = [];

            foreach ($terms as $term) {
                $url = add_query_arg(
                    [
                        'post_type' => $post_type,
                        $taxonomy => $term->slug,
                    ],
                    admin_url('edit.php')
                );

                $term_links[] = sprintf(
                    '<a href="%s">%s</a>',
                    esc_url($url),
                    esc_html($term->name)
                );
            }

            echo implode(', ', $term_links); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
    }

    private function get_column_lookup(string $post_type): array
    {
        if (isset($this->column_lookup_cache[$post_type])) {
            return $this->column_lookup_cache[$post_type];
        }

        $lookup = [];

        $config = $this->get_post_type_config($post_type);

        foreach ($config['acf'] as $field_name) {
            $lookup[$this->acf_column_key($field_name)] = [
                'type' => 'acf',
                'name' => $field_name,
            ];
        }

        foreach ($config['tax'] as $taxonomy) {
            $lookup[$this->taxonomy_column_key($taxonomy)] = [
                'type' => 'tax',
                'name' => $taxonomy,
            ];
        }

        $this->column_lookup_cache[$post_type] = $lookup;

        return $lookup;
    }

    private function get_acf_value(int $post_id, string $field_name)
    {
        if (function_exists('get_field')) {
            $value = get_field($field_name, $post_id);
            if ($value !== null) {
                return $value;
            }
        }

        return get_post_meta($post_id, $field_name, true);
    }

    private function stringify_value($value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        if (is_bool($value)) {
            return $value ? __('Yes', 'ptact') : __('No', 'ptact');
        }

        if (is_scalar($value)) {
            return trim(wp_strip_all_tags((string) $value));
        }

        if ($value instanceof WP_Post) {
            return get_the_title($value);
        }

        if ($value instanceof WP_Term) {
            return $value->name;
        }

        if ($value instanceof WP_User) {
            return $value->display_name;
        }

        if (is_array($value)) {
            $parts = [];

            foreach ($value as $nested_value) {
                $formatted = $this->stringify_value($nested_value);
                if ($formatted !== '-') {
                    $parts[] = $formatted;
                }
            }

            $parts = array_values(array_unique($parts));

            if (empty($parts)) {
                return '-';
            }

            return implode(', ', $parts);
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return '-';
    }

    private function acf_column_key(string $field_name): string
    {
        return 'ptact_acf_' . md5($field_name);
    }

    private function taxonomy_column_key(string $taxonomy): string
    {
        return 'ptact_tax_' . md5($taxonomy);
    }

    private function get_available_acf_fields(string $post_type): array
    {
        if (isset($this->acf_fields_cache[$post_type])) {
            return $this->acf_fields_cache[$post_type];
        }

        if (! function_exists('acf_get_field_groups') || ! function_exists('acf_get_fields')) {
            $this->acf_fields_cache[$post_type] = [];
            return [];
        }

        $fields = [];

        $groups = acf_get_field_groups(['post_type' => $post_type]);
        if (! is_array($groups)) {
            $this->acf_fields_cache[$post_type] = [];
            return [];
        }

        foreach ($groups as $group) {
            if (! is_array($group) || empty($group['key'])) {
                continue;
            }

            $group_fields = acf_get_fields($group['key']);
            if (! is_array($group_fields)) {
                continue;
            }

            $this->collect_acf_fields($group_fields, $fields);
        }

        uasort($fields, static function (array $a, array $b): int {
            return strcasecmp($a['label'], $b['label']);
        });

        $this->acf_fields_cache[$post_type] = $fields;

        return $fields;
    }

    private function collect_acf_fields(array $group_fields, array &$fields): void
    {
        foreach ($group_fields as $field) {
            if (! is_array($field) || empty($field['name'])) {
                continue;
            }

            $field_type = isset($field['type']) ? (string) $field['type'] : '';
            if (in_array($field_type, ['tab', 'accordion', 'message'], true)) {
                continue;
            }

            $field_name = (string) $field['name'];
            $field_label = isset($field['label']) && $field['label'] !== ''
                ? (string) $field['label']
                : $field_name;

            $fields[$field_name] = [
                'name' => $field_name,
                'label' => $field_label,
                'type' => $field_type,
            ];

            if (isset($field['sub_fields']) && is_array($field['sub_fields'])) {
                $this->collect_acf_fields($field['sub_fields'], $fields);
            }
        }
    }

    private function get_post_type_config(string $post_type): array
    {
        $config = $this->settings[$post_type] ?? [];

        $acf_fields = [];
        if (isset($config['acf']) && is_array($config['acf'])) {
            foreach ($config['acf'] as $field_name) {
                if (is_string($field_name) && $field_name !== '') {
                    $acf_fields[] = $field_name;
                }
            }
        }

        $taxonomies = [];
        if (isset($config['tax']) && is_array($config['tax'])) {
            foreach ($config['tax'] as $taxonomy) {
                if (is_string($taxonomy) && taxonomy_exists($taxonomy)) {
                    $taxonomies[] = $taxonomy;
                }
            }
        }

        return [
            'acf' => array_values(array_unique($acf_fields)),
            'tax' => array_values(array_unique($taxonomies)),
        ];
    }

    public function render_settings_page(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $post_types = $this->get_ui_post_types();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Post Type Admin Columns', 'ptact') . '</h1>';
        echo '<p>' . esc_html__('Select ACF fields and taxonomies to expose as columns. Each user can still toggle these on or off from Screen Options on list pages.', 'ptact') . '</p>';

        if (isset($_GET['updated']) && sanitize_text_field(wp_unslash((string) $_GET['updated'])) === '1') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Settings saved.', 'ptact') . '</p></div>';
        }

        echo '<form method="post" action="">';
        wp_nonce_field('ptact_save_settings', 'ptact_nonce');
        echo '<input type="hidden" name="ptact_action" value="save" />';

        foreach ($post_types as $post_type => $post_type_object) {
            $config = $this->get_post_type_config($post_type);

            $selected_acf_fields = $config['acf'];
            $selected_taxonomies = $config['tax'];

            $acf_fields = $this->get_available_acf_fields($post_type);

            $taxonomies = get_object_taxonomies($post_type, 'objects');
            if (! is_array($taxonomies)) {
                $taxonomies = [];
            }

            $taxonomies = array_filter($taxonomies, static function ($taxonomy_object): bool {
                return is_object($taxonomy_object) && ! empty($taxonomy_object->show_ui);
            });

            uasort($taxonomies, static function ($a, $b): int {
                $a_label = isset($a->labels->singular_name) ? $a->labels->singular_name : $a->name;
                $b_label = isset($b->labels->singular_name) ? $b->labels->singular_name : $b->name;
                return strcasecmp($a_label, $b_label);
            });

            $post_type_label = isset($post_type_object->labels->singular_name)
                ? $post_type_object->labels->singular_name
                : $post_type;

            echo '<hr style="margin:24px 0;" />';
            echo '<h2>' . esc_html($post_type_label) . '</h2>';
            echo '<p><code>' . esc_html($post_type) . '</code></p>';

            echo '<table class="form-table" role="presentation"><tbody>';

            echo '<tr>';
            echo '<th scope="row">' . esc_html__('ACF Fields', 'ptact') . '</th>';
            echo '<td>';

            if (empty($acf_fields)) {
                echo '<p>' . esc_html__('No ACF fields found for this post type, or ACF is not active.', 'ptact') . '</p>';
            } else {
                foreach ($acf_fields as $field_name => $field) {
                    $checked = in_array($field_name, $selected_acf_fields, true) ? ' checked="checked"' : '';

                    echo '<label style="display:block;margin-bottom:6px;">';
                    echo '<input type="checkbox" name="ptact[' . esc_attr($post_type) . '][acf][]" value="' . esc_attr($field_name) . '"' . $checked . ' /> ';
                    echo esc_html($field['label']) . ' <code>' . esc_html($field_name) . '</code>';
                    echo '</label>';
                }
            }

            $missing_acf_fields = array_diff($selected_acf_fields, array_keys($acf_fields));
            if (! empty($missing_acf_fields)) {
                echo '<p><strong>' . esc_html__('Saved fields not currently discoverable:', 'ptact') . '</strong></p>';

                foreach ($missing_acf_fields as $field_name) {
                    echo '<label style="display:block;margin-bottom:6px;">';
                    echo '<input type="checkbox" name="ptact[' . esc_attr($post_type) . '][acf][]" value="' . esc_attr($field_name) . '" checked="checked" /> ';
                    echo esc_html($field_name) . ' <code>' . esc_html($field_name) . '</code>';
                    echo '</label>';
                }
            }

            echo '</td>';
            echo '</tr>';

            echo '<tr>';
            echo '<th scope="row">' . esc_html__('Taxonomies', 'ptact') . '</th>';
            echo '<td>';

            if (empty($taxonomies)) {
                echo '<p>' . esc_html__('No UI-enabled taxonomies found for this post type.', 'ptact') . '</p>';
            } else {
                foreach ($taxonomies as $taxonomy => $taxonomy_object) {
                    $checked = in_array($taxonomy, $selected_taxonomies, true) ? ' checked="checked"' : '';
                    $label = isset($taxonomy_object->labels->singular_name)
                        ? $taxonomy_object->labels->singular_name
                        : $taxonomy;

                    echo '<label style="display:block;margin-bottom:6px;">';
                    echo '<input type="checkbox" name="ptact[' . esc_attr($post_type) . '][tax][]" value="' . esc_attr($taxonomy) . '"' . $checked . ' /> ';
                    echo esc_html($label) . ' <code>' . esc_html($taxonomy) . '</code>';
                    echo '</label>';
                }
            }

            $known_taxonomies = array_keys($taxonomies);
            $missing_taxonomies = array_diff($selected_taxonomies, $known_taxonomies);
            if (! empty($missing_taxonomies)) {
                echo '<p><strong>' . esc_html__('Saved taxonomies not currently discoverable:', 'ptact') . '</strong></p>';

                foreach ($missing_taxonomies as $taxonomy) {
                    echo '<label style="display:block;margin-bottom:6px;">';
                    echo '<input type="checkbox" name="ptact[' . esc_attr($post_type) . '][tax][]" value="' . esc_attr($taxonomy) . '" checked="checked" /> ';
                    echo esc_html($taxonomy) . ' <code>' . esc_html($taxonomy) . '</code>';
                    echo '</label>';
                }
            }

            echo '</td>';
            echo '</tr>';

            echo '</tbody></table>';
        }

        submit_button(__('Save Columns', 'ptact'));

        echo '</form>';
        echo '</div>';
    }

    private function get_ui_post_types(): array
    {
        $post_types = get_post_types(['show_ui' => true], 'objects');

        if (isset($post_types['attachment'])) {
            unset($post_types['attachment']);
        }

        uasort($post_types, static function ($a, $b): int {
            $a_label = isset($a->labels->singular_name) ? $a->labels->singular_name : $a->name;
            $b_label = isset($b->labels->singular_name) ? $b->labels->singular_name : $b->name;
            return strcasecmp($a_label, $b_label);
        });

        return $post_types;
    }
}

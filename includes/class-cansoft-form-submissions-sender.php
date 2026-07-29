<?php
if (!defined('ABSPATH')) {
    exit;
}

class Cansoft_Form_Submissions_Sender {

    private $option_name = 'cansoft_form_send_settings';

    public function init() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);

        // AJAX handlers for testing connection and local requests
        add_action('wp_ajax_cansoft_form_get_metrics', [$this, 'ajax_get_metrics']);
        add_action('wp_ajax_nopriv_cansoft_form_get_metrics', [$this, 'ajax_get_metrics']);
        add_action('wp_ajax_cansoft_form_test_connection', [$this, 'ajax_test_connection']);
    }

    public function add_admin_menu() {
        add_options_page(
            __('Cansoft Form Info', 'cansoft-form-submissions-send'),
            __('Cansoft Form Info', 'cansoft-form-submissions-send'),
            'manage_options',
            'cansoft-form-info',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings() {
        register_setting($this->option_name, $this->option_name);

        $options = get_option($this->option_name, []);
        if (empty($options['api_token'])) {
            $options['api_token'] = wp_generate_password(32, false);
            update_option($this->option_name, $options);
        }
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = get_option($this->option_name, []);
        $api_token = isset($options['api_token']) ? $options['api_token'] : '';
        $site_url = site_url();
        ?>
        <div class="wrap">
            <h1><?php _e('Cansoft Form Submissions Info - Client Settings', 'cansoft-form-submissions-send'); ?></h1>
            <p><?php _e('This plugin captures form submission metrics (Elementor Pro, Flamingo/CF7, WPForms) and exposes a secure endpoint for the Cansoft PM Mother Site.', 'cansoft-form-submissions-send'); ?></p>

            <form method="post" action="options.php">
                <?php
                settings_fields($this->option_name);
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="api_token"><?php _e('API Secret Token', 'cansoft-form-submissions-send'); ?></label></th>
                        <td>
                            <input type="text" name="<?php echo esc_attr($this->option_name); ?>[api_token]" id="api_token" class="large-text" value="<?php echo esc_attr($api_token); ?>" readonly onclick="this.select();">
                            <p class="description"><?php _e('Copy this Secret Token and paste it into the Mother Site under Project Progress Settings -> Form Submissions.', 'cansoft-form-submissions-send'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Client Site URL', 'cansoft-form-submissions-send'); ?></th>
                        <td>
                            <code><?php echo esc_html($site_url); ?></code>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Save Settings', 'cansoft-form-submissions-send')); ?>
            </form>

            <hr>

            <h2><?php _e('Test Connection', 'cansoft-form-submissions-send'); ?></h2>
            <p><?php _e('Test form metrics calculation for current month.', 'cansoft-form-submissions-send'); ?></p>
            <button type="button" id="cansoft-form-test-btn" class="button button-secondary">
                <?php _e('Test Form Metrics Query', 'cansoft-form-submissions-send'); ?>
            </button>
            <div id="cansoft-form-test-result" style="margin-top: 15px;"></div>

            <script>
            jQuery(document).ready(function($) {
                $('#cansoft-form-test-btn').on('click', function() {
                    var $btn = $(this);
                    var $res = $('#cansoft-form-test-result');
                    $btn.prop('disabled', true).text('Testing...');
                    $res.html('<p style="color: #666;">Querying local database...</p>');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'cansoft_form_test_connection',
                            _wpnonce: '<?php echo wp_create_nonce("cansoft_form_test_nonce"); ?>'
                        },
                        success: function(resp) {
                            $btn.prop('disabled', false).text('Test Form Metrics Query');
                            if (resp.success) {
                                var data = resp.data;
                                var html = '<div class="notice notice-success inline" style="padding: 12px;"><p><strong>Success! Form Metrics Calculated:</strong></p>';
                                html += '<ul>';
                                html += '<li><strong>Total Submissions:</strong> ' + data.total_submissions + '</li>';
                                html += '<li><strong>Legitimate Queries:</strong> ' + data.legit_submissions + '</li>';
                                html += '<li><strong>Spam Queries:</strong> ' + data.spam_submissions + '</li>';
                                html += '</ul></div>';
                                $res.html(html);
                            } else {
                                $res.html('<div class="notice notice-error inline" style="padding: 12px;"><p>Error: ' + (resp.data || 'Failed to query forms') + '</p></div>');
                            }
                        },
                        error: function() {
                            $btn.prop('disabled', false).text('Test Form Metrics Query');
                            $res.html('<div class="notice notice-error inline" style="padding: 12px;"><p>Server communication error.</p></div>');
                        }
                    });
                });
            });
            </script>
        </div>
        <?php
    }

    public function register_rest_routes() {
        register_rest_route('cansoft-form/v1', '/metrics', [
            'methods'  => ['GET', 'POST'],
            'callback' => [$this, 'handle_rest_metrics'],
            'permission_callback' => [$this, 'validate_api_request'],
        ]);
    }

    public function validate_api_request($request) {
        $options = get_option($this->option_name, []);
        $expected_token = isset($options['api_token']) ? trim($options['api_token']) : '';
        if (empty($expected_token)) {
            return false;
        }

        // Check Authorization header
        $auth_header = $request->get_header('authorization');
        if ($auth_header && preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
            $provided_token = trim($matches[1]);
            if (hash_equals($expected_token, $provided_token)) {
                return true;
            }
        }

        // Check token in GET/POST parameters
        $provided_param_token = $request->get_param('token');
        if ($provided_param_token && hash_equals($expected_token, trim($provided_param_token))) {
            return true;
        }

        return false;
    }

    public function handle_rest_metrics($request) {
        $period = $request->get_param('period');
        if (empty($period)) {
            $period = date('Y-m');
        }

        $metrics = $this->get_form_metrics_for_period($period);
        return rest_ensure_response($metrics);
    }

    public function ajax_get_metrics() {
        $options = get_option($this->option_name, []);
        $expected_token = isset($options['api_token']) ? trim($options['api_token']) : '';
        
        $token = isset($_REQUEST['token']) ? trim($_REQUEST['token']) : '';
        if (empty($expected_token) || !hash_equals($expected_token, $token)) {
            wp_send_json_error('Unauthorized API Token', 401);
        }

        $period = isset($_REQUEST['period']) ? sanitize_text_field($_REQUEST['period']) : date('Y-m');
        $metrics = $this->get_form_metrics_for_period($period);
        wp_send_json_success($metrics);
    }

    public function ajax_test_connection() {
        check_ajax_referer('cansoft_form_test_nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $period = date('Y-m');
        $metrics = $this->get_form_metrics_for_period($period);
        wp_send_json_success($metrics);
    }

    public function get_form_metrics_for_period($period) {
        global $wpdb;

        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            $period = date('Y-m');
        }

        $start_date = $period . '-01 00:00:00';
        $end_date = date('Y-m-t 23:59:59', strtotime($start_date));

        $legit_submissions = 0;
        $spam_submissions = 0;
        $sources = [];

        // 1. Elementor Pro Submissions (wp_e_submissions)
        $e_submissions_table = $wpdb->prefix . 'e_submissions';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$e_submissions_table}'") === $e_submissions_table) {
            $elementor_counts = $wpdb->get_results($wpdb->prepare(
                "SELECT status, COUNT(*) as count FROM {$e_submissions_table} WHERE created_at_gmt >= %s AND created_at_gmt <= %s GROUP BY status",
                $start_date,
                $end_date
            ));

            $e_legit = 0;
            $e_spam = 0;

            if (!empty($elementor_counts)) {
                foreach ($elementor_counts as $row) {
                    $st = strtolower(trim($row->status));
                    $cnt = intval($row->count);
                    if (in_array($st, ['publish', 'read', 'unread'])) {
                        $e_legit += $cnt;
                    } else if (in_array($st, ['spam', 'trash'])) {
                        $e_spam += $cnt;
                    } else {
                        $e_legit += $cnt;
                    }
                }
            }

            $legit_submissions += $e_legit;
            $spam_submissions += $e_spam;
            $sources['elementor'] = ['legit' => $e_legit, 'spam' => $e_spam];
        }

        // 2. Flamingo / Contact Form 7 (wp_posts post_type = 'flamingo_inbound')
        $posts_table = $wpdb->prefix . 'posts';
        $flamingo_counts = $wpdb->get_results($wpdb->prepare(
            "SELECT post_status, COUNT(*) as count FROM {$posts_table} WHERE post_type = 'flamingo_inbound' AND post_date >= %s AND post_date <= %s GROUP BY post_status",
            $start_date,
            $end_date
        ));

        if (!empty($flamingo_counts)) {
            $f_legit = 0;
            $f_spam = 0;
            foreach ($flamingo_counts as $row) {
                $st = strtolower(trim($row->post_status));
                $cnt = intval($row->count);
                if ($st === 'publish') {
                    $f_legit += $cnt;
                } else if ($st === 'spam' || $st === 'trash') {
                    $f_spam += $cnt;
                } else {
                    $f_legit += $cnt;
                }
            }
            $legit_submissions += $f_legit;
            $spam_submissions += $f_spam;
            $sources['flamingo'] = ['legit' => $f_legit, 'spam' => $f_spam];
        }

        // 3. WPForms (wp_wpforms_entries)
        $wpforms_table = $wpdb->prefix . 'wpforms_entries';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$wpforms_table}'") === $wpforms_table) {
            $wpforms_counts = $wpdb->get_results($wpdb->prepare(
                "SELECT status, COUNT(*) as count FROM {$wpforms_table} WHERE date >= %s AND date <= %s GROUP BY status",
                $start_date,
                $end_date
            ));

            if (!empty($wpforms_counts)) {
                $wp_legit = 0;
                $wp_spam = 0;
                foreach ($wpforms_counts as $row) {
                    $st = strtolower(trim($row->status));
                    $cnt = intval($row->count);
                    if ($st === 'spam' || $st === 'trash') {
                        $wp_spam += $cnt;
                    } else {
                        $wp_legit += $cnt;
                    }
                }
                $legit_submissions += $wp_legit;
                $spam_submissions += $wp_spam;
                $sources['wpforms'] = ['legit' => $wp_legit, 'spam' => $wp_spam];
            }
        }

        $total_submissions = $legit_submissions + $spam_submissions;

        return [
            'status'            => 'success',
            'period'            => $period,
            'total_submissions' => $total_submissions,
            'legit_submissions' => $legit_submissions,
            'spam_submissions'  => $spam_submissions,
            'sources'           => $sources,
        ];
    }
}

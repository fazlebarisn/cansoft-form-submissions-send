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
        register_setting($this->option_name, $this->option_name, [
            'sanitize_callback' => [$this, 'sanitize_settings'],
        ]);

        $options = get_option($this->option_name, []);
        if (empty($options['api_token'])) {
            $options['api_token'] = wp_generate_password(32, false);
            update_option($this->option_name, $options);
        }
    }

    public function sanitize_settings($input) {
        $output = get_option($this->option_name, []);
        if (isset($input['api_token'])) {
            $token = sanitize_text_field(trim($input['api_token']));
            if (!empty($token)) {
                $output['api_token'] = $token;
            } else {
                $output['api_token'] = wp_generate_password(32, false);
            }
        }
        return $output;
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
            <p><?php _e('This plugin captures form submission metrics (Elementor Pro, Flamingo/CF7, CFDB7, WPForms, Fluent Forms, Gravity Forms) and exposes a secure endpoint for the Cansoft PM Mother Site.', 'cansoft-form-submissions-send'); ?></p>

            <form method="post" action="options.php">
                <?php
                settings_fields($this->option_name);
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="api_token"><?php _e('API Secret Token', 'cansoft-form-submissions-send'); ?></label></th>
                        <td>
                            <div style="display: flex; align-items: center; gap: 8px; max-width: 600px;">
                                <input type="text" name="<?php echo esc_attr($this->option_name); ?>[api_token]" id="api_token" class="regular-text" style="flex: 1;" value="<?php echo esc_attr($api_token); ?>" placeholder="<?php esc_attr_e('Enter or generate a 32-character token', 'cansoft-form-submissions-send'); ?>">
                                <button type="button" id="cansoft-generate-token-btn" class="button"><?php _e('Generate New', 'cansoft-form-submissions-send'); ?></button>
                                <button type="button" id="cansoft-copy-token-btn" class="button"><?php _e('Copy', 'cansoft-form-submissions-send'); ?></button>
                            </div>
                            <p class="description"><?php _e('You can enter a custom token or click "Generate New". Copy this Secret Token and paste it into the Mother Site under Project Settings -> API -> Form Submissions Secret Token.', 'cansoft-form-submissions-send'); ?></p>
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

            <h2><?php _e('Test Metrics Query', 'cansoft-form-submissions-send'); ?></h2>
            <p><?php _e('Test form metrics calculation for a selected month directly from your database.', 'cansoft-form-submissions-send'); ?></p>
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 12px;">
                <label for="cansoft-test-period"><strong><?php _e('Month:', 'cansoft-form-submissions-send'); ?></strong></label>
                <input type="month" id="cansoft-test-period" value="<?php echo esc_attr(date('Y-m')); ?>" class="regular-text" style="max-width: 180px;">
                <button type="button" id="cansoft-form-test-btn" class="button button-secondary">
                    <?php _e('Test Form Metrics Query', 'cansoft-form-submissions-send'); ?>
                </button>
            </div>
            <div id="cansoft-form-test-result" style="margin-top: 15px;"></div>

            <script>
            jQuery(document).ready(function($) {
                $('#cansoft-generate-token-btn').on('click', function(e) {
                    e.preventDefault();
                    var chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
                    var token = '';
                    for (var i = 0; i < 32; i++) {
                        token += chars.charAt(Math.floor(Math.random() * chars.length));
                    }
                    $('#api_token').val(token).focus();
                    alert('<?php echo esc_js(__('New token generated! Click "Save Settings" below to activate it.', 'cansoft-form-submissions-send')); ?>');
                });

                $('#cansoft-copy-token-btn').on('click', function(e) {
                    e.preventDefault();
                    var $input = $('#api_token');
                    $input.select();
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText($input.val());
                    } else {
                        document.execCommand('copy');
                    }
                    var $btn = $(this);
                    var orig = $btn.text();
                    $btn.text('<?php echo esc_js(__('Copied!', 'cansoft-form-submissions-send')); ?>');
                    setTimeout(function() { $btn.text(orig); }, 2000);
                });

                $('#cansoft-form-test-btn').on('click', function() {
                    var $btn = $(this);
                    var $res = $('#cansoft-form-test-result');
                    var period = $('#cansoft-test-period').val() || '<?php echo esc_js(date("Y-m")); ?>';
                    $btn.prop('disabled', true).text('Testing...');
                    $res.html('<p style="color: #666;">Querying local database for ' + period + '...</p>');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'cansoft_form_test_connection',
                            period: period,
                            _wpnonce: '<?php echo wp_create_nonce("cansoft_form_test_nonce"); ?>'
                        },
                        success: function(resp) {
                            $btn.prop('disabled', false).text('Test Form Metrics Query');
                            if (resp.success) {
                                var data = resp.data;
                                var html = '<div class="notice notice-success inline" style="padding: 12px;"><p><strong>Success! Form Metrics Calculated for ' + (data.period || period) + ':</strong></p>';
                                html += '<ul>';
                                html += '<li><strong>Total Submissions:</strong> ' + data.total_submissions + '</li>';
                                html += '<li><strong>Legitimate Queries:</strong> ' + data.legit_submissions + '</li>';
                                html += '<li><strong>Spam Queries:</strong> ' + data.spam_submissions + '</li>';
                                html += '</ul>';
                                if (data.sources) {
                                    html += '<p><strong>Detected Sources:</strong></p><ul>';
                                    $.each(data.sources, function(src, sData) {
                                        html += '<li>' + src.toUpperCase() + ': ' + (sData.legit + sData.spam) + ' entries (' + sData.legit + ' legit, ' + sData.spam + ' spam)</li>';
                                    });
                                    html += '</ul>';
                                }
                                html += '</div>';
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

        $period = isset($_POST['period']) ? sanitize_text_field($_POST['period']) : '';
        if (empty($period) || !preg_match('/^\d{4}-\d{2}$/', $period)) {
            $period = current_time('Y-m');
        }
        $metrics = $this->get_form_metrics_for_period($period);
        wp_send_json_success($metrics);
    }

    public function get_form_metrics_for_period($period) {
        global $wpdb;

        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            $period = current_time('Y-m');
        }

        // Local date bounds (matches WordPress site timezone)
        $start_date_local = $period . '-01 00:00:00';
        $end_date_local   = date('Y-m-t 23:59:59', strtotime($start_date_local));

        // GMT / UTC date bounds (for database columns stored in GMT)
        if (function_exists('get_gmt_from_date')) {
            $start_date_gmt = get_gmt_from_date($start_date_local);
            $end_date_gmt   = get_gmt_from_date($end_date_local);
        } else {
            $start_date_gmt = gmdate('Y-m-d H:i:s', strtotime($start_date_local));
            $end_date_gmt   = gmdate('Y-m-d H:i:s', strtotime($end_date_local));
        }

        $legit_submissions = 0;
        $spam_submissions  = 0;
        $sources           = [];

        // 1. Elementor Pro Submissions (wp_e_submissions)
        $e_submissions_table = $wpdb->prefix . 'e_submissions';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$e_submissions_table}'") === $e_submissions_table) {
            $has_created_at = $wpdb->get_var("SHOW COLUMNS FROM `{$e_submissions_table}` LIKE 'created_at'");
            if ($has_created_at) {
                // If created_at exists, use local datetime bounds; fall back to created_at_gmt with GMT bounds
                $elementor_counts = $wpdb->get_results($wpdb->prepare(
                    "SELECT status, COUNT(*) as count 
                     FROM `{$e_submissions_table}` 
                     WHERE (created_at IS NOT NULL AND created_at != '0000-00-00 00:00:00' AND created_at >= %s AND created_at <= %s)
                        OR ((created_at IS NULL OR created_at = '0000-00-00 00:00:00') AND created_at_gmt >= %s AND created_at_gmt <= %s)
                     GROUP BY status",
                    $start_date_local,
                    $end_date_local,
                    $start_date_gmt,
                    $end_date_gmt
                ));
            } else {
                $elementor_counts = $wpdb->get_results($wpdb->prepare(
                    "SELECT status, COUNT(*) as count 
                     FROM `{$e_submissions_table}` 
                     WHERE created_at_gmt >= %s AND created_at_gmt <= %s 
                     GROUP BY status",
                    $start_date_gmt,
                    $end_date_gmt
                ));
            }

            $e_legit = 0;
            $e_spam  = 0;

            if (!empty($elementor_counts)) {
                foreach ($elementor_counts as $row) {
                    $st  = strtolower(trim((string) $row->status));
                    $cnt = intval($row->count);
                    if (in_array($st, ['spam', 'trash'], true)) {
                        $e_spam += $cnt;
                    } else {
                        $e_legit += $cnt;
                    }
                }
            }

            if ($e_legit > 0 || $e_spam > 0) {
                $legit_submissions += $e_legit;
                $spam_submissions  += $e_spam;
                $sources['elementor'] = ['legit' => $e_legit, 'spam' => $e_spam];
            }
        }

        // 2. Flamingo / Contact Form 7 (wp_posts post_type = 'flamingo_inbound')
        $posts_table = $wpdb->prefix . 'posts';
        $flamingo_counts = $wpdb->get_results($wpdb->prepare(
            "SELECT post_status, COUNT(*) as count 
             FROM `{$posts_table}` 
             WHERE post_type = 'flamingo_inbound' 
               AND post_date >= %s AND post_date <= %s 
             GROUP BY post_status",
            $start_date_local,
            $end_date_local
        ));

        if (!empty($flamingo_counts)) {
            $f_legit = 0;
            $f_spam  = 0;
            foreach ($flamingo_counts as $row) {
                $st  = strtolower(trim((string) $row->post_status));
                $cnt = intval($row->count);
                if (in_array($st, ['spam', 'trash', 'flamingo-spam'], true)) {
                    $f_spam += $cnt;
                } else {
                    $f_legit += $cnt;
                }
            }
            if ($f_legit > 0 || $f_spam > 0) {
                $legit_submissions += $f_legit;
                $spam_submissions  += $f_spam;
                $sources['flamingo'] = ['legit' => $f_legit, 'spam' => $f_spam];
            }
        }

        // 3. CFDB7 (Contact Form 7 Database Addon - wp_db7_forms)
        $cfdb7_table = $wpdb->prefix . 'db7_forms';
        if (!isset($sources['flamingo']) && $wpdb->get_var("SHOW TABLES LIKE '{$cfdb7_table}'") === $cfdb7_table) {
            $cfdb7_count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `{$cfdb7_table}` WHERE form_date >= %s AND form_date <= %s",
                $start_date_local,
                $end_date_local
            ));
            if ($cfdb7_count > 0) {
                $legit_submissions += $cfdb7_count;
                $sources['cfdb7'] = ['legit' => $cfdb7_count, 'spam' => 0];
            }
        }

        // 4. WPForms (wp_wpforms_entries) - date is stored in GMT
        $wpforms_table = $wpdb->prefix . 'wpforms_entries';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$wpforms_table}'") === $wpforms_table) {
            $wpforms_counts = $wpdb->get_results($wpdb->prepare(
                "SELECT status, COUNT(*) as count 
                 FROM `{$wpforms_table}` 
                 WHERE date >= %s AND date <= %s 
                 GROUP BY status",
                $start_date_gmt,
                $end_date_gmt
            ));

            if (!empty($wpforms_counts)) {
                $wp_legit = 0;
                $wp_spam  = 0;
                foreach ($wpforms_counts as $row) {
                    $st  = strtolower(trim((string) $row->status));
                    $cnt = intval($row->count);
                    if (in_array($st, ['spam', 'trash', 'abandoned'], true)) {
                        $wp_spam += $cnt;
                    } else {
                        $wp_legit += $cnt;
                    }
                }
                if ($wp_legit > 0 || $wp_spam > 0) {
                    $legit_submissions += $wp_legit;
                    $spam_submissions  += $wp_spam;
                    $sources['wpforms'] = ['legit' => $wp_legit, 'spam' => $wp_spam];
                }
            }
        }

        // 5. Fluent Forms (wp_fluentform_submissions) - created_at is local time
        $fluent_table = $wpdb->prefix . 'fluentform_submissions';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$fluent_table}'") === $fluent_table) {
            $fluent_counts = $wpdb->get_results($wpdb->prepare(
                "SELECT status, COUNT(*) as count 
                 FROM `{$fluent_table}` 
                 WHERE created_at >= %s AND created_at <= %s 
                 GROUP BY status",
                $start_date_local,
                $end_date_local
            ));

            if (!empty($fluent_counts)) {
                $ff_legit = 0;
                $ff_spam  = 0;
                foreach ($fluent_counts as $row) {
                    $st  = strtolower(trim((string) $row->status));
                    $cnt = intval($row->count);
                    if (in_array($st, ['trash', 'trashed', 'spam'], true)) {
                        $ff_spam += $cnt;
                    } else {
                        $ff_legit += $cnt;
                    }
                }
                if ($ff_legit > 0 || $ff_spam > 0) {
                    $legit_submissions += $ff_legit;
                    $spam_submissions  += $ff_spam;
                    $sources['fluent_forms'] = ['legit' => $ff_legit, 'spam' => $ff_spam];
                }
            }
        }

        // 6. Gravity Forms (wp_gf_entry) - date_created is GMT
        $gf_table = $wpdb->prefix . 'gf_entry';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$gf_table}'") === $gf_table) {
            $gf_counts = $wpdb->get_results($wpdb->prepare(
                "SELECT status, COUNT(*) as count 
                 FROM `{$gf_table}` 
                 WHERE date_created >= %s AND date_created <= %s 
                 GROUP BY status",
                $start_date_gmt,
                $end_date_gmt
            ));

            if (!empty($gf_counts)) {
                $gf_legit = 0;
                $gf_spam  = 0;
                foreach ($gf_counts as $row) {
                    $st  = strtolower(trim((string) $row->status));
                    $cnt = intval($row->count);
                    if (in_array($st, ['spam', 'trash'], true)) {
                        $gf_spam += $cnt;
                    } else {
                        $gf_legit += $cnt;
                    }
                }
                if ($gf_legit > 0 || $gf_spam > 0) {
                    $legit_submissions += $gf_legit;
                    $spam_submissions  += $gf_spam;
                    $sources['gravity_forms'] = ['legit' => $gf_legit, 'spam' => $gf_spam];
                }
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

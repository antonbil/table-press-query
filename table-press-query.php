<?php
/**
 * Plugin Name:       TablePress Query
 * Plugin URI:        https://your-plugin-website.com/
 * Description:       Extends TablePress by allowing advanced queries on its tables and displaying results via a shortcode. Enables seamless integration of filtered and customized TablePress table content into WordPress pages and posts.
 * Version:           1.0.0
 * Requires at least: 6.8.0
 * Requires PHP:      8.0
 * Author:            Anton Bil
 * Author URI:        https://your-website.com/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tablepress-query
 * Domain Path:       /languages
 * Requires Plugins:  tablePress
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die( 'Silence is golden.' ); // A common phrase used here
}

// Define plugin constants for better manageability
define( 'TABLEPRESS_QUERY_VERSION', '1.0.0' );
define( 'TABLEPRESS_QUERY_PLUGIN_DIR_PATH', plugin_dir_path( __FILE__ ) );
define( 'TABLEPRESS_QUERY_PLUGIN_DIR_URL', plugin_dir_url( __FILE__ ) );

/**
 * Include the main query class file.
 *
 * Using require_once ensures the file is included only once and will produce a fatal error
 * if the file is not found, which is appropriate for a critical class.
 */
require_once TABLEPRESS_QUERY_PLUGIN_DIR_PATH . 'includes/table-press-query-class.php';

function tpq_load_textdomain() {
    load_plugin_textdomain( 'tablepress-query', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
}
add_action( 'plugins_loaded', 'tpq_load_textdomain' );

function tpq_enqueue_scripts(){
      // Static flag to ensure CSS is enqueued only once per page request,
    // even if the shortcode is used multiple times on the same page.
    static $style_enqueued = false;
    if ( ! $style_enqueued ) {
        wp_enqueue_style(
            'tablepress-query-style',                                       // Unique handle for the stylesheet.
            TABLEPRESS_QUERY_PLUGIN_DIR_URL . 'css/table-press-query.css',   // Path to the CSS file.
            array(),                                                        // Dependencies (e.g., if it depends on TablePress styles).
            TABLEPRESS_QUERY_VERSION                                        // Version number for cache busting.
        );
        $style_enqueued = true;
    }
    // enqueue JS script:
    static $script_enqueued = false;
    if ( ! $script_enqueued ) {
      wp_enqueue_script('table-press-query', TABLEPRESS_QUERY_PLUGIN_DIR_URL . 'js/table-press-query.js', array('jquery'), null, true);
      wp_localize_script('table-press-query', 'tpq_ajax_object', array(
          'ajax_url' => admin_url('admin-ajax.php'),
          // 'nonce' => wp_create_nonce('tpq_contact_form_nonce') // Als je een nonce wilt meesturen voor het ophalen
      ));
      $script_enqueued = true;
    }
}

/**
 * Shortcode handler for [tablepress-query].
 *
 * This function processes the shortcode attributes, enqueues the necessary CSS
 * if the shortcode is used, and then calls the TablePressQuery class
 * to fetch and display the queried table data.
 *
 * @since 1.0.0
 *
 * @param array  $atts    Shortcode attributes.
 * @return string         HTML output of the queried table or an error/help message.
 */
function tablepress_query_shortcode_handler( $atts ) {
    tpq_enqueue_scripts();
    // Define default shortcode attributes and merge with user-provided ones.
    $atts = shortcode_atts(
        array(
            'tablename'    => '',     // Table title (required).
            'columns'      => null,   // Columns to display (comma-separated).
            'filter'       => null,   // Filter conditions (e.g., "column_name=value").
            'select'       => null,   // Specific rows to select based on a column value.
            'column_names' => null,   // Custom column names (comma-separated, matching 'columns').
            'sort'         => null,   // Column to sort by.
            'title'        => null,   // Custom title to display above the queried table.
            'cssclass'     => null,   // Custom CSS class for the table wrapper.
            'format'       => null,   // Output format (e.g., 'html', 'json' - if you implement this).
        ),
        $atts,
        'tablepress-query' // The shortcode tag.
    );

    // Check if the required table name attribute is provided.
    if ( empty( $atts['tablename'] ) ) {
        // If 'tablename' is missing, attempt to display a help message.
        if ( class_exists( 'TablePressQuery' ) ) {
            $survey = new TablePressQuery();
            // It's good practice for methods like getHelp() to return escaped HTML.
            return $survey->getHelp();
        } else {
            // This error is more for the site admin/developer.
            return '<div>Error: The TablePressQuery class was not found after including its file. Plugin configuration issue.</div>';
        }

    }

    // Sanitize user-provided attributes.
    $tablename    = sanitize_text_field( $atts['tablename'] );
    $css_class    = ! empty( $atts['cssclass'] ) ? sanitize_text_field( $atts['cssclass'] ) : null;
    $sort         = ! empty( $atts['sort'] ) ? sanitize_text_field( $atts['sort'] ) : null;
    $title_query  = ! empty( $atts['title'] ) ? sanitize_text_field( $atts['title'] ) : null;
    $format_query = ! empty( $atts['format'] ) ? sanitize_text_field( $atts['format'] ) : null;
    // Note: 'columns', 'filter', 'select', 'column_names' might need more specific sanitization
    // depending on their expected format and how they are used in SQL queries or other processing.
    // For example, if they are comma-separated lists, you might explode them and sanitize each part.

    if ( ! class_exists( 'TablePressQuery' ) ) {
        error_log( 'TablePress Query Plugin: TablePressQuery class not found even after include.' );
        return '<div>Error: The TablePressQuery class is not available. Plugin may be corrupted or misconfigured.</div>';
    }

    try {
        // Instantiate the survey class with sanitized and processed attributes.
        $survey = new TablePressQuery(
            $tablename,
            $atts['columns'],      // Consider processing/sanitizing these further if needed by the class.
            $atts['filter'],
            $atts['column_names'],
            $title_query,
            $sort,
            $css_class,
            $format_query,
            $atts['select']
        );

        // Check for errors reported by the TablePressQuery class.
        if ( $survey->error !== null ) {
            // Ensure any output from $atts['cssclass'] or $survey->error is properly escaped.
            return "<div class='" . esc_attr( $css_class ) . " tablepress-query-error'>" . esc_html( $survey->error ) . "</div>";
        }

        // Get the table HTML (or other format).
        // The getTable() method should be responsible for any necessary escaping of its own content.
        $table_output = $survey->getTable();
        return $table_output;

    } catch ( Exception $e ) {
        // Log the detailed error for site administrators/developers.
        error_log(
            sprintf(
                "TablePress Query Plugin Exception: %s\nInput Attributes: %s\nStack Trace:\n%s",
                $e->getMessage(),
                wp_json_encode( $atts ), // Log input attributes for easier debugging
                $e->getTraceAsString()
            )
        );
        // Provide a generic, user-friendly error message on the front-end.
        // Avoid exposing detailed error messages or stack traces to the public.
        $error_message_div_class = 'tablepress-query-error';
        if ( ! empty( $css_class ) ) {
            $error_message_div_class .= ' ' . esc_attr( $css_class );
        }
        // You might want to translate this string if your plugin supports multiple languages.
        return "<div class='" . $error_message_div_class . "'>" . esc_html__( 'An unexpected error occurred while processing the TablePress Query. Please try again later or contact support if the issue persists.', 'tablepress-query' ) . "</div>";
    }
    // Note: Code here (outside the try-catch) would only be reached if an exception
    // isn't caught or if there's a logic path that bypasses the try-catch return statements.
    // In this structure, it's unlikely to be reached if the shortcode functions correctly.
}

/**
 * Registers the [tablepress-query] shortcode with WordPress.
 *
 * @since 1.0.0
 */
function tablepress_query_register_shortcode() {
    add_shortcode( 'tablepress-query', 'tablepress_query_shortcode_handler' );
}
add_action( 'init', 'tablepress_query_register_shortcode' );

/**
 * Checks for plugin dependencies (i.e., if TablePress is active).
 *
 * This function is hooked to 'plugins_loaded'. If TablePress is not active,
 * it hooks another function to 'admin_notices' to display an error message.
 * The 'Requires Plugins' header is the preferred method for WP 6.5+.
 * This is a fallback or for added robustness.
 *
 * @since 1.0.0
 */
function tablepress_query_check_dependencies() {
    if ( ! class_exists( 'TablePress' ) ) {
        // Check if the function for adding notices exists, to avoid errors on non-admin pages if hooked too early.
        if ( is_admin() ) {
            add_action( 'admin_notices', 'tablepress_query_dependency_notice' );
        }
    }
}
add_action( 'plugins_loaded', 'tablepress_query_check_dependencies' );

/**
 * Displays an admin notice if TablePress is not active.
 *
 * @since 1.0.0
 */
function tablepress_query_dependency_notice() {
    ?>
    <div class="notice notice-error is-dismissible">
        <p>
            <?php
            printf(
                /* translators: 1: Strong tag opening, 2: Strong tag closing, 3: Plugin name TablePress */
                esc_html__( '%1$sTablePress Query%2$s plugin requires the %3$sTablePress%2$s plugin to be installed and activated. Please activate TablePress to use TablePress Query.', 'tablepress-query' ),
                '<strong>',
                '</strong>',
                '<em>'
            );
            ?>
        </p>
    </div>
    <?php
}

/**
 * Reads the contents of a file_directory and returns a list of PDF filenames.
 *
 * @param string $pdf_dir The file_directory to scan.
 *
 * @return array A list of PDF filenames.
 */
function tpq_get_pdf_list(string $pdf_dir): array {
    $pdf_files = [];

    // Check if the file_directory exists and is readable
    if (is_dir($pdf_dir) && is_readable($pdf_dir)) {
        $files = scandir($pdf_dir);

        if ($files !== false) {
            foreach ($files as $file) {
                // Skip . and ..
                if ($file != "." && $file != "..") {
                    //check if pdf
                    if (pathinfo($file, PATHINFO_EXTENSION) == 'pdf') {
                        $pdf_files[] = $file;
                    }
                }
            }
        }
    } else {
        error_log("Directory {$pdf_dir} does not exist or is not readable.");
    }
    return $pdf_files;
}

//start contact-form
// Action hook for generating/displaying the contact form via AJAX
add_action('wp_ajax_nopriv_tpq_get_contact_form', 'tpq_get_contact_form_callback'); // For non-logged-in users.
add_action('wp_ajax_tpq_get_contact_form', 'tpq_get_contact_form_callback');    // For logged-in users.

/**
 * Shortcode handler to display contact information based on generic parameters.
 *
 * This shortcode allows specifying the table ID, filter expressions,
 * an expression to construct the display name, and the name of the email column.
 *
 * Example usage:
 * [tablepress-generic-contact table_id="1" filter_expression="{Department:Sales},{Status:Active}" name_expression="{FirstName}+' '+{LastName}" email_column="WorkEmail" columns_to_print="{Naam:{FirstName}+' '+{LastName},Email:{WorkEmail},Telefoon:{MobilePhone}}"]
 *
 * Attributes:
 * - table_id (string, required): The ID of the TablePress table.
 * - filter_expression (string, optional): The filter conditions in the format "{{ColumnName1:Value1},{ColumnName2:Value2}}".
 *                                         Can also be a more complex custom filter string if your class supports it.
 * - name_expression (string, required): An expression to construct the display name, e.g., "{FirstName}+' '+{LastName}".
 *                                       This will be used for the 'Naam' part of columns_to_print.
 * - email_column (string, required): The exact name of the column in the TablePress table that contains the email address.
 * - columns_to_print (string, optional): Advanced: Directly specify the columns to print string.
 *                                        If provided, it overrides individual name_expression, email_column, phone_column.
 *                                        Format: "{DisplayName1:Expression1,DisplayName2:ColumnName2,...}"
 *
 * @param array $atts Shortcode attributes.
 * @return string The formatted contact information or an error message.
 */
function tablepress_generic_contact_shortcode_handler($atts) {
    // Enqueue scripts and styles needed for the output (e.g., for the mailto links)
    tpq_enqueue_scripts();

    // Ensure the main class for data retrieval is available.
    if (!class_exists('TablePressQuery')) {
        error_log('TablePress Generic Contact Shortcode: TablePressQuery class not found.');
        return '<div>Error: Core class for data retrieval is not available. Please check plugin configuration.</div>';
    }

    // --- Define and sanitize attributes ---
    $atts = shortcode_atts(
        array(
            'table_id' => '',          // Required: ID of the TablePress table
            'filter_expression' => '', // Optional: e.g., "{Department:Sales},{City:New York}"
            'name_expression' => '',   // Required: e.g., "{FirstName}+' '+{LastName}" or just "{FullName}"
            'email_column' => '',     // Required: e.g., "EmailAddress" or "ContactEmail"
            'columns_to_print' => '', // Advanced: Overrides individual column settings if provided
        ),
        $atts,
        'tablepress-generic-contact' // The shortcode tag
    );

    // Basic validation for required attributes
    if (empty($atts['table_id'])) {
        return '<div>Error: The "table_id" attribute is required for the [tablepress-generic-contact] shortcode.</div>';
    }
    // We need either columns_to_print OR (name_expression AND email_column)
    if (empty($atts['columns_to_print']) && (empty($atts['name_expression']) || empty($atts['email_column']))) {
        return '<div>Error: Please provide either the "columns_to_print" attribute, or both "name_expression" and "email_column" attributes.</div>';
    }

    // Sanitize all attributes (simple sanitization here, might need more specific based on your class's needs)
    $table_id = sanitize_text_field($atts['table_id']);
    $filter_expression = sanitize_text_field($atts['filter_expression']); // Consider more robust sanitization if complex expressions are allowed.
    $columns_to_print_override = trim($atts['columns_to_print']);


    // --- Construct parameters for your TablePressQuery class ---
    $final_columns_to_print = "";
    $column_names_for_survey = ""; // This will be like "{Naam,Email,Telefoon}"

    if (!empty($columns_to_print_override)) {
        // User provided the full columns_to_print string
        $final_columns_to_print = $columns_to_print_override; // Assume it's already correctly formatted by the user

        // Attempt to derive column_names_for_survey from the override
        // This is a basic attempt; complex expressions might make this tricky.
        // It extracts the part before the first ':' in each segment.
        preg_match_all('/\{([^:]+):/', $final_columns_to_print, $matches);
        if (!empty($matches[1])) {
            $column_names_for_survey = "{" . implode(',', $matches[1]) . "}";
        } else {
            // Fallback if parsing fails
            $temp_cols = str_replace(['{', '}'], '', $final_columns_to_print);
            $parts = explode(',', $temp_cols);
            $display_names = [];
            foreach ($parts as $part) {
                $sub_parts = explode(':', $part, 2);
                $display_names[] = trim($sub_parts[0]);
            }
            if (!empty($display_names)) {
                 $column_names_for_survey = "{" . implode(',', $display_names) . "}";
            } else {
                 $column_names_for_survey = "{Naam,Email}"; // Default if complex parsing fails
            }
        }

    } else {
        // Construct columns_to_print from individual attributes
        $name_expression = trim($atts['name_expression']);
        $email_column = trim($atts['email_column']);

        $cols_array = array();
        $display_names_array = array();

        if (!empty($name_expression)) {
            $cols_array[] = "Naam:" . $name_expression; // 'Naam' is the display name, expression is its source
            $display_names_array[] = "Naam";
        }
        if (!empty($email_column)) {
            $cols_array[] = "Email:" . $email_column;   // 'Email' is the display name, email_column is its source
            $display_names_array[] = "Email";
        }

        $final_columns_to_print = "{" . implode(',', $cols_array) . "}";
        $column_names_for_survey = "{" . implode(',', $display_names_array) . "}";
    }

    // --- Instantiate and use TablePressQuery class ---
    try {
      $table_press_query = new TablePressQuery(
            $table_id,                     // Table ID (or name)
            $final_columns_to_print,       // e.g., "{Naam:{FirstName}+' '+{LastName},Email:WorkEmail}"
            $filter_expression,            // e.g., "{{Department:Sales},{Status:Active}}"
            $column_names_for_survey,       // e.g., "{Naam,Email,Telefoon}" - these are the *display* names
            '',
            '',
            '',
            '',
            ''
        );

        // --- Get and return the output ---
        $output = $table_press_query->get_contact_info_generic($filter_expression); // This method should produce the final HTML

        if (empty($output)) {
            // Provide a message if no results are found, but not an error.
            return '<div>' . esc_html__('No contact information found matching your criteria.', 'your-text-domain') . '</div>';
        }
        return $output;

    } catch (Exception $e) {
        // Log the error for administrators
        error_log('TablePress Generic Contact Shortcode Error: ' . $e->getMessage());
        // Return a user-friendly error message
        return '<div>Error: Could not retrieve contact information. ' . esc_html($e->getMessage()) . '</div>';
    }
}

/**
 * Registers the new generic contact query shortcode.
 */
function register_tablepress_generic_contact_query_shortcode() {
    add_shortcode('tablepress-generic-contact', 'tablepress_generic_contact_shortcode_handler');
}
add_action('init', 'register_tablepress_generic_contact_query_shortcode');

/**
 * AJAX callback to generate and return the HTML for the contact form.
 *
 * This function is triggered by an AJAX request (typically GET or POST)
 * when a user clicks a button to contact a specific person.
 * It dynamically creates the form HTML, pre-fills recipient information,
 * and includes necessary security nonces.
 *
 * @since 1.0.0 (Replace with your plugin's version)
 */
function tpq_get_contact_form_callback() {

    // Security check: Verify nonce if you decide to send one from JS for this specific 'get_form' action.
    // This is optional for form *retrieval* but highly recommended for any action that *changes* data or state.
    // If you add a nonce in JS for this call (e.g., 'get_form_nonce' from wp_localize_script),
    // you would check it here:
    // check_ajax_referer( 'tpq_get_form_action_nonce', 'security' ); // 'security' would be the key of the nonce in $_POST.

    // Sanitize input POST data (or _GET if you change the JS method for this AJAX call).
    // Use wp_unslash() as a good practice before sanitization if data might have slashes added.
    $recipient_name = isset($_POST['recipient_name'])
        ? sanitize_text_field(wp_unslash($_POST['recipient_name']))
        : __('Contact Person', 'tablepress-query'); // Default name, translatable

    $recipient_email_for_form = isset($_POST['recipient_email'])
        ? sanitize_email(wp_unslash($_POST['recipient_email']))
        : '';

    // Start generating the form HTML.
    // The form ID 'kcm-dynamic-contact-form' is used by JavaScript to handle submission.
    $form_html = '<form id="kcm-dynamic-contact-form" class="kcm-contact-form" method="post">'; // Added method for clarity, though AJAX handles it.
    $form_html .= '<h4>' . sprintf(esc_html__('Contact %s', 'tablepress-query'), esc_html($recipient_name)) . '</h4>'; // Translatable title

    // Nonce field for the actual email submission.
    // This is crucial for securing the tpq_send_contact_email_callback action.
    // The first parameter is the nonce action, the second is the name of the nonce field in the form.
    $form_html .= wp_nonce_field('tpq_send_email_nonce_action', '_tpq_send_email_nonce_field', true, false);

    // Hidden fields for recipient information and the AJAX action for form submission.
    // The 'action' field tells WordPress which AJAX hook to trigger when this form is submitted.
    $form_html .= '<input type="hidden" name="action" value="tpq_send_contact_email">';
    // These hidden fields carry over the recipient details to the email sending function.
    $form_html .= '<input type="hidden" name="tpq_recipient_email" value="' . esc_attr($recipient_email_for_form) . '">';
    $form_html .= '<input type="hidden" name="tpq_recipient_name" value="' . esc_attr($recipient_name) . '">';

    // Visible fields for the sender.
    // Labels are associated with inputs using 'for' and 'id' attributes for accessibility.
    // 'required' attribute provides basic client-side validation.
    $form_html .= '<p><label for="tpq_sender_name-' . esc_attr($recipient_email_for_form) . '">' . esc_html__('Your Name:', 'tablepress-query') . '</label><br>'; // Unique ID for label
    $form_html .= '<input type="text" id="tpq_sender_name-' . esc_attr($recipient_email_for_form) . '" name="tpq_sender_name" class="kcm-form-input" required></p>';

    $form_html .= '<p><label for="tpq_sender_email-' . esc_attr($recipient_email_for_form) . '">' . esc_html__('Your Email:', 'tablepress-query') . '</label><br>';
    $form_html .= '<input type="email" id="tpq_sender_email-' . esc_attr($recipient_email_for_form) . '" name="tpq_sender_email" class="kcm-form-input" required></p>';

    $form_html .= '<p><label for="tpq_email_subject-' . esc_attr($recipient_email_for_form) . '">' . esc_html__('Subject:', 'tablepress-query') . '</label><br>';
    $form_html .= '<input type="text" id="tpq_email_subject-' . esc_attr($recipient_email_for_form) . '" name="tpq_email_subject" class="kcm-form-input" required></p>';

    $form_html .= '<p><label for="tpq_email_message-' . esc_attr($recipient_email_for_form) . '">' . esc_html__('Message:', 'tablepress-query') . '</label><br>';
    $form_html .= '<textarea id="tpq_email_message-' . esc_attr($recipient_email_for_form) . '" name="tpq_email_message" rows="4" class="kcm-form-input" required></textarea></p>';

    // Optional: Placeholder for CAPTCHA or other anti-spam measures.
    // $form_html .= '<div class="kcm-captcha-placeholder"></div>';

    $form_html .= '<p><input type="submit" value="' . esc_attr__('Send Message', 'tablepress-query') . '" class="kcm-form-submit-button"></p>';

    // Optional: A div for feedback messages related to this form's submission.
    // The JavaScript will look for a div with class 'kcm-form-feedback' inside the form's wrapper.
    // If you want feedback *inside* the <form> tag, add it here.
    // $form_html .= '<div class="kcm-form-feedback-inline" style="display:none; margin-top:10px;"></div>';

    $form_html .= '</form>'; // Close the <form> tag.

    // Send the generated HTML back to the client as part of a JSON success response.
    // The JavaScript expects an object like { success: true, data: { html: "..." } }.
    wp_send_json_success( array( 'html' => $form_html ) );
    // wp_send_json_success() includes wp_die(), so no further output is possible or needed.
}
// Action hook for processing the contact form submission via AJAX.
add_action('wp_ajax_nopriv_tpq_send_contact_email', 'tpq_send_contact_email_callback'); // For non-logged-in users.
add_action('wp_ajax_tpq_send_contact_email', 'tpq_send_contact_email_callback');    // For logged-in users.

/**
 * AJAX callback to handle the contact form submission.
 *
 * This function is triggered when the dynamically generated contact form is submitted.
 * It performs security checks (nonce verification), validates and sanitizes input,
 * constructs the email, sends it using wp_mail(), and returns a JSON response
 * indicating success or failure.
 *
 * @since 1.0.0 // Replace with your plugin's version
 */
function tpq_send_contact_email_callback() {
    // 1. Security: Verify the nonce sent with the form submission.
    // The nonce name ('_tpq_email_nonce') must match the name attribute of the nonce field in the form.
    // The nonce action ('tpq_send_email_nonce') must match the action used when creating the nonce field.
    if ( ! isset( $_POST['_tpq_send_email_nonce_field'] ) ||
         ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_tpq_send_email_nonce_field'] ) ), 'tpq_send_email_nonce_action' ) ) {
        wp_send_json_error( array( 'message' => __( 'Security error: Invalid nonce. Please refresh the page and try submitting the form again.', 'tablepress-query' ) ) );
    }

    // 2. Validate and sanitize all submitted input data.
    // Consider using wp_unslash() on $_POST data if there's any chance of magic quotes being active (though less common now).
    $recipient_email = isset($_POST['tpq_recipient_email']) ? sanitize_email(wp_unslash($_POST['tpq_recipient_email'])) : '';
    $recipient_name  = isset($_POST['tpq_recipient_name']) ? sanitize_text_field(wp_unslash($_POST['tpq_recipient_name'])) : __('Contact Person', 'tablepress-query'); // Name of the person being emailed.

    $sender_name    = isset($_POST['tpq_sender_name']) ? sanitize_text_field(wp_unslash($_POST['tpq_sender_name'])) : '';
    $sender_email   = isset($_POST['tpq_sender_email']) ? sanitize_email(wp_unslash($_POST['tpq_sender_email'])) : '';
    $subject        = isset($_POST['tpq_email_subject']) ? sanitize_text_field(wp_unslash($_POST['tpq_email_subject'])) : __('Message via website', 'tablepress-query'); // Default subject
    $message_body   = isset($_POST['tpq_email_message']) ? sanitize_textarea_field(wp_unslash($_POST['tpq_email_message'])) : ''; // Use wp_kses_post() if you need to allow some HTML.

    // Basic validation checks.
    if (empty($recipient_email) || !is_email($recipient_email)) {
        wp_send_json_error(array('message' => __('Error: Invalid recipient email address:'.$recipient_email, 'tablepress-query')));
    }
    if (empty($sender_name) || empty($sender_email) || !is_email($sender_email) || empty($subject) || empty($message_body)) {
        wp_send_json_error(array('message' => __('Error: All fields are required and your email address must be valid.', 'tablepress-query')));
    }

    // Optional: Validate CAPTCHA here if implemented.
    // E.g., if ( ! tpq_verify_captcha($_POST['captcha_response']) ) { wp_send_json_error(...); }

    // 3. Compose the email.
    $to = $recipient_email; // The email address of the contact person (from the TablePress table via hidden form field).

    // Construct a clear email subject.
    $email_subject = sprintf(
        '[%s] %s: %s',
        get_bloginfo('name'), // Website name
        __('Contact via website', 'tablepress-query'), // Type of contact
        $subject // User-provided subject
    );

    // Set email headers.
    $headers = array();
    $headers[] = 'Content-Type: text/html; charset=UTF-8'; // Use UTF-8 for broad character support.
    $headers[] = 'From: ' . $sender_name . ' <' . $sender_email . '>'; // Set 'From' to the sender. Or use a no-reply@yourdomain.com address.
    $headers[] = 'Reply-To: ' . $sender_name . ' <' . $sender_email . '>'; // Ensure replies go to the actual sender.

    // Construct the HTML email body.
    $email_content = "<html><body>";
    $email_content .= "<h2>" . sprintf(esc_html__('Message received via the contact form on %s', 'tablepress-query'), get_bloginfo('name')) . "</h2>";
    $email_content .= "<p>" . sprintf(esc_html__('This message is for: %s', 'tablepress-query'), '<strong>' . esc_html($recipient_name) . '</strong>') . "</p><hr>";
    $email_content .= "<p><strong>" . esc_html__('Sender:', 'tablepress-query') . "</strong> " . esc_html($sender_name) . "</p>";
    $email_content .= "<p><strong>" . esc_html__('Sender Email:', 'tablepress-query') . "</strong> " . esc_html($sender_email) . "</p>";
    $email_content .= "<p><strong>" . esc_html__('Subject:', 'tablepress-query') . "</strong> " . esc_html($subject) . "</p>";
    $email_content .= "<p><strong>" . esc_html__('Message:', 'tablepress-query') . "</strong></p>";
    $email_content .= "<div style='padding:10px; border:1px solid #eee; background-color:#f9f9f9;'>";
    $email_content .= nl2br(esc_html($message_body)); // Convert newlines to <br> for HTML display, after escaping.
    $email_content .= "</div>";
    $email_content .= "<hr><p><small>" . sprintf(esc_html__('This email was automatically generated from the website %s.', 'tablepress-query'), get_bloginfo('url')) . "</small></p>";
    $email_content .= "</body></html>";

    // 4. Send the email using wp_mail().
    if (wp_mail($to, $email_subject, $email_content, $headers)) {
        wp_send_json_success(array('message' => __('Your message has been sent successfully!', 'tablepress-query')));
    } else {
        // Log the error for the site admin for debugging purposes.
        error_log(
            sprintf(
                "TablePress Query Contact Form: Email sending failed. To: %s, Subject: %s, Sender: %s <%s>",
                $to,
                $email_subject,
                $sender_name,
                $sender_email
            )
        );
        // Provide a generic error message to the user.
        wp_send_json_error(array('message' => __('An error occurred while sending your message. Please try again later.', 'tablepress-query')));
    }
    // wp_die(); // is necessary if not using wp_send_json_* to terminate the AJAX handler correctly.
}
// End of contact form processing logic.


?>

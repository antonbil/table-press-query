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

function kcm_load_textdomain() {
    load_plugin_textdomain( 'tablepress-query', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
}
add_action( 'plugins_loaded', 'kcm_load_textdomain' );

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
      wp_localize_script('table-press-query', 'kcm_ajax_object', array(
          'ajax_url' => admin_url('admin-ajax.php'),
          // 'nonce' => wp_create_nonce('kcm_contact_form_nonce') // Als je een nonce wilt meesturen voor het ophalen
      ));
      $script_enqueued = true;
    }
}

/**
 * Shortcode handler for [tablepress-query].
 *
 * This function processes the shortcode attributes, enqueues the necessary CSS
 * if the shortcode is used, and then calls the TablePressSurvey class
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
        if ( class_exists( 'TablePressSurvey' ) ) {
            $survey = new TablePressSurvey();
            // It's good practice for methods like getHelp() to return escaped HTML.
            return $survey->getHelp();
        } else {
            // This error is more for the site admin/developer.
            return '<div>Error: The TablePressSurvey class was not found after including its file. Plugin configuration issue.</div>';
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

    if ( ! class_exists( 'TablePressSurvey' ) ) {
        error_log( 'TablePress Query Plugin: TablePressSurvey class not found even after include.' );
        return '<div>Error: The TablePressSurvey class is not available. Plugin may be corrupted or misconfigured.</div>';
    }

    try {
        // Instantiate the survey class with sanitized and processed attributes.
        $survey = new TablePressSurvey(
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

        // Check for errors reported by the TablePressSurvey class.
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


//taskgroup functions and calls
function tablepress_taskgroup_email_shortcode($atts) {
    tpq_enqueue_scripts();
    // Default attributes
    $atts = shortcode_atts(
        array(
            'group' => '', // Default to empty group
        ),
        $atts,
        'tablepress-taskgroup'
    );

    // Get the group name from the shortcode attribute
    $group_name = sanitize_text_field($atts['group']);

    // Check if a group name was provided
    if (empty($group_name)) {
        return "Error: No task group specified.";
    }
	$path = dirname(__FILE__)."/tablePressList.php";
    // Ensure that the php-file is included
    //require_once $path;
        if ( ! class_exists( 'TablePressSurvey' ) ) {
        error_log( 'TablePress Query Plugin: TablePressSurvey class not found even after include.' );
        return '<div>Error: The TablePressSurvey class is not available. Plugin may be corrupted or misconfigured.</div>';
    }

    // Shortcode output
    return get_task_group_email_links($group_name);
}

function register_tablepress_taskgroup_email() {
    // Register the shortcode
	add_shortcode('tablepress-taskgroup-email', 'tablepress_taskgroup_email_shortcode');
}
/**
 * Retrieves data from a TablePress table by its ID and returns a list
 * with specified columns ("id", "Datum", "Predikant").
 *
 * @param int $table_id The ID of the TablePress table.
 *
 * @return string The HTML string representing the table data, or an error message.
 */
function print_tablepress_list(int $table_id) {
    // Check if the table ID is valid
    if (!is_int($table_id) || $table_id <= 0) {
        error_log("Invalid TablePress table ID: " . $table_id);
        return "Error: Invalid TablePress table ID.";
    }

    // Get the table post
    $table_post = get_post($table_id);

    // Check if the table post exists and is of the correct type
    if (!$table_post || $table_post->post_type !== 'tablepress_table') {
        error_log("TablePress table with ID {$table_id} not found.");
        return "Error: TablePress table with ID {$table_id} not found.";
    }

    // Get the table data from post content.
    $table_data_json = $table_post->post_content;

    // Check if data is present
    if (!$table_data_json) {
        error_log("Missing table data for TablePress table ID {$table_id}.");
        return "Error: Missing table dat1 for TablePress table ID {$table_id}.";
    }
    //decode the json-string to an array
    $table_data = json_decode($table_data_json);

    //check if json-decode was succesfull
    if (!$table_data) {
        error_log("Error json-decoding table data for TablePress table ID {$table_id}.");
        return "Error: Error json-decoding table data for TablePress table ID {$table_id}.";
    }

    //check if it is an array
    if (!is_array($table_data)) {
        error_log("Table data for TablePress table ID {$table_id} not an array.");
        return "Error: Table data for TablePress table ID {$table_id} not an array.";
    }

    // Define the columns you want to print
    $columns_to_print = ["id", "Datum", "Predikant"];

    // Find the indexes of the columns you want
    $column_indexes = [];
    $header_row = $table_data[0];

    foreach ($columns_to_print as $column_name) {
        $index = array_search($column_name, $header_row);
        if ($index !== false) {
            $column_indexes[$column_name] = $index;
        } else {
            error_log("Column '{$column_name}' not found in TablePress table ID {$table_id}.");
            return "Warning: Column '{$column_name}' not found in TablePress table ID {$table_id}.<br/>";
        }
    }
    //check if the correct columsn are found
    if (count($column_indexes) != count($columns_to_print)) {
        error_log("Not all the requested columns found in TablePress table ID {$table_id}.");
        return "Warning: Not all the requested columns found in TablePress table ID {$table_id}.<br/>";
    }
    //get the index of the id-column
    $id_index=$column_indexes["id"];

    // Get the current date
    $current_date = new DateTime();

    //set the found date to null
    $found_date_index = null;
    $found_date = null;

    //collect the rows that are in the past, present or future.
    $past_rows=array();
    $future_rows=array();
    $current_id_date = "";
    // Loop through rows (skip the header row)
    foreach (array_slice($table_data, 1) as $index => $row) {
        //get the date from the id-column
        $id_value=$row[$id_index];
        $year = "20".substr($id_value, 0, 2); // Assuming the year is always in the 2000s
        $month = substr($id_value, 2, 2);
        $day = substr($id_value, 4, 2);
        //check if we got a valid date.
        if(checkdate($month, $day, $year)) {
            // Create a DateTime object from the extracted date
            $row_date = new DateTime("$year-$month-$day");
            if ($row_date == $current_date) {
                $current_id_date = $row[$column_indexes["Datum"]];
            } else {
                 $next_sunday = get_next_sunday($current_date);
                // //check if the row_date is before the next sunday
                if ($row_date <= $next_sunday) {

                    $current_id_date = $row[$column_indexes["Datum"]];
                }
            }

            // Compare the row date to the current date
            if ($row_date <= $current_date) {
                $past_rows[]=$row;
                //check if the date is the nearest date.
                if (($found_date_index == null) || ($row_date > $found_date)) {
                   $found_date_index=$index;
                    $found_date = $row_date;

                }

            } else {
                $future_rows[]=$row;
            }
        } else {
            //we have an invalid date. Add the row to the past.
            $past_rows[]=$row;
        }

    }

    //check if we have a date found
    if ($found_date_index == null) {
        //no valid date found. Set the found_date_index to zero.
        $found_date_index = 0;
    }

    // Slice the array to get the desired range
    $start_index = max(0, $found_date_index - 8); // Ensure start_index is not negative
    $end_index = min(count($past_rows)-1, $found_date_index + 4); // Ensure end_index is within bounds

    //merge the array.
    $rows_to_print=array_merge(array_slice($past_rows, $start_index,$end_index-$start_index+1), array_slice($future_rows,0, 4));
    $rows_to_print=update_rows_from_prekenrooster($rows_to_print, $columns_to_print, $current_date,$column_indexes,$header_row);

    $output = print_table_output($columns_to_print, $rows_to_print, $current_date,$column_indexes, $current_id_date);

     return $output;
}

/**
 * Get the date of the next Sunday after a given date.
 *
 * @param DateTime $date The date to start from.
 *
 * @return DateTime The date of the next Sunday.
 */
function get_next_sunday(DateTime $date): DateTime {
    $next_sunday = clone $date;
    $days_until_sunday = (7 - $next_sunday->format('w')) % 7; // Calculate days until the next Sunday
    $next_sunday->modify("+$days_until_sunday days");

    return $next_sunday;
}

/**
 * Updates the rows_to_print array with missing rows from the prekenrooster TablePress table.
 * If there are rows in $rows_to_print that are not in the prekenrooster table, the prekenrooster
 * table is updated with the content of $rows_to_print (including the header row).
 *
 * @param array $rows_to_print
 * @param array $columns_to_print
 * @param DateTime $current_date
 * @param array $column_indexes
 * @param array $header_row
 * @return array
 */
function update_rows_from_prekenrooster($rows_to_print, $columns_to_print, $current_date, $column_indexes, $header_row) {
    return $rows_to_print;
    // Get the ID of the "prekenrooster" table
    $table_id = get_tablepress_id_by_title("prekenrooster");

    // Check if the table ID is valid
    if (!is_int($table_id) || $table_id <= 0) {
        error_log("Invalid TablePress table ID: " . $table_id);
        return $rows_to_print;
    }

    // Get the table post
    $table_post = get_post($table_id);

    // Check if the table post exists and is of the correct type
    if (!$table_post || $table_post->post_type !== 'tablepress_table') {
        error_log("TablePress table with ID {$table_id} not found.");
        return $rows_to_print;
    }

    // Get the table data from the post content.
    $table_data_json = $table_post->post_content;

    // Check if data is present
    if (!$table_data_json) {
        error_log("Missing table data for TablePress table ID {$table_id}.");
        return $rows_to_print;
    }

    // Decode the json-string to an array
    $table_data = json_decode($table_data_json);

    // Check if json_decode was successful
    if (!$table_data) {
        error_log("Error json-decoding table data for TablePress table ID {$table_id}.");
        return $rows_to_print;
    }

    // Check if it is an array
    if (!is_array($table_data)) {
        error_log("Table data for TablePress table ID {$table_id} not an array.");
        return $rows_to_print;
    }

    // Get the index of the id-column
    $id_index = $column_indexes["id"];

    // Get the rows without header-row.
    $prekenrooster_rows = array_slice($table_data, 1);

    // Check if $rows_to_print has any rows that are not in $prekenrooster_rows
    $has_discrepancies = false;
    foreach ($rows_to_print as $row) {
        $found = false;
        foreach ($prekenrooster_rows as $prekenrooster_row) {
            if ($row == $prekenrooster_row) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $has_discrepancies = true;
            break;
        }
    }

    // If there are discrepancies, update the "prekenrooster" table.
    if ($has_discrepancies) {
        // Add header_row
        $new_table_data = array_merge([$header_row], $rows_to_print);
        //update the table data
        update_prekenrooster_table($table_post,$new_table_data);
        //update the prekenrooster_rows
        $prekenrooster_rows=$rows_to_print;

    }
    //collect all rows to be added.
    $rows_to_add = array();
    //set the max-date to null
    $max_row_date = null;

    //loop over the rows to_print.
    foreach ($rows_to_print as $row) {
        //get the id_value from the row
        $id_value = $row[$id_index];
        //get the date from the id-column
        $year = "20" . substr($id_value, 0, 2); // Assuming the year is always in the 2000s
        $month = substr($id_value, 2, 2);
        $day = substr($id_value, 4, 2);
        //check if we got a valid date.
        if (checkdate($month, $day, $year)) {
            // Create a DateTime object from the extracted date
            $row_date = new DateTime("$year-$month-$day");
            if (($max_row_date == null) || ($row_date > $max_row_date)) {
                $max_row_date = $row_date;
            }
        }
    }
    //add the correct rows from prekenrooster
    foreach ($prekenrooster_rows as $prekenrooster_row) {
        //get the id_value from the row
        $id_value = $prekenrooster_row[$id_index];
        //get the date from the id-column
        $year = "20" . substr($id_value, 0, 2); // Assuming the year is always in the 2000s
        $month = substr($id_value, 2, 2);
        $day = substr($id_value, 4, 2);
        //check if we got a valid date.
        if (checkdate($month, $day, $year)) {
            // Create a DateTime object from the extracted date
            $row_date = new DateTime("$year-$month-$day");
            //add the row, when the row_date is < max_row_date
            if (($max_row_date != null) && ($row_date < $max_row_date)) {
                //add the row to the list, when not yet present.
                $is_present = false;
                foreach ($rows_to_print as $row) {
                    if ($row == $prekenrooster_row) {
                        $is_present = true;
                        break;
                    }
                }
                if (!$is_present) {
                    $rows_to_add[] = $prekenrooster_row;
                }
            }
        }
    }
    //add the rows to the rows_to_print.
    $rows_to_print = array_merge($rows_to_add, $rows_to_print);
    //remove extra rows.
    $rows_to_print = array_slice($rows_to_print, 0, 13);
    //$rows_to_print = array_merge(prekenrooster_rows, $rows_to_print);
    return $rows_to_print;
}
/**
 * Updates the "prekenrooster" TablePress table with new data.
 *
 * @param WP_Post $table_post The TablePress table post object.
 * @param array $new_table_data The new data for the table.
 * @return void
 */
function update_prekenrooster_table(WP_Post $table_post, array $new_table_data) {
    // Convert the data to JSON
    $new_table_data_json = json_encode($new_table_data, JSON_UNESCAPED_UNICODE);

    // Prepare the data for updating the post
    $updated_post_data = array(
        'ID'           => $table_post->ID,
        'post_content' => $new_table_data_json,
    );

    // Update the post
    wp_update_post($updated_post_data);
}
/**
 * Swaps the first and last columns of the columns_to_print and rows_to_print arrays, and updates the column_indexes.
 *
 * @param array $columns_to_print
 * @param array $rows_to_print
 * @param array $column_indexes
 *
 * @return array [new_columns_to_print, new_rows_to_print, new_column_indexes]
 */
function swap_columns_and_rows(array $columns_to_print, array $rows_to_print, array $column_indexes): array {
    // Swap the first and last column names
    $columns_to_print = swap_first_and_last($columns_to_print);

    // Swap the first and last elements of each row
    $new_rows_to_print = [];
    foreach ($rows_to_print as $row) {
        $new_rows_to_print[] = swap_first_and_last_row_data($row, $column_indexes);
    }
    $rows_to_print = $new_rows_to_print;

    // Update the column indexes
    $new_column_indexes = [];
    $first_key = array_key_first($column_indexes);
    $last_key = array_key_last($column_indexes);
    foreach ($columns_to_print as $column_name) {
        if ($column_name == $first_key) {
            $new_column_indexes[$column_name] = $column_indexes[$last_key];
        } elseif ($column_name == $last_key) {
            $new_column_indexes[$column_name] = $column_indexes[$first_key];
        } else {
            $new_column_indexes[$column_name] = $column_indexes[$column_name];
        }
    }
    $column_indexes=$new_column_indexes;
    return [$columns_to_print, $rows_to_print, $column_indexes];
}

/**
 * Swaps the first and last data element of a row.
 */
function swap_first_and_last_row_data($row, $column_indexes) {
    //get the first and last element.
    $first_index = $column_indexes[array_key_first($column_indexes)];
    $first_value = $row[$first_index];
    $last_index = $column_indexes[array_key_last($column_indexes)];
    $last_value = $row[$last_index];
    //swap the first and last
    $row[$first_index] = $last_value;
    $row[$last_index] = $first_value;
    return $row;
}

/**
 * Swaps the first and last elements of an array.
 *
 * @param array $array The array to swap elements in.
 *
 * @return array The array with the first and last elements swapped.
 */
function swap_first_and_last($array) {
    if (count($array) < 2) {
        return $array; // Nothing to swap if there are fewer than 2 elements
    }
    $first = array_shift($array);
    $last = array_pop($array);
    array_unshift($array, $last);
    array_push($array, $first);
    return $array;
}

function print_table_output($columns_to_print, $rows_to_print, $current_date,$column_indexes,$current_id_date){
    //swap the columns_to_print and rows_to_print
    list($columns_to_print, $rows_to_print, $column_indexes) = swap_columns_and_rows($columns_to_print, $rows_to_print, $column_indexes);
    //create href
    list($columns_to_print, $rows_to_print) = change_id_to_href($columns_to_print, $rows_to_print, $column_indexes);
    $id_index=$column_indexes["id"];
    $id_date=$column_indexes["Datum"];
    $is_past_or_present = "";
    foreach ($rows_to_print as $row){
            //get the date from the id-column
            $id_value=$row[$id_index];
             $year = "20".substr($id_value, 0, 2); // Assuming the year is always in the 2000s
             $month = substr($id_value, 2, 2);
             $day = substr($id_value, 4, 2);

             //check if we got a valid date.
            //if(checkdate($month, $day, $year)) {
                // Create a DateTime object from the extracted date
                //$row_date = new DateTime("$year-$month-$day");

                // Compare the row date to the current date
                //if ($row_date <= $current_date)
                //$is_past_or_present = $row[id_date];
            //}

    }

    // Build the HTML table
    $output =  "<div class='table-press-query-slider'>";
    $output .= "<table border='1' style='border-collapse: collapse;'>";

    // Add the header row
    $output .= "<thead><tr>";
    foreach ($columns_to_print as $column_name) {
        $output .= "<th>" . htmlspecialchars($column_name) . "</th>";
    }
    $output .= "</tr></thead>";

    // Add the table body
    $output .= "<tbody>";
    foreach ($rows_to_print as $row) {
        // Add the table row
        $output .= get_table_row($row, $columns_to_print, $current_date, $column_indexes, $current_id_date);
    }
    $output .= "</tbody>";
    $output .= "</table>";
    $output .= "</div>";
    return $output;
}

/**
 * Replaces the "id" column with hrefs to matching PDF files or a "." if no match is found.
 *
 * @param array $columns_to_print
 * @param array $rows_to_print
 * @param array $column_indexes
 *
 * @return array [new_columns_to_print, new_rows_to_print]
 */
function change_id_to_href(array $columns_to_print, array $rows_to_print, array $column_indexes): array {
    // Directory to look for PDF files
    $pdf_dir = WP_CONTENT_DIR . '/uploads/2025/01/';

    // Get the list of PDF files
    $pdf_files = get_pdf_list($pdf_dir);

    //get the id_column index
    $id_index = $column_indexes["id"];
    //get the first and last column name
    $first_column_name = array_key_first($column_indexes);
    $last_column_name = array_key_last($column_indexes);
    // Loop through each row
    foreach ($rows_to_print as $row_key=>&$row) {
        //get the id_value
        $id_value=$row[$id_index];
        $href = ".";
        $is_found=false;
        //check if there is a matching pdf file.
        foreach ($pdf_files as $pdf_file) {
            if (strpos($pdf_file, $id_value) !== false) {
                $href = WP_CONTENT_URL . '/uploads/2025/01/' . $pdf_file;
                $is_found=true;
                break;
            }
        }
        // Create the link or replace with a dot
        if ($is_found){
            //create the href.
            $row[$id_index] = '<a href="' . esc_url($href) . '" target="_blank">Orde van Dienst</a>';
        } else {
            //no link available. Add a dot.
            $row[$id_index] = ".";
        }
    }
    $columns_to_print[$id_index] = "";
    return [$columns_to_print, $rows_to_print];
}
/**
 * Reads the contents of a file_directory and returns a list of PDF filenames.
 *
 * @param string $pdf_dir The file_directory to scan.
 *
 * @return array A list of PDF filenames.
 */
function get_pdf_list(string $pdf_dir): array {
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

/**
 * Create a table row for one line.
 */
function get_table_row($row, $columns_to_print, $current_date, $column_indexes, $current_id_date){
    //get the date from the id-column
    $is_past_or_present = strcmp($row[$column_indexes["Datum"]],$current_id_date) == 0;
    //$row[$column_indexes["Datum"]]=$current_id_date;
    // Start a table row with conditional formatting
    $output = "<tr";
    if ($is_past_or_present) {
        $output .= " style='font-style: italic;font-weight: bold;'";
    }
    $output .= ">";

    // Add the table cells
    foreach ($columns_to_print as $column_name) {
        if(isset($column_indexes[$column_name])){
            $index = $column_indexes[$column_name];
            if (isset($row[$index])) {
                $output .= "<td>" . $row[$index] . "</td>";
            } else {
               $output .= "<td></td>";
            }
        }
    }

    $output .= "</tr>"; // End of table row
    return $output;
}

/**
 * Gets the members of a specific task group from the "taakgroep" TablePress table,
 * omitting empty columns and converting email addresses to mailto links.
 *
 * @param string $group_name The name of the task group.
 *
 * @return string The HTML output of the task group members.
 */
function get_task_group($group_name) {
    // Get the TablePress table ID for "taakgroep"
    $table_id = get_tablepress_id_by_title("taakgroep");

    // Check if the table ID is valid
    if (!is_int($table_id) || $table_id <= 0) {
        error_log("Invalid TablePress table ID for 'taakgroep'.");
        return "Error: Invalid TablePress table ID for 'taakgroep'.";
    }

    // Get the table post
    $table_post = get_post($table_id);

    // Check if the table post exists and is of the correct type
    if (!$table_post || $table_post->post_type !== 'tablepress_table') {
        error_log("TablePress table 'taakgroep' not found.");
        return "Error: TablePress table 'taakgroep' not found.";
    }

    // Get the table data from post content.
    $table_data_json = $table_post->post_content;

    // Check if data is present
    if (!$table_data_json) {
        error_log("Missing table data for TablePress table 'taakgroep'.");
        return "Error: Missing table data for TablePress table 'taakgroep'.";
    }

    // Decode the JSON string to an array
    $table_data = json_decode($table_data_json);

    // Check if JSON decode was successful
    if (!$table_data) {
        error_log("Error JSON-decoding table data for TablePress table 'taakgroep'.");
        return "Error: Error JSON-decoding table data for TablePress table 'taakgroep'.";
    }

    // Check if it is an array
    if (!is_array($table_data)) {
        error_log("Table data for TablePress table 'taakgroep' not an array.");
        return "Error: Table data for TablePress table 'taakgroep' not an array.";
    }

    // Get the columns
    $columns = $table_data[0];

    // Get the indexes of the columns
    $column_indexes = get_column_indexes($table_data[0], array("Voornaam", "Achternaam", "Taakgroep", "Functie", "Email", "Telefoon"));

    // Get the index of the taakgroep-column.
    $task_group_index = $column_indexes["Taakgroep"];

    // Collect the rows that match with the specified group_name
    $group_rows = array();
    foreach (array_slice($table_data, 1) as $row) {
        // Check if the task group is present.
        if (strcmp($row[$task_group_index], $group_name) == 0) {
            // Add the row to the array.
            $group_rows[] = $row;
        }
    }

    // Return error if nothing found.
    if (count($group_rows) == 0) {
        return "Geen leden voor taakgroep: " . $group_name . "<br/>";
    }

    // Columns to print (initially all)
    $all_columns_to_print = array("Voornaam", "Achternaam", "Functie", "Email", "Telefoon");

    // Find columns that are completely empty
    $empty_columns = find_empty_columns($group_rows,$column_indexes, $all_columns_to_print);
    //filter the columns to print
    $columns_to_print = array_diff($all_columns_to_print, $empty_columns);

    $rows_to_print = $group_rows;

    // Build the HTML table
    $output =  "<div class='table-press-query-slider'>";
    $output .= "<table border='1' style='border-collapse: collapse;'>";

    // Add the header row
    $output .= "<thead><tr>";
    foreach ($columns_to_print as $column_name) {
        if (strcmp( $column_name, "id")==0)
        $column_name = "";
        $output .= "<th>" . htmlspecialchars($column_name) . "</th>";
    }
    $output .= "</tr></thead>";

    // Add the table body
    $output .= "<tbody>";
    foreach ($rows_to_print as $row) {
        // Add the table row
        $output .= get_table_row_empty_columns($row, $columns_to_print, $column_indexes);
    }
    $output .= "</tbody>";
    $output .= "</table>";
    $output .= "</div>";

    return $output;
}
/**
 * Gets the members of a specific task group from the "taakgroep" TablePress table,
 * omitting empty columns and converting email addresses to email-links.
 *
 * @param string $group_name The name of the task group.
 *
 * @return string The HTML output of the email buttons of the task group members.
 */

function get_task_group_email_links($group_name, $function_name = null) {
    // Get the TablePress table ID for "taakgroep"
    $table_id = get_tablepress_id_by_title("taakgroep");

    // Check if the table ID is valid
    if (!is_int($table_id) || $table_id <= 0) {
        error_log("Invalid TablePress table ID for 'taakgroep'.");
        return "Error: Invalid TablePress table ID for 'taakgroep'.";
    }

    // Get the table post
    $table_post = get_post($table_id);

    // Check if the table post exists and is of the correct type
    if (!$table_post || $table_post->post_type !== 'tablepress_table') {
        error_log("TablePress table 'taakgroep' not found.");
        return "Error: TablePress table 'taakgroep' not found.";
    }

    // Get the table data from post content.
    $table_data_json = $table_post->post_content;

    // Check if data is present
    if (!$table_data_json) {
        error_log("Missing table data for TablePress table 'taakgroep'.");
        return "Error: Missing table data for TablePress table 'taakgroep'.";
    }

    // Decode the JSON string to an array
    $table_data = json_decode($table_data_json);

    // Check if JSON decode was successful
    if (!$table_data) {
        error_log("Error JSON-decoding table data for TablePress table 'taakgroep'.");
        return "Error: Error JSON-decoding table data for TablePress table 'taakgroep'.";
    }

    // Check if it is an array
    if (!is_array($table_data)) {
        error_log("Table data for TablePress table 'taakgroep' not an array.");
        return "Error: Table data for TablePress table 'taakgroep' not an array.";
    }

    // Get the columns
    $columns = $table_data[0];

    // Get the indexes of the columns
    $column_indexes = get_column_indexes($table_data[0], array("Voornaam", "Achternaam", "Taakgroep", "Functie", "Email", "Telefoon"));

    // Get the index of the taakgroep-column.
    $task_group_index = $column_indexes["Taakgroep"];
    $voornaam_index = $column_indexes["Voornaam"];
    $achternaam_index = $column_indexes["Achternaam"];
    $functie_index = $column_indexes["Functie"];
    $email_index = $column_indexes["Email"];

    // Collect the rows that match with the specified group_name
   $group_rows = array();
$added_names = array(); // Array to keep track of added names (Voornaam + Achternaam)

// Loop through table data, skipping the header row
foreach (array_slice($table_data, 1) as $row) {
    // 1. Check if the current row has a task group and if it matches $group_name
    if (!isset($row[$task_group_index]) || strcmp($row[$task_group_index], $group_name) !== 0) {
        continue; // Skip to the next row if task group doesn't match or is not set
    }
    if (isset($row[$email_index]) && strlen($row[$email_index]) == 0){
        continue;
    }

    // 2. If $function_name is provided, check if the current row's function matches
    if ($function_name !== null) {
        // Ensure the function index is set in the row and the function name matches
        if (!isset($row[$functie_index]) || strcmp($row[$functie_index], $function_name) !== 0) {
            continue; // Skip to the next row if function name is required but doesn't match or is not set
        }
    }

    // 3. Get Voornaam and Achternaam
    $voornaam = isset($row[$voornaam_index]) ? trim($row[$voornaam_index]) : '';
    $achternaam = isset($row[$achternaam_index]) ? trim($row[$achternaam_index]) : '';

    // 4. Skip if Voornaam or Achternaam is empty
    if (empty($voornaam) || empty($achternaam)) {
        continue;
    }

    // 5. Create a unique key for the name combination (case-insensitive)
    $name_key = strtolower($voornaam) . '_' . strtolower($achternaam);

    // 6. Check if this name combination has already been added
    if (in_array($name_key, $added_names)) {
        continue; // Skip if already added
    }

    // 7. If all checks pass, create and add the URL
    // Encode parameters for the URL correctly
    $encoded_group_name = rawurlencode($group_name);
    $encoded_voornaam   = rawurlencode($voornaam);
    $encoded_achternaam = rawurlencode($achternaam);
    $title_name         = rawurlencode($voornaam . ' ' . $achternaam);
    $pagina_url         = "https%3A%2F%2Fkoorkerk.nl%2F"; // Assuming this is static and already encoded

    // Construct the URL.
    $encoded_group_name = rawurlencode($group_name);
$encoded_voornaam   = rawurlencode($voornaam);
$encoded_achternaam = rawurlencode($achternaam);
$title_name         = rawurlencode($voornaam . ' ' . $achternaam);
$pagina_url         = "https%3A%2F%2Fkoorkerk.nl%2F"; // Assuming this is static and already encoded

// Construct the HTML for the button/cartouche
$button_text = "mail:" . esc_html($voornaam) . " " . esc_html($achternaam);
$link_href   = "/react-form-contact/?taakgroep={$encoded_group_name}&voornaam={$encoded_voornaam}&achternaam={$encoded_achternaam}&title={$title_name}&pagina={$pagina_url}";
   // Haal het e-mailadres van de contactpersoon op
   $recipient_email = isset($row[$email_index]) ? trim($row[$email_index]) : '';

$recipient_email = esc_attr($recipient_email);
$recipient_email_id = str_replace(".","-",str_replace("@","-",$recipient_email));
// HTML structure for a cartouche-style button
// We use a container div for the overall cartouche shape and styling,
// and the <a> tag inside for the clickable text/link.
$url = "<span class=\"kcm-cartouche-button-wrapper\">"; // Wrapper voor positionering/marge
$url .= "<a class=\"kcm-cartouche-button\" href=\"" . esc_url($link_href) . "\">";
$url .= "<span class=\"kcm-cartouche-button-text\">" . $button_text . "</span>";
$url .= "</a>";
$url .= "</span>";
    // Add the URL to the array
    //$group_rows[] = $url;
//new code
// ... binnen je foreach loop ...


   // Sla over als er geen e-mailadres is voor de ontvanger
   if (empty($recipient_email)) {
       continue;
   }

   // HTML structure for a cartouche-style button that triggers JavaScript
   $button_text = "Mail: " . esc_html($voornaam) . " " . esc_html($achternaam);

   // Gebruik data-* attributen om de nodige info mee te geven aan JavaScript

   $html_output = "<span id=\"".$recipient_email_id ."\"></span><span class=\"kcm-cartouche-button-wrapper\">";
   $html_output .= "<a class=\"kcm-cartouche-button kcm-contact-trigger\" href=\"#\" "; // Class om JS aan te binden
   $html_output .= "data-recipient-name=\"" . esc_attr($voornaam . ' ' . $achternaam) . "\" ";
   $html_output .= "data-recipient-email=\"" . esc_attr($recipient_email) . "\" "; // BELANGRIJK: e-mail van ontvanger
   $html_output .= "data-task-group=\"" . esc_attr($group_name) . "\" ";
   // Voeg eventueel meer data attributen toe als je ze nodig hebt in het formulier
   $html_output .= ">";
   $html_output .= "<span class=\"kcm-cartouche-button-text\">" . $button_text . "</span>";
   $html_output .= "</a>";
   $html_output .= "</span>";
   // Voeg een placeholder div toe waar het formulier kan komen (optioneel, kan ook globaal)
   // $html_output .= "<div class=\"kcm-contact-form-container\" style=\"display:none;\"></div>";

   $group_rows[] = $html_output;
//end new code

    // Add the name combination to the list of processed names
    $added_names[] = $name_key;
}
?>
    <style type="text/css">
    /* --- Cartouche Button Styling --- */

.kcm-cartouche-button-wrapper {
    display: inline-block; /* BELANGRIJK: Maakt de wrapper inline */
    margin-right: 10px;    /* Ruimte rechts van elke knop-wrapper */
    margin-bottom: 10px;   /* Ruimte onder elke knop-wrapper (voor als ze wrappen) */

}

.kcm-cartouche-button {
    display: inline-block;
    /*background-color: #f0f0f0; */
    color: var(--ast-global-color-0);
    margin-right:10px;
    border: 1px solid var(--ast-global-color-0);;
    padding: 3px 10px; /* Iets kleinere padding voor inline flow misschien? */
    border-radius: 20px;
    text-decoration: none;
    font-size: 0.9em; /* Iets kleiner lettertype voor inline flow? */
    line-height: 1.3;
    text-align: center;
    cursor: pointer;
    transition: background-color 0.3s ease, border-color 0.3s ease, color 0.3s ease;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.kcm-cartouche-button:hover,
.kcm-cartouche-button:focus {
    background-color: #e0e0e0;
    border-color: #bbb;
    color: #111;
    outline: none;
}

.kcm-cartouche-button-text {
    vertical-align: middle;
}
    </style>
    <script type="text/javascript">

    </script>
<?php
    // Return error if nothing found.
    if (count($group_rows) == 0) {
        return "Geen leden voor taakgroep: " . $group_name . "<br/>";
    }


    return implode($group_rows);
}

/**
 * Shortcode to display contact information for a specific person.
 *
 * @param array $atts Shortcode attributes.
 * @return string The formatted contact information.
 */
function tablepress_contact_info_shortcode($atts) {
    //$path = dirname(__FILE__)."/tablePressList.php";
    // Ensure that the php-file is included
    //require_once $path;
      if ( ! class_exists( 'TablePressSurvey' ) ) {
        error_log( 'TablePress Query Plugin: TablePressSurvey class not found even after include.' );
        return '<div>Error: The TablePressSurvey class is not available. Plugin may be corrupted or misconfigured.</div>';
    }

    // Default attributes
    $atts = shortcode_atts(
        array(
            'taskgroup' => '',
            'function' => '',
        ),
        $atts,
        'tablepress-contact-info'
    );

    // Extract attributes
    $taskgroup = sanitize_text_field($atts['taskgroup']);
    $function = sanitize_text_field($atts['function']);
    $path = dirname(__FILE__)."/tablePressList.php";
    // Ensure that the php-file is included
    //require_once $path;
    // Shortcode output
    return get_task_group_email_links($taskgroup, $function);

}
//start contact-form
// Action hook for generating/displaying the contact form via AJAX (Option A).
add_action('wp_ajax_nopriv_kcm_get_contact_form', 'kcm_get_contact_form_callback'); // For non-logged-in users.
add_action('wp_ajax_kcm_get_contact_form', 'kcm_get_contact_form_callback');    // For logged-in users.

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
    // Assuming tpq_enqueue_scripts() handles your JS for the contact triggers.
    //if (function_exists('tpq_enqueue_scripts')) {
        tpq_enqueue_scripts();
    //}

    // Ensure the main class for data retrieval is available.
    // Adjust 'TablePressSurvey' if you have a different class name for this generic purpose.
    if (!class_exists('TablePressSurvey')) {
        error_log('TablePress Generic Contact Shortcode: TablePressSurvey class not found.');
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


    // --- Construct parameters for your TablePressSurvey class ---
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
            // Fallback if parsing fails - user might need to ensure this is correct
            // or your TablePressSurvey class handles it.
            // For example, if columns_to_print is just "{Name,Email}", then this would be the same.
            // If it's "{ContactNaam:{FirstName}+' '+{LastName},ContactEmail:{Email}}", we want "{ContactNaam,ContactEmail}"
             // A simpler approach if your class can infer display names:
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

    // --- Instantiate and use your TablePressSurvey class ---
    // Ensure the table_id is used as the first parameter if your class expects it.
    // The TablePressSurvey class needs to be flexible enough to handle these generic inputs.
    try {
        // You might need to adjust the constructor call based on how TablePressSurvey is designed.
        // For example, if it strictly expects a table *name* (like "taakgroep") rather than *ID*
        // you might need a way to get the table name from its ID or modify the class.
        // Assuming the first parameter can be the table_id or a name derived from it.
      $table_press_query = new TablePressSurvey(
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
        // The get_contact_info() method should be flexible enough to work with the
        // generic column display names (Naam, Email, Telefoon) as defined in $column_names_for_survey.
        // It will then generate the mailto links etc.
        // Alternatively, if get_task_group_email_links is more suitable and can be made generic:
        // return $table_press_query->get_task_group_email_links();
        // For now, let's assume get_contact_info() is the intended generic method.

        $output = $table_press_query->get_contact_info_generic($filter_expression); // This method should produce the final HTML

        if (empty($output)) {
            // Provide a message if no results are found, but not an error.
            // return '<div>' . esc_html__('No contact information found matching your criteria.', 'your-text-domain') . '</div>';
            // Or let get_contact_info() handle the "no results" message.
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
function kcm_get_contact_form_callback() {

    // Security check: Verify nonce if you decide to send one from JS for this specific 'get_form' action.
    // This is optional for form *retrieval* but highly recommended for any action that *changes* data or state.
    // If you add a nonce in JS for this call (e.g., 'get_form_nonce' from wp_localize_script),
    // you would check it here:
    // check_ajax_referer( 'kcm_get_form_action_nonce', 'security' ); // 'security' would be the key of the nonce in $_POST.

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
    // This is crucial for securing the kcm_send_contact_email_callback action.
    // The first parameter is the nonce action, the second is the name of the nonce field in the form.
    $form_html .= wp_nonce_field('kcm_send_email_nonce_action', '_kcm_send_email_nonce_field', true, false);

    // Hidden fields for recipient information and the AJAX action for form submission.
    // The 'action' field tells WordPress which AJAX hook to trigger when this form is submitted.
    $form_html .= '<input type="hidden" name="action" value="kcm_send_contact_email">';
    // These hidden fields carry over the recipient details to the email sending function.
    $form_html .= '<input type="hidden" name="kcm_recipient_email" value="' . esc_attr($recipient_email_for_form) . '">';
    $form_html .= '<input type="hidden" name="kcm_recipient_name" value="' . esc_attr($recipient_name) . '">';

    // Visible fields for the sender.
    // Labels are associated with inputs using 'for' and 'id' attributes for accessibility.
    // 'required' attribute provides basic client-side validation.
    $form_html .= '<p><label for="kcm_sender_name-' . esc_attr($recipient_email_for_form) . '">' . esc_html__('Your Name:', 'tablepress-query') . '</label><br>'; // Unique ID for label
    $form_html .= '<input type="text" id="kcm_sender_name-' . esc_attr($recipient_email_for_form) . '" name="kcm_sender_name" class="kcm-form-input" required></p>';

    $form_html .= '<p><label for="kcm_sender_email-' . esc_attr($recipient_email_for_form) . '">' . esc_html__('Your Email:', 'tablepress-query') . '</label><br>';
    $form_html .= '<input type="email" id="kcm_sender_email-' . esc_attr($recipient_email_for_form) . '" name="kcm_sender_email" class="kcm-form-input" required></p>';

    $form_html .= '<p><label for="kcm_email_subject-' . esc_attr($recipient_email_for_form) . '">' . esc_html__('Subject:', 'tablepress-query') . '</label><br>';
    $form_html .= '<input type="text" id="kcm_email_subject-' . esc_attr($recipient_email_for_form) . '" name="kcm_email_subject" class="kcm-form-input" required></p>';

    $form_html .= '<p><label for="kcm_email_message-' . esc_attr($recipient_email_for_form) . '">' . esc_html__('Message:', 'tablepress-query') . '</label><br>';
    $form_html .= '<textarea id="kcm_email_message-' . esc_attr($recipient_email_for_form) . '" name="kcm_email_message" rows="4" class="kcm-form-input" required></textarea></p>';

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
add_action('wp_ajax_nopriv_kcm_send_contact_email', 'kcm_send_contact_email_callback'); // For non-logged-in users.
add_action('wp_ajax_kcm_send_contact_email', 'kcm_send_contact_email_callback');    // For logged-in users.

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
function kcm_send_contact_email_callback() {
    // 1. Security: Verify the nonce sent with the form submission.
    // The nonce name ('_kcm_email_nonce') must match the name attribute of the nonce field in the form.
    // The nonce action ('kcm_send_email_nonce') must match the action used when creating the nonce field.
    if ( ! isset( $_POST['_kcm_send_email_nonce_field'] ) ||
         ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_kcm_send_email_nonce_field'] ) ), 'kcm_send_email_nonce_action' ) ) {
        wp_send_json_error( array( 'message' => __( 'Security error: Invalid nonce. Please refresh the page and try submitting the form again.', 'tablepress-query' ) ) );
    }

    // 2. Validate and sanitize all submitted input data.
    // Consider using wp_unslash() on $_POST data if there's any chance of magic quotes being active (though less common now).
    $recipient_email = isset($_POST['kcm_recipient_email']) ? sanitize_email(wp_unslash($_POST['kcm_recipient_email'])) : '';
    $recipient_name  = isset($_POST['kcm_recipient_name']) ? sanitize_text_field(wp_unslash($_POST['kcm_recipient_name'])) : __('Contact Person', 'tablepress-query'); // Name of the person being emailed.

    $sender_name    = isset($_POST['kcm_sender_name']) ? sanitize_text_field(wp_unslash($_POST['kcm_sender_name'])) : '';
    $sender_email   = isset($_POST['kcm_sender_email']) ? sanitize_email(wp_unslash($_POST['kcm_sender_email'])) : '';
    $subject        = isset($_POST['kcm_email_subject']) ? sanitize_text_field(wp_unslash($_POST['kcm_email_subject'])) : __('Message via website', 'tablepress-query'); // Default subject
    $message_body   = isset($_POST['kcm_email_message']) ? sanitize_textarea_field(wp_unslash($_POST['kcm_email_message'])) : ''; // Use wp_kses_post() if you need to allow some HTML.

    // Basic validation checks.
    if (empty($recipient_email) || !is_email($recipient_email)) {
        wp_send_json_error(array('message' => __('Error: Invalid recipient email address:'.$recipient_email, 'tablepress-query')));
    }
    if (empty($sender_name) || empty($sender_email) || !is_email($sender_email) || empty($subject) || empty($message_body)) {
        wp_send_json_error(array('message' => __('Error: All fields are required and your email address must be valid.', 'tablepress-query')));
    }

    // Optional: Validate CAPTCHA here if implemented.
    // E.g., if ( ! kcm_verify_captcha($_POST['captcha_response']) ) { wp_send_json_error(...); }

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

// Register the shortcode

function register_tablepress_contact_info_shortcode() {
    // Register the shortcode
	add_shortcode('tablepress-contact-info', 'tablepress_contact_info_shortcode');
}

// Hook the shortcode registration to the 'init' action
add_action('init', 'register_tablepress_contact_info_shortcode');
/**
 * Find the columns that are empty in the given data set.
 */
function find_empty_columns($group_rows,$column_indexes, $columns_to_print){
    //array with all the empty columns
    $empty_columns=array();
    //loop over the columns to print.
    foreach ($columns_to_print as $column_name) {
        //check if empty
        $is_empty=true;
        //get the index of the column
        $index=$column_indexes[$column_name];
        //loop over all rows
        foreach ($group_rows as $row){
            if (isset($row[$index]) && ($row[$index]!="")){
                //we found a filled field.
                $is_empty=false;
            }
        }
        if ($is_empty){
            $empty_columns[]=$column_name;
        }
    }
    return $empty_columns;
}
/**
 * Create a table row for one line, omitting empty columns and adding mailto-href.
 */
function get_table_row_empty_columns($row, $columns_to_print, $column_indexes){
    // Start a table row with conditional formatting
    $output = "<tr>";

    // Add the table cells
    foreach ($columns_to_print as $column_name) {
        if(isset($column_indexes[$column_name])){
            $index = $column_indexes[$column_name];
            if (isset($row[$index])) {
                //check for email
                $cell_value = change_email_to_mailto($row[$index]);
                $output .= "<td>" . $cell_value . "</td>";
            } else {
               $output .= "<td></td>";
            }
        }
    }

    $output .= "</tr>"; // End of table row
    return $output;
}

/**
 * Check if string is an email and convert it to mailto-href.
 */
function change_email_to_mailto(string $value): string {
   //check if the value is an email.
    if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
        //create the mailto-href
        return '<a href="mailto:' . esc_attr($value) . '">' . htmlspecialchars($value) . '</a>';
    } else {
      //not an email, so return the original value.
      return $value;
    }
}
/**
 * Gets the column indexes for the given columns.
 */
function get_column_indexes($header_row, $columns_to_print){
    // Find the indexes of the columns you want
    $column_indexes = [];
    foreach ($columns_to_print as $column_name) {
        $index = array_search($column_name, $header_row);
        if ($index !== false) {
            $column_indexes[$column_name] = $index;
        } else {
            error_log("Column '{$column_name}' not found in TablePress table.");
        }
    }
    return $column_indexes;
}

/**
 * Get the TablePress table ID by its title.
 *
 * @param string $title The title of the TablePress table.
 *
 * @return int|null The ID of the table if found, null otherwise.
 */
function get_tablepress_id_by_title(string $title): ?int {
    // Get all TablePress table posts
    $args = array(
        'post_type'      => 'tablepress_table',
        'post_status'    => 'publish',
        'posts_per_page' => -1, // Get all tables
    );
    $tables = get_posts($args);

    // Check if there are any tables
    if (empty($tables)) {
        error_log("No TablePress tables found.");
        return null;
    }

    // Loop through the tables and find the one with the matching title
    foreach ($tables as $table) {
        if (strcmp( $table->post_title, $title)==0) {
            return (int) $table->ID;
        }
    }

    // Table not found
    error_log("TablePress table with title '{$title}' not found.");
    return null;
}


class TablePressSurvey
{
    const SPACE_PLACEHOLDER = "ssxxaxxss";
    const EQUAL_PLACEHOLDER = "ssxxbxxss";
    const OPEN_PAREN_PLACEHOLDER = "ssxxcxxss";
    const CLOSE_PAREN_PLACEHOLDER = "ssxxdxxss";
    const SMALLER_PLACEHOLDER = "ssxxexxss";
    const BIGGER_PLACEHOLDER = "ssxxfxxss";
    const SMALLER_EQUAL_PLACEHOLDER = "ssxxgxxss";
    const BIGGER_EQUAL_PLACEHOLDER = "ssxxhxxss";
    const UNEQUAL_PLACEHOLDER = "ssxxixxss";
    const DOUBLE_QUOTE_PLACEHOLDER = "ssxxjxxss";
    //const SINGLE_QUOTE_PLACEHOLDER = "ssxxkxxss";

    private $table_id;
    private $table_data;
    public $column_indexes;
    private $columns_to_print;
    private $rows_to_print;
    private $table_title;
    private $column_names;
    private $filter_conditions;
    public $error;
    private $empty_columns;
    private $title;
    private $format;
    private $css_class;
    private $sort_condition;
    private $pdf_files;
    private $download_description;
    private $closest_row;
    private $term_map = [];
    private $function_dictionary;
    private $term_tokens;
    private $row;

    public function __construct(string $tablename = null, string $columns_str = null,
    string $filter_str = null, string $column_names_str = null, string $title_query = null,
    string $sort = null, string $new_css_class = null, string $format = null, string $select_expression = null)
    {
        $this->term_tokens = [
            self::SPACE_PLACEHOLDER => ' ',
            self::EQUAL_PLACEHOLDER => '=',
            self::OPEN_PAREN_PLACEHOLDER => '(',
            self::CLOSE_PAREN_PLACEHOLDER => ')',
            self::SMALLER_PLACEHOLDER => '<',
            self::BIGGER_PLACEHOLDER => '>',
            self::SMALLER_EQUAL_PLACEHOLDER => '<=',
            self::BIGGER_EQUAL_PLACEHOLDER => '>=',
            self::UNEQUAL_PLACEHOLDER => '<>',
            self::DOUBLE_QUOTE_PLACEHOLDER => '"',
            //self::SINGLE_QUOTE_PLACEHOLDER => "'",
        ];
       // Initialize the function dictionary
       $this->function_dictionary = array(
            "bulleted_list" => array(
                "callback" => array($this, "convert_string_to_list"),
                "description" => "Converts a string with newlines or &lt;br/&gt; tags into a bulleted list."
            ),
            "date_description" => array(
                "callback" => array($this, "get_date_description"),
                "description" => "Converts a date-id into a readable date."
            ),
            "bold" => array(
                "callback" => array($this, "make_bold"),
                "description" => "Converts the value to bold."
            ),
            "days_plus" => array(
                "callback" => array($this, "days_plus"),
                "description" => "Adds a number of days to the current date and returns the new date in yymmdd format."
            ),
            "italics" => array(
                "callback" => array($this, "make_italics"),
                "description" => "Converts the value to italics."
            ),
            "h3" => array(
                "callback" => array($this, "make_h3"),
                "description" => "Converts the value to h3."
            ),
            "uppercase" => array(
                "callback" => array($this, "make_uppercase"),
                "description" => "Converts text to uppercase."
            ),
            "lowercase" => array(
                "callback" => array($this, "make_lowercase"),
                "description" => "Converts text to lowercase."
            ),
            "trim" => array(
                "callback" => array($this, "make_trim"),
                "description" => "Trim a string. Usage: trim(column_name, length)"
            ),
            "comma" => array(
               "callback" => array($this, "make_comma"),
               "description" => "Returns a comma (,)."
           ),

            // Add other functions here
       );
       if (!$tablename){
            return;
        }
        $this->error = null;

        //trim the title:
        $tablename = trim($tablename);

        $this->css_class = $new_css_class;
        $this->format = $format;
        $this->sort_condition = $sort;
        $this->title = $title_query;
        $this->pdf_files = [];
        $this->download_description = "Download";
        //$this->error="css-class:{$new_css_class}.";
        $this->table_title = $tablename;
        // Get the TablePress table ID by title
        $this->table_id = $this->get_tablepress_id_by_title($tablename);
        // Check if the table ID is valid
        if (!is_int($this->table_id) || $this->table_id <= 0) {
            $this->error = "Invalid TablePress table ID for '{$title_query}'.";
            return;
        }

        // Get the table data
        $this->table_data = $this->get_table_data($this->table_id);
        if (!$this->table_data) {
            $this->error = "Error retrieving or decoding data from TablePress table";
        }

        //get the column indexes
        $this->column_indexes = $this->get_column_indexes($this->table_data[0]);

        //set the columns to print
        $this->columns_to_print = $this->parse_columns($columns_str);

        //set the filter-conditions
        $this->filter_conditions = $this->parse_filter($filter_str);

        //set the rows to print
        $this->rows_to_print = $this->filter_rows($this->filter_conditions);

        //set the select_expressions
        $this->rows_to_print = $this-> parse_select_expression($select_expression, $this->rows_to_print);

        //sort the rows to print
        $this->sort_rows();

        //set the column_names
        $this->column_names = $this->parse_column_names($column_names_str);

        $this->find_empty_columns();
    }

    public function getHelp() {
        $output = "<div class='tablepress-survey-help'>"; // Add a container for styling

        // Shortcode Description
        $output .= "<h2>[tablepress-query] Shortcode Help</h2>";
        $output .= "<p>This shortcode displays data from a TablePress table.</p>";

        // Shortcode Syntax
        $output .= "<h3>Syntax</h3>";
        $output .= "<pre>[tablepress-query
    tablename=\"table-name\"
    columns=\"{column1, column2, function(column3), 'constant text', ...}\"
    column_names=\"{Column Name 1, Column Name 2, ...}\"
    filter=\"filter condition\"
    cssclass=\"css-class\"
    format=\"card\"
    sort=\"column-name [asc|desc]\"
    title=\"text\"
]</pre>";

        // Parameter Descriptions
        $output .= "<h3>Parameters</h3>";
        $output .= "<ul>";
        $output .= "<li><strong>tablename:</strong> (Required) The name (title) of the TablePress table.</li>";
        $output .= "<li><strong>columns:</strong> A comma-separated list of column names or expressions to display. If no columns are specified, use all columns from the table</li>";
        $output .= "<li><strong>column_names:</strong> (Optional) A comma-separated list of custom column headers. If not provided, the Tablepress table headers will be used.</li>";
        $output .= "<li><strong>filter:</strong> (Optional) A filter condition (TBD).</li>";
        $output .= "<li><strong>cssclass:</strong> (Optional) A custom CSS class for styling.</li>";
        $output .= "<li><strong>format:</strong> (Optional) The display format (e.g., 'card', 'table', etc.). Default is TBD.</li>";
        $output .= "<li><strong>sort:</strong> (Optional) Sorts the data. E.g. 'name asc' or 'date desc'.</li>";
        $output .= "<li><strong>title:</strong> (Optional) A custom title for the query-result.</li>";
        $output .= "</ul>";

        // Function List
        $output .= "<h3>Available Functions</h3>";
        $output .= "<ul>";
        foreach ($this->function_dictionary as $function_name => $function_data) {
            $output .= "<li><strong>{$function_name}(column_name)</strong>: {$function_data["description"]}</li>";
        }
        $output .= "</ul>";

        // Examples
        $output .= "<h3>Examples</h3>";
        // Example 1
        $output .= "<div class='shortcode-example'>";
        $output .= "<p><code>"
            . "[tablepress-query "
            . "columns=\"{naam,bijzonderheden,bold(adres),telefoon,email,webpage}\" "
            . "column_names=\"{,,Adres,Telefoon,Email,}\" "
            . "tablename=\"contacten\" "
            . "cssclass=\"contacten-css-class\" "
            . "format=\"card\"]"
            . "</code></p>";
        $output .= "</div>";

        // Example 2
        $output .= "<div class='shortcode-example'>";
        $output .= "<p><code>"
            . "[tablepress-query "
            . "columns=\"{h3(naam),frequentie,verantwoordelijke,bold(beschrijving),bulleted_list(procedure)}\" "
            . "column_names=\"{,frequentie,verantwoordelijke,,}\" "
            . "tablename=\"procedures-webmeester\" "
            . "cssclass=\"procedures-css-class\" "
            . "format=\"card\"]"
            . "</code></p>";
        $output .= "</div>";
        $output .= "</div>"; // Close the container

        return $output;
    }

    /**
     * Adds a given number of days to the current date and returns the new date in yymmdd format.
     *
     * @param string $days The number of days to add (can be positive or negative).
     * @return string The new date in yymmdd format, or an empty string on error.
     */
    private function days_plus(string $days): string
    {
        // Validate the input
        if (!ctype_digit($days) && $days[0] != '-') {
            return ""; // Return empty string if input is not an integer or a negative number
        }
        // Convert days to an integer
        $days = (int) $days;

        // Get the current date and time
        $current_date = new DateTime();

        // Modify the date by adding the specified number of days
        $current_date->modify("+$days days");

        // Format the date as yymmdd
        return $current_date->format("ymd");
    }

    public function get_column_indexes_var(){
        return $this->column_indexes;
    }

    public function get_all_rows(){
        return $this->rows_to_print;
    }

    /**
     * sort the rows based on the sorting condition
     * the rows to be sorted are in the class-variable: $this->rows_to_print
     * the sort-condition is in the class-variable: $this->sort_condition
     * example of sorting conditions:
     * Achternaam
     * Achernaam,Descending
     * Achternaam+Voornaam,Ascending
     * in this condition Achternaam and Voornaam must exist as column-title. the sort-order is optional, and can also be lowercase.
     */
    private function sort_rows()
    {
        //check if there is a sort-condition
        if (!isset($this->sort_condition) || !$this->sort_condition) {
            return; // Nothing to sort
        }
        // Split the sorting condition into fields and direction
        $sort_parts = explode(',', $this->sort_condition);
        $sort_fields_str = trim($sort_parts[0]);
        $sort_direction = (count($sort_parts) > 1) ? trim(strtolower($sort_parts[1])) : 'ascending';

        // Split compound sort fields
        $sort_fields = explode('+', $sort_fields_str);
        $sort_fields = array_map('trim', $sort_fields);

        // Check if all sort fields are valid columns
        foreach ($sort_fields as $sort_field) {
            if (!array_key_exists($sort_field, $this->column_indexes)) {
                $this->error = "Invalid sort field: '{$sort_field}'.";
                return;
            }
        }

        // Sort the rows using a custom comparison function
        usort($this->rows_to_print, function ($row_a, $row_b) use ($sort_fields, $sort_direction) {
            foreach ($sort_fields as $sort_field) {
                // Get the index for the current sort field
                $sort_index = $this->column_indexes[$sort_field];

                // Get the values to compare
                $value_a = $row_a[$sort_index];
                $value_b = $row_b[$sort_index];

                // Compare the values
                $comparison = strcasecmp($value_a, $value_b);

                // If the values are different, apply the sort direction and return the result
                if ($comparison !== 0) {
                    return ($sort_direction === 'descending') ? -$comparison : $comparison;
                }
            }
            // If all sort fields are equal, maintain original order
            return 0;
        });
    }

    /**
     * Check for empty columns and mark them for hiding.
     */
    private function find_empty_columns()
    {
        $this->empty_columns = [];
        // Loop through each column
        foreach ($this->columns_to_print as $column_name => $expression) {
            $is_empty = true; // Assume empty initially
            // Loop through each row
            foreach ($this->rows_to_print as $row) {
                // Get the value of the cell
                $cell_value = $this->get_column_value($row, $expression);
                // If we find a non-empty cell, this column is not empty
                if (!empty($cell_value)) {
                    $is_empty = false;
                    break; // No need to check other rows for this column
                }
            }
            // If all cells were empty, add the column to the list of empty columns
            if ($is_empty) {
                $this->empty_columns[] = $column_name;
            }
        }
    }


    /**
     * Get the TablePress table ID by its title.
     *
     * @param string $title The title of the TablePress table.
     *
     * @return int|null The ID of the table if found, null otherwise.
     */
    function get_tablepress_id_by_title(string $title): ?int
    {
        // Get all TablePress table posts
        $args = array(
            'post_type'      => 'tablepress_table',
            'post_status'    => 'publish',
            'posts_per_page' => -1, // Get all tables
        );
        $tables = get_posts($args);

        // Check if there are any tables
        if (empty($tables)) {
            error_log("No TablePress tables found.");
            return null;
        }

        // Loop through the tables and find the one with the matching title
        foreach ($tables as $table) {
            if (strcmp($table->post_title, $title) == 0) {
                return (int) $table->ID;
            }
        }

        // Table not found
        error_log("TablePress table with title '{$title}' not found.");
        return null;
    }
    /**
     * Get the table-data of the given table.
     */
    public function get_table_data(int $table_id)
    {
        // Get the table post
        $table_post = get_post($table_id);

        // Check if the table post exists and is of the correct type
        if (!$table_post || $table_post->post_type !== 'tablepress_table') {
            error_log("TablePress table with id: " . $table_id . " not found.");
            return null;
        }

        // Get the table data from post content.
        $table_data_json = $table_post->post_content;

        // Check if data is present
        if (!$table_data_json) {
            error_log("Missing table data for TablePress table with id: " . $table_id . ".");
            return null;
        }
        //decode the json-string to an array
        $table_data = json_decode($table_data_json);

        //check if json-decode was succesfull
        if (!$table_data) {
            error_log("Error json-decoding table data for TablePress table with id: " . $table_id . ".");
            return null;
        }

        //check if it is an array
        if (!is_array($table_data)) {
            error_log("Table data for TablePress table with id: " . $table_id . " not an array.");
            return null;
        }
        return $table_data; //$this->table_title;//.
    }

    /**
     * Gets the column indexes for the given columns.
     */
    public function get_column_indexes($header_row)
    {
        // Find the indexes of the columns you want
        $column_indexes = [];
        foreach ($header_row as $column_name) {
            $index = array_search($column_name, $header_row);
            if ($index !== false) {
                $column_indexes[$column_name] = $index;
            } else {
                error_log("Column '{$column_name}' not found in TablePress table.");
            }
        }
        return $column_indexes;
    }
    /**
     * Parses the columns string.
     */
    private function parse_columns($columns_str)
    {
        $columns = [];
        if ($columns_str === null) {
            // If no columns are specified, use all columns from the table
            $columns = $this->table_data[0];
        } else {
            // Extract column definitions
            $columns_definitions = explode(',', trim($columns_str, '{}'));

            foreach ($columns_definitions as $column_definition) {
                $column_parts = explode(':', trim($column_definition));
                if (count($column_parts) == 2) {
                    // Computed column
                    $column_name = trim($column_parts[0]);
                    $column_expression = trim($column_parts[1]);
                    $columns[$column_name] = $column_expression;
                } else {
                    // Existing column
                    $column_name = trim($column_parts[0]);
                    $columns[$column_name] = $column_name;
                }
            }
        }
        return $columns;
    }
    /**
     * Parses the filter string into filter conditions.
     *
     * @param string|null $filter_str The filter string.
     * @return array An array of filter conditions.
     */
    private function parse_filter($filter_str)
    {
        $filters = [];
        if ($filter_str !== null) {
            // Split into AND conditions
            $and_conditions = explode('},', trim($filter_str, '{}'));

            foreach ($and_conditions as $and_condition) {
                $condition_parts = explode(':', trim($and_condition, '{}'));
                $field = trim($condition_parts[0]);
                $or_values_str = trim($condition_parts[1], '{}');
                //check for link
                if (strpos($or_values_str, "LINK/") !== false) {
                    $link_part = explode('LINK/', $or_values_str);
                    if (count($link_part) > 1) {
                        $link_url = $link_part[1];
                        $parts = explode(';', $link_url);
                        if (count($parts) > 1) {
                            $link_url = $parts[0];
                            $this->download_description = $parts[1];
                        }
                        $filters[$field]["LINK"] = $link_url;
                        $pdf_dir = WP_CONTENT_DIR . "/" . $link_url;

                        // Get the list of PDF files
                        $this->pdf_files = get_pdf_list($pdf_dir);
                    }
                } else {
                    // Check if any value is 'TODAY'
                    $or_values = explode(',', $or_values_str);
                    $containsToday = false;
                    foreach ($or_values as $value) {
                        if (strcasecmp(trim($value), "TODAY") === 0) {
                            $containsToday = true;
                            break;
                        }
                    }
                    if ($containsToday) {
                        $filters[$field] = [];
                        foreach ($or_values as $value) {
                            $value = trim($value);
                            if (strcasecmp($value, "TODAY") === 0) {
                                $filters[$field]['TODAY'] = date('Y-m-d'); // Replace 'TODAY' with today's date
                            } elseif (is_numeric($value)) {
                                $filters[$field][] = (int)$value; // Store as an integer
                            }
                        }
                    } else {
                        $filters[$field] = array_map('trim', $or_values);
                    }
                }
            }
        }
        return $filters;
    }


    /**
     * Filters an array of rows based on a given filter expression.
     *
     * This function iterates through the provided array of rows and applies the
     * filter expression to each row using the `filter_row` method. Rows that
     * satisfy the filter expression are kept, while rows that do not are removed.
     *
     * @param string $filter_expression The filter expression to apply to each row.
     * @param array $rows_to_print The array of rows to filter.
     * @return array The filtered array of rows.
     */
    private function parse_select_expression($filter_expression, $rows_to_print)
    {
        // If there is no filter expression, return all rows.
        if (empty($filter_expression)) {
            return $rows_to_print;
        }

        // Use array_filter to iterate over the rows and keep only those that match the filter.
        $rows_to_print = array_filter($rows_to_print, function ($row) use ($filter_expression) {
            // Keep the row if it satisfies the filter (filter_row returns true).
            return $this->filter_row($row, $filter_expression);
        });

        // Reindex the array (optional).
       // $rows_to_print = array_values($rows_to_print);

        return $rows_to_print;
    }

    /**
     * Filter rows based on the given conditions.
     *
     * @param array $filter_conditions The filter conditions.
     * @return array The filtered rows.
     */
    private function filter_rows(array $filter_conditions)
    {
        $filtered_rows = [];
        $rows = array_slice($this->table_data, 1); // Skip header row
        $today = date('Y-m-d');
        // Variables to keep track of the closest date
        $closest_row = null;
        $min_difference = PHP_INT_MAX; // Start with a very large difference

        foreach ($rows as $row) {
            $matches_all_conditions = true;

            foreach ($filter_conditions as $field => $values) {
                $matches_any_value = false;
                //check if the column exists
                if (array_key_exists($field, $this->column_indexes)) {
                    $index = $this->column_indexes[$field];
                    $row_value_str = trim($row[$index]);
                    //check if we have a link-filter
                    if (isset($values['LINK'])) {
                        //this is handled later. We always want to show this row.
                        $matches_any_value = true;
                    } else {

                        //check if we have a date-filter
                        if (isset($values['TODAY'])) {
                            $row_date = $this->convert_dutch_date($row_value_str);
                            if (!($row_date === false)) {
                                // Calculate the difference in days
                                $diff = $this->date_diff_in_days($today, $row_date);
                                //check if the row has a date closer to today
                                if (abs($diff) < $min_difference) {
                                    $min_difference = abs($diff);
                                    $closest_row = $row_value_str;
                                }

                                // Check the difference against the given range
                                $min_diff = isset($values[0]) ? $values[0] : 0;
                                $max_diff = isset($values[1]) ? $values[1] : 0;
                                //check if the difference is within the given range
                                if ($diff >= $min_diff && $diff <= $max_diff) {
                                    $matches_any_value = true;
                                }
                            }
                        } else {
                            //not a date-filter
                            foreach ($values as $value) {
                                if (strcasecmp($row_value_str, $value) === 0) {
                                    $matches_any_value = true;
                                    break;
                                }
                            }
                        }
                    }
                } else {
                    $matches_any_value = false;
                }

                if (!$matches_any_value) {
                    $matches_all_conditions = false;
                    break;
                }
            }

            if ($matches_all_conditions) {
                $filtered_rows[] = $row;
            }
        }

         //store the result in a class-variable.
         $this->closest_row=$closest_row;
         return $filtered_rows;
    }
    /**
     * Converts a Dutch date string (dd-mmm-yyyy) to YYYY-MM-DD.
     *
     * @param string $dutch_date The Dutch date string.
     * @return string|false The converted date, or false on failure.
     */
    private function convert_dutch_date($dutch_date)
    {
        // Create the mapping from dutch to english.
        $month_map = [
            //add english-compatible months
            'mar' => 'Mar',
            'may' => 'May',
            'oct' => 'Oct',
            //dutch-compatible
            'jan' => 'Jan',
            'feb' => 'Feb',
            'mrt' => 'Mar',
            'apr' => 'Apr',
            'mei' => 'May',
            'jun' => 'Jun',
            'jul' => 'Jul',
            'aug' => 'Aug',
            'sep' => 'Sep',
            'okt' => 'Oct',
            'nov' => 'Nov',
            'dec' => 'Dec',
        ];
        // Explode by '-'
        $parts = explode('-', $dutch_date);
        if (count($parts) !== 3) {
            return false;
        }

        // Get the different parts
        $day = $parts[0];
        $month_nl = strtolower($parts[1]);
        $year = $parts[2];

        // Get the month in english.
        if (!array_key_exists($month_nl, $month_map)) {
            return false;
        }
        $month_en = $month_map[$month_nl];

        // Convert it to a date
        $date = DateTime::createFromFormat('d-M-Y', $day . '-' . $month_en . '-' . $year);
        if (!$date) {
            return false;
        }
        // Return the date in YYYY-MM-DD format
        return $date->format('Y-m-d');
    }

    /**
     * Calculate the difference in days between two dates.
     *
     * @param string $date1 The first date (YYYY-MM-DD).
     * @param string $date2 The second date (YYYY-MM-DD).
     * @return int The difference in days.
     */
    private function date_diff_in_days($date1, $date2)
    {
        $datetime1 = date_create($date1);
        $datetime2 = date_create($date2);
        if (($datetime1 === false) || ($datetime2 === false)) {
            return 100000; //large number, so it will be filtered out.
        }

        $interval = date_diff($datetime1, $datetime2);
        return (int) $interval->format('%R%a'); // %R%a gives signed difference
    }
    public function getError()
    {
        return $this->error;
    }

    public function getTable()
    {
        if ($this->error !== null) {
            return $this->error;
        }
        if (strcmp(strtolower($this->format), "card") == 0)
            $output = $this->print_card_output();
        else
            $output = $this->print_table_output();
        return $output;
    }

    /**
     * Prints the TablePress data as a series of cards.
     *
     * @return string The HTML output for the cards.
     */
    private function print_card_output()
{
    $output = '';
    $css_class = "";
    if ($this->css_class) {
        $css_class = $this->css_class . "";
    }
    $query_title = "";
    if ($this->title) {
        $query_title = "<div class=\"table-press-query-title\">" . $this->title . "</div>";
    }

    $output .= "<div class=\"parent-of-table-press-query\">"; // Start the container for the cards
    $output .= "<div class=\"table-press-query\">";
    $output .= $query_title;
    $output .= "<div class=\"{$css_class} contact-cards-container\">"; // Start the container for the cards

    foreach ($this->rows_to_print as $row) {
        $output .= '<div class="contact-card">'; // Start a single card
        $column_index = 0;
        foreach ($this->columns_to_print as $column_name => $expression) {
            if (in_array($column_name, $this->empty_columns)) continue;

            $column_value = $this->get_column_value($row, $expression);

            // Omit empty columns:
            if (!empty($column_value) || $column_value=="0") {
                // Generate column-specific CSS class:
                $safe_column_name = sanitize_html_class($column_name);
                foreach ($this->function_dictionary as $key => $f) {
                    $safe_column_name = str_replace($key, "",$safe_column_name);
                }
                // Use column_names if available:
                if (!empty($this->column_names[$column_index])) {
                    $pretty_column_name = $this->column_names[$column_index];
                } else {
                    $pretty_column_name = $column_name;
                }

                $output .= '<div class="card-row">';
                // Omit empty labels:
                if (!empty($this->column_names[$column_index])) {
                    $output .= "<div class=\"card-label column-{$safe_column_name}-label\">" . htmlspecialchars($pretty_column_name) . ":</div>";
                }

                // URL as link (improved):
                if ($this->is_probably_a_url($column_value)) {
                    // Add http:// if the URL starts with www.
                    if (strpos($column_value, 'www.') === 0) {
                        $column_value = 'http://' . $column_value;
                    }
                    $column_value = "<a href=\"{$column_value}\" target=\"_blank\">{$column_value}</a>";
                }
                $output .= "<div class=\"card-value column-{$safe_column_name}-value\">" . $column_value . "</div>";
                $output .= '</div>'; // Close card-row
            }
            $column_index++;
        }
        $output .= '</div>'; // Close card
    }

    $output .= '</div>'; // Close the container for the cards
    $output .= "</div>";
    $output .= "</div>";
    return $output;
}

    /**
     * Check if a string is probably a URL (starts with http(s):// or www.).
     *
     * @param string $string The string to check.
     * @return bool True if it's probably a URL, false otherwise.
     */
    private function is_probably_a_url($string) {
        // Check for http://, https://, or www. at the beginning
        return (strpos($string, 'http://') === 0 || strpos($string, 'https://') === 0 || strpos($string, 'www.') === 0);
    }

    /**
     * Parses the column names string.
     *
     * @param string|null $column_names_str The column names string.
     * @return array An array of column names.
     */
    private function parse_column_names($column_names_str)
    {
        $column_names = [];
        if ($column_names_str !== null) {
            // Remove brackets and split by comma
            $names = explode(',', trim($column_names_str, '{}'));
            // Loop through each name and sanitize
            foreach ($names as $name) {
                $sanitized_name = trim($name);
                $sanitized_name = $sanitized_name;
                $column_names[] = $sanitized_name;
            }
        } else {
            $column_names = $this->columns_to_print;
        }
        return $column_names;
    }

    /**
     * Prints the table output.
     *
     * @param array $columns_to_print The columns to print.
     * @param array $rows_to_print The rows to print.
     *
     * @return string The HTML string representing the table.
     */
    private function print_table_output()
    {
        $css_class = "";
        if ($this->css_class) {
            $css_class = $this->css_class . "";
        }
        $query_title = "";
        if ($this->title) {
            $query_title = "<div class=\"table-press-query-title\">" . $this->title . "</div>";
        }
        // Build the HTML table
        $output =  "<div class='table-press-query-slider'>";
        $output .= "<div class=\"parent-of-table-press-query\"><div class=\"table-press-query\">$query_title<table class=\"table-press-query-table $css_class\" >";

        // Add the header row
        $output .= "<thead class=\"table-press-header\"><tr>";
        //if no column_names are set, we will use the columns
        if ($this->column_names == null) {
            foreach ($this->columns_to_print as $column_name => $expression) {
                if (in_array($column_name, $this->empty_columns)) continue;
                $output .= "<th>" . htmlspecialchars($column_name) . "</th>";
            }
        } else {
            //use the set column_names
            foreach ($this->column_names as $column_name) {
                if (in_array($column_name, $this->empty_columns)) continue;
                $output .= "<th>" . htmlspecialchars($column_name) . "</th>";
            }
        }

        $output .= "</tr></thead>";

        // Add the table body
        $output .= "<tbody>";
        foreach ($this->rows_to_print as $row) {
            // Add the table row
            $output .= $this->get_table_row($row);
        }
        $output .= "</tbody>";
        $output .= "</table></div></div></div>";

        return $output;
    }
    /**
     * Create a table row for one line, omitting empty columns and adding mailto-href.
     */
    private function get_table_row($row)
    {
        // Start a table row with conditional formatting
        $output = "<tr>";
        $is_closest = false;

        foreach ($this->columns_to_print as $column_name => $expression) {
            if (isset($this->filter_conditions[$column_name]['TODAY'])) {
                //get the value of the column
                $cell_value = $this->get_column_value($row, $expression);
                if (strcmp($cell_value, $this->closest_row)==0)
                $is_closest = true;
            }
        }


        // Add the table cells
        foreach ($this->columns_to_print as $column_name => $expression) {
            if (in_array($column_name, $this->empty_columns)) continue;
            //get the value of the column
            $cell_value = $this->get_column_value($row, $expression);
            $is_download_link = false;
            //if column is defined as link
            if (isset($this->filter_conditions[$column_name]['LINK'])) {

                //see if id in any name of pdf-files
                foreach ($this->pdf_files as $pdf_file) {
                    //check if id in file-name
                    if (strpos($pdf_file, $cell_value) !== false) {
                        //create download-link
                        $file_directory = $this->filter_conditions[$column_name]['LINK'];
                        $href = htmlspecialchars(WP_CONTENT_URL . "/" . $file_directory . $pdf_file);
                        $download_description = $this->download_description;
                        $cell_value = "<a href=\"$href\">$download_description</a>";
                        $is_download_link = true;
                        break;
                    }
                }
                if (!$is_download_link) {
                    $cell_value = ".";
                }
            }
            if ($is_closest){
                $cell_value = "<b><i>$cell_value</i></b>";
            }

            //add the column to the output
            $output .= "<td>" . $cell_value . "</td>";
        }

        $output .= "</tr>"; // End of table row
        return $output;
    }

    /**
     * Replaces a function call within an expression with a result string.
     *
     * This function accurately targets a function call by searching for the
     * function name immediately followed by an opening parenthesis (e.g., "trim(").
     * It handles cases where the function name might appear elsewhere in the
     * expression as part of a string constant or other unrelated content.
     *
     * @param string $expression The original expression string containing the function call.
     * @param string $function_name The name of the function to replace.
     * @param int $start_index The index of the character *after* the opening parenthesis.
     * @param int $close_index The index of the closing parenthesis.
     * @param string $result_expression The string to replace the function call with.
     * @return string The modified expression with the function call replaced.
     */
    function replace_function_call($expression, $function_name, $start_index, $close_index, $result_expression)
    {
        // Construct the search string: function name followed by an opening parenthesis.
        $function_call_start = $function_name . "(";

        // Find the position of the function call (e.g., "trim(") within the expression.
        // We use strpos() instead of strrpos() because we want the leftmost match.
        $start_of_function_call = strpos($expression, $function_call_start);
        //check if it was found.
        if ($start_of_function_call === false)
            return $expression;
        // Calculate the length of the substring to replace.
        // We need to replace everything from the beginning of the function call
        // up to and including the closing parenthesis.
        $length_to_replace = ($close_index + 1) - $start_of_function_call;

        // Use substr_replace to perform the replacement.
        $new_expression = substr_replace($expression, $result_expression, $start_of_function_call, $length_to_replace);

        return $new_expression;
    }
    /**
     * Removes whitespace outside of string constants.
     *
     * @param string $expression The expression to process.
     * @return string The expression with whitespace removed outside of string constants.
     */
    private function remove_whitespace_outside_strings($expression)
    {
        $result = "";
        $in_string = false;
        $quote_char = null;

        for ($i = 0; $i < strlen($expression); $i++) {
            $char = $expression[$i];

            if ($char == "'" || $char == '"') {
                // If we're not inside a string, start a new string
                if (!$in_string) {
                    $in_string = true;
                    $quote_char = $char; // Remember which quote character we started with
                    $result .= $char;
                }
                // If we are in a string and we encounter the same quote character, end the string
                elseif ($quote_char == $char) {
                    $in_string = false;
                    $quote_char = null;
                    $result .= $char;
                }
                // If we're in a string and we encounter a different quote character, it's just part of the string
                else {
                    $result .= $char;
                }
            }
            // If we're not inside a string, remove whitespace
            elseif (!$in_string && ctype_space($char)) {
                // Ignore whitespace
            }
            // Otherwise, add the character to the result
            else {
                $result .= $char;
            }
        }

        return $result;
    }
    /**
     * Get the value of a column.
     */
    public function get_column_value($row, $expression)
    {
       // Remove whitespace outside of string constants
       $expression = $this->remove_whitespace_outside_strings($expression);
       //check if it is an email.
        if (filter_var($expression, FILTER_VALIDATE_EMAIL)) {
            //if so, create the mail-to-link
            return $this->change_email_to_mailto($expression);
        }

        // Check if the expression is a function call
        while (preg_match('/[a-zA-Z0-9_]+\(/', $expression)) {
            //error_log("this is a function");
            // Extract function name
            preg_match('/([a-zA-Z0-9_]+)\(/', $expression, $function_matches);
            $function_name = $function_matches[1];
            //error_log("function_name: $function_name");

            // Find the matching closing parenthesis
            $open_count = 0;
            $start_index = strpos($expression, '(') + 1;
            $close_index = -1;
            $argument_string = "";
            for ($i = $start_index; $i < strlen($expression); $i++) {
                if ($expression[$i] == '(') {
                    $open_count++;
                } elseif ($expression[$i] == ')') {
                    if ($open_count == 0) {
                        $close_index = $i;
                        break;
                    }
                    $open_count--;
                }
            }
            //if there is a close index
            if ($close_index != -1) {
                // Extract argument string
                $argument_string = substr($expression, $start_index, $close_index - $start_index);
                //error_log("argument_string: $argument_string");

                //check if it is a supported function
                if (array_key_exists($function_name, $this->function_dictionary)) {
                    // Get the function callback
                    $callback = $this->function_dictionary[$function_name]["callback"];
                    // Get the argument value
                    $argument_value = $this->get_column_value($row, $argument_string);
                    //echo "argument values:$argument_string and $argument_value";
                    if (!$argument_value){//it is a constant
                        $argument_value = $argument_string;
                    }
                    // Call the function
                    $result = call_user_func($callback, $argument_value);
                    //if there is more after the expression, handle it too.
                    //replace function-name + (........) within expression with $result
                    $result_expression = $this->replace_function_call($expression, $function_name, $start_index, $close_index, $result);
                    if (str_contains($result_expression, "+")){
                        //add string-constant-braces because result of argument is a string-constant within a concatenation
                        $result = "'".$result."'";
                        //continue with changed expression, see if it contains more function-calls
                        $expression = $this->replace_function_call($expression, $function_name, $start_index, $close_index, $result);

                    } else{ //not concatenation
                        //error_log("expression:::$expression:::$result_expression:::$result");
                        //als $result
                        return $result_expression;
                    }
                } else {
                    return "Unsupported function $function_name";
                }
            } else {
                return "";
            }

        }

        //error_log("expression::$expression");
        //check if the expression is a simple value
        if (array_key_exists($expression, $this->column_indexes)) {
            //get the index from the column_indexes
            $index = $this->column_indexes[$expression];
            //get the value from the row
            $value = $row[$index];
           //double quotes are stored as '' inside tablePres-data
            $value = str_replace("''", '"',$value);

            return $this->change_email_to_mailto($value);
        }
        //check if it is a concatenation
        if (strpos($expression, '+') !== false) {
            //split the expression
            $parts = explode("+", $expression);
            //create the result.
            $result = "";
            foreach ($parts as $part) {
                //trim the part if it is not a string constant.
                if (!($part[0] === "'" && $part[strlen($part) - 1] === "'")) {
                    $part = trim($part);
                    //add the content of this field.
                    $part = $this->get_column_value($row, $part);
                } else {
                    //remove the quotes.
                    $part = substr($part, 1, -1);
                }
                //add all parts to the result
                $result = $result . $part;
            }
            return $result;
        }
    }
    /**
     * Make the text bold
     */
    private function make_bold($value){
        return "<b>$value</b>";
      }
    /**
     * Make the text italics
     */
    private function make_italics($value){
        return "<i>$value</i>";
      }
    /**
     * Make the text h3
     */
    private function make_h3($value){
        return "<h3>$value</h3>";
      }
    private function make_uppercase($text) {
        return strtoupper($text);
    }

    private function make_lowercase($text) {
        return strtolower($text);
    }
    private function make_trim($text){
        $trimmed = trim($text, " \t\n,-.");
        return $trimmed;
    }
    private function make_comma() {
        return ",";
    }

    /**
 * Converts a string with <br/> line breaks OR newlines into an unordered list (ul) string.
 *
 * @param string $inputString The string to convert.
 * @return string The converted string containing <li> elements, or an empty string if the input is empty.
 */
function convert_string_to_list($inputString) {
    // Check if the input string is empty
    if (empty($inputString)) {
        return ""; // Return empty string if the input is empty
    }

    // Replace <br/> with newline characters, so we have only 1 delimiter
    $inputString = str_replace("<br/>", "\n", $inputString);

    // Split the string by newlines
    $items = explode("\n", $inputString);

    // Start the list
    $output = "";

    // Loop through each item and wrap it in <li> tags
    foreach ($items as $item) {
        // Trim any leading or trailing whitespace
        $item = trim($item);
         // Check if the item is empty (after trimming)
        if (!empty($item)){
            if (str_starts_with($item, "-")){
                //remove first char from string
                $item = substr($item, 1);
                $item = "<div class=\"second-order\">$item</div>";
            }

          $output .= "<li>$item</li>";
        }
    }

    return "<ul>$output</ul>";
}
/**
 * Gets a formatted date description from a date ID.
 *
 * @param string $value The date ID in YYMMDD format (e.g., "250325").
 * @return string The formatted date description in Dutch (e.g., "Zondag 25 maart"),
 *                or an error message if the date ID is invalid.
 */
private function get_date_description($value) {
    // Check if the input value is a valid 6-digit number
    if (!preg_match('/^\d{6}$/', $value)) {
        return "Ongeldige datum-ID"; // Invalid date ID
    }

    // Extract year, month, and day from the date ID
    $year = substr($value, 0, 2);
    $month = substr($value, 2, 2);
    $day = substr($value, 4, 2);

    // Adjust year for 21st century (20xx)
    $year = (int) $year;
    if ($year <= date('y')) {
        $year += 2000; // Assumes 21st century for past or current year
    } else {
        $year += 1900; // Assumes 20th century for future years
    }

    // Create a DateTime object
    try {
        $date = new DateTime("$year-$month-$day");
    } catch (Exception $e) {
        return "Ongeldige datum"; // Invalid date
    }

    // Format the date in Dutch
    $dayOfWeek = $date->format('l'); // Full day of the week
    $dayOfMonth = $date->format('j'); // Day of the month (without leading zeros)
    $monthName = $date->format('F'); // Full month name

    // Dutch day and month names
    $dutchDays = array(
        'Monday' => 'Maandag',
        'Tuesday' => 'Dinsdag',
        'Wednesday' => 'Woensdag',
        'Thursday' => 'Donderdag',
        'Friday' => 'Vrijdag',
        'Saturday' => 'Zaterdag',
        'Sunday' => 'Zondag'
    );

    $dutchMonths = array(
        'January' => 'januari',
        'February' => 'februari',
        'March' => 'maart',
        'April' => 'april',
        'May' => 'mei',
        'June' => 'juni',
        'July' => 'juli',
        'August' => 'augustus',
        'September' => 'september',
        'October' => 'oktober',
        'November' => 'november',
        'December' => 'december'
    );

    // Translate day and month to Dutch
    $dayOfWeekDutch = $dutchDays[$dayOfWeek];
    $monthNameDutch = $dutchMonths[$monthName];

    // Return the formatted date description
    return "$dayOfWeekDutch $dayOfMonth $monthNameDutch";
}

    /**
     * Check if string is an email and convert it to mailto-href.
     */
    private function change_email_to_mailto(string $value): string
    {
        //check if the value is an email.
        if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
            //create the mailto-href
            return '<a href="mailto:' . $value . '">' . htmlspecialchars($value) . '</a>';
        } else {
            //not an email, so return the original value.
            return $value;
        }
    }
        /**
     * Returns the contact information of the person with the given criteria.
     * @return string the contact information
     */
    public function get_contact_info_generic($filter_expression)
    {

      $filtered_rows=$this->filter_rows($this->filter_conditions);
      $output=[];
      foreach ($this->rows_to_print as $first_row) {
        foreach ($this->columns_to_print as $column_name => $expression) {
                      // Get the value of the cell
                      $cell_value = $this->get_column_value($first_row, $expression);
                      // If we find a non-empty cell, this column is not empty
                      if (empty($cell_value)) {
                          continue; // No need to check other rows for this column
                      }
                      $output[] = $cell_value;
              }
      }
      if (count($output) > 1) {
        $recipient_email = $output[1];
        $recipient_email = explode("\"",explode("mailto:",$recipient_email)[1])[0];
        $recipient_email_id = str_replace(".","-",str_replace("@","-",$recipient_email));
           // HTML structure for a cartouche-style button that triggers JavaScript
            $button_text = "Mail: " . $output[0];

            // Gebruik data-* attributen om de nodige info mee te geven aan JavaScript

            $html_output = "<span id=\"".$recipient_email_id ."\"></span><span class=\"kcm-cartouche-button-wrapper\">";
            $html_output .= "<a class=\"kcm-cartouche-button kcm-contact-trigger\" href=\"#\" "; // Class om JS aan te binden
            $html_output .= "data-recipient-name=\"" . $output[0] . "\" ";
            $html_output .= "data-recipient-email=\"" . $recipient_email . "\" "; // BELANGRIJK: e-mail van ontvanger
            // Add attributes if you need them in the form
            $html_output .= ">";
            $html_output .= "<span class=\"kcm-cartouche-button-text\">" . $button_text . "</span>";
            $html_output .= "</a>";
            $html_output .= "</span>";
            return $html_output;
      }
    }

    /**
     * Returns the contact information of the person with the given criteria.
     * @return string the contact information
     */
    public function get_contact_info()
    {
         $filtered_rows=$this->filter_rows($this->filter_conditions);
         if (count($filtered_rows) > 0) {
            $first_row = $filtered_rows[0]; //get the first row.
            $output_values = [];
            error_log("start");
            foreach ($this->columns_to_print as $column_name => $expression) {
                    // Get the value of the cell
                    $cell_value = $this->get_column_value($first_row, $expression);
                    error_log($cell_value);
                    // If we find a non-empty cell, this column is not empty
                    if (empty($cell_value)) {
                        continue; // No need to check other rows for this column
                    }
                    $output_values[] = $cell_value;
                    error_log($cell_value);
            }
            $email = "";
            if (count($output_values) > 1){
                $email = " ($output_values[1])";
            }

            if (count($output_values) > 2){
                $email = "$email $output_values[2]";
            }
            $html="$output_values[0]$email";

            return '<span class="person-contact-info">' . $html . '</span>';
         } else {
            return "";
         }
    }
    /**
     * Returns the contact information of the organisation with the given criteria.
     * @return string the contact information
     */
    public function get_contacten_info()
    {
         $filtered_rows=$this->filter_rows($this->filter_conditions);
         if (count($filtered_rows) > 0) {
            $first_row = $filtered_rows[0]; //get the first row.
            $output_values = [];
            error_log("start");
            foreach ($this->columns_to_print as $column_name => $expression) {
                    // Get the value of the cell
                    $cell_value = $this->get_column_value($first_row, $expression);
                    error_log($cell_value);
                    // If we find a non-empty cell, this column is not empty
                    if (empty($cell_value)) {
                        continue; // No need to check other rows for this column
                    }
                    $output_values[] = $cell_value;
                    error_log($cell_value);
            }
            $webpage = "";
            if (count($output_values) > 1){
                $webpage = " (<a href=\"$output_values[1]\">$output_values[1]</a>)";
            }


            $html="<a href=\"gerelateerde-organisaties#$output_values[2]\">$output_values[0]</a>$webpage";

            return '<span class="person-contact-info">' . $html . '</span>';
         } else {
            return "";
         }
    }


    //logical expression evaluation
    /**
     * Replaces complex terms in an expression with unique placeholders and stores the terms in the term map.
     *
     * @param array $complex_terms The array of complex terms to replace.
     * @param string $expression The expression in which to perform the replacements.
     * @param int $term_map_counter A counter to ensure unique placeholders.
     * @return string The modified expression with terms replaced by placeholders.
     */
    private function replace_with_placeholders(array $complex_terms, string $expression, int &$term_map_counter): string
    {
        foreach ($complex_terms as $term) {
            $placeholder = "xxterm{$this->numberToLetter($term_map_counter)}xx";
            $this->term_map[$placeholder] = $term;
            $expression = str_replace($term, $placeholder, $expression);
            $term_map_counter++;
        }
        return $expression;
    }


    /**
     * Extracts and replaces complex terms (function calls and string concatenations) from an expression.
     *
     * This function has two main phases:
     * 1. Function Call Extraction: It identifies and extracts function calls (e.g., lowercase(voornaam), uppercase(achternaam)).
     *    Nested function calls (e.g., lowercase(uppercase(voornaam))) are supported.
     * 2. String Concatenation Extraction: It identifies and extracts string concatenations (e.g., 'START' + 'END', 'hello' + voornaam).
     *    These can involve string literals, column names, function placeholders (e.g., func_0), and term placeholders (e.g., term_1).
     *
     * Each extracted term is stored in the `$this->term_map` with a unique placeholder (xxtermaaxx, xxtermbbxx, etc.).
     * The original term in the expression is then replaced by its placeholder.
     *
     * @param string $expression The expression to process.
     * @return string The modified expression with complex terms replaced by placeholders.
     */
    private function extract_complex_terms(string $expression): string
    {
        $complex_terms = [];
        $term_map_counter = 1;
        // Phase 1: Extract String Literals
        //find opening and closing ', and replace the spaces in the expression inbetween
        $offset = 0;
        $expression_length = strlen($expression);
        while ($offset < $expression_length) {
            $start_quote_pos = strpos($expression, "'", $offset);

            if ($start_quote_pos === false) {
                break; // No more opening quotes found
            }

            $end_quote_pos = strpos($expression, "'", $start_quote_pos + 1);
            if ($end_quote_pos === false) {
                break; // Unclosed string literal
            }
            //replace spaces in string literal with placeholder
            $string_literal = substr($expression, $start_quote_pos, $end_quote_pos - $start_quote_pos + 1);
            $string_literal_replace = $string_literal;
            foreach ($this->term_tokens as $key=>$value) {
                $string_literal_replace = str_replace($value, $key, $string_literal_replace);
            }
            //echo "replace $string_literal with $string_literal_replace";
            $expression = str_replace($string_literal, $string_literal_replace, $expression);
            $expression_length = strlen($expression);

            $offset = $start_quote_pos + strlen($string_literal_replace) + 1; // Move offset to the character after the closing quote
        }

        $complex_terms = []; //reset the list
         // Phase 2: Function Call Extraction
        // Regex to find the start of function calls with nested parentheses (e.g., lowercase(uppercase(voornaam)))
        $pattern = '/\w+\(/'; // Matches a word followed by an opening parenthesis.
        $offset = 0; // Keeps track of the current search position in the string.

        // Loop through the expression to find all function calls.
        while (preg_match($pattern, $expression, $matches, PREG_OFFSET_CAPTURE, $offset)) {
            $function_start = $matches[0][1]; // The starting position of the matched function name.
            $open_count = 1; // Counts the number of open parentheses.
            $close_count = 0; // Counts the number of closed parentheses.
            $end = $function_start + strlen($matches[0][0]); // The current end position (starts at the end of the function name).

            // Search for the closing parenthesis, correctly handling nested parentheses.
            while ($open_count > $close_count && $end < strlen($expression)) {
                if ($expression[$end] === '(') {
                    $open_count++; // Increment when an open parenthesis is found.
                } elseif ($expression[$end] === ')') {
                    $close_count++; // Increment when a closed parenthesis is found.
                }
                $end++; // Move the end position to the next character.
            }

            // Extract the full function call (including arguments and nested parentheses).
            $complex_terms[] = substr($expression, $function_start, $end - $function_start);
            $offset = $end; // Move the search position beyond the extracted function call.
        }

        // Replace function calls with placeholders in the term_map
         // Replace string concatenations with placeholders in the term_map
        $expression = $this->replace_with_placeholders($complex_terms, $expression, $term_map_counter);

        // Phase 3: String Concatenation Extraction
        $complex_terms = []; //reset the list.

        //remove all spaces before and after +
        foreach ([" +", "+ "] as $to_find){
            while (str_contains($expression, $to_find)){
                $expression = str_replace($to_find, "+",$expression);
            }
        }

        // Regex to find string concatenations (e.g., 'START' + 'END', 'hello' + voornaam, 'e' + func_0, term_0 + 'abc').
        // Regex to find one or more concatenated elements
        $regex = "/(?:'[^']*'|[\w-]+|func_\d+|term_\d+)(?:\s*\+\s*(?:'[^']*'|[\w-]+|func_\d+|term_\d+))+/";

        $offset = 0;
        while (preg_match($regex, $expression, $matches, PREG_OFFSET_CAPTURE, $offset)) {
            $match = $matches[0][0];
            $complex_terms[] = $match;
            $offset = $matches[0][1] + strlen($match);
        }

        // Replace string concatenations with placeholders in the term_map
        $expression = $this->replace_with_placeholders($complex_terms, $expression, $term_map_counter);

        return $expression; // Return the modified expression.
    }

    /**
     * Converts a number to a lowercase letter sequence (e.g., 1 -> a, 2 -> b, ..., 26 -> z, 27 -> aa, 28 -> ab).
     *
     * @param int $number The number to convert.
     * @return string The corresponding lowercase letter sequence.
     */
    private function numberToLetter(int $number): string
    {
        $letters = '';
        while ($number > 0) {
            $code = ($number - 1) % 26; // Calculate the remainder (0-25).
            $letters = chr(65 + $code) . $letters; // Convert the remainder to an uppercase letter (A-Z) and prepend it.
            $number = (int)(($number - $code) / 26); // Update the number for the next iteration.
        }
        return strtolower($letters); // Convert the letter sequence to lowercase.
    }

    /**
     * Preprocesses the given expression by extracting and replacing complex terms.
     *
     * This method is a wrapper for the extract_complex_terms method.
     * It initializes the term_map and then calls extract_complex_terms to perform the main work.
     *
     * @param string $expression The expression to preprocess.
     * @return string The preprocessed expression.
     */
    private function preprocess_expression(string $expression): string
    {
        //echo "preprocess_expression: $expression \n";
        $this->term_map = []; // Initialize the term_map (clear any previous content).
        $expression = $this->extract_complex_terms($expression); // Extract and replace complex terms in the expression.

        return $expression; // Return the modified expression.
    }

    /**
     * @param string $expression
     * @return array
     */
    private function tokenize(string $expression): array
    {
        $tokens = preg_split(
            '/\s*([\'=()]|<=|>=|<>|<|>|(?<=\w)(?=[()])|(?<=[()])(?=\w)|(?<=\))(?=\s)|(?<=\s)(?=\())|\s+/',
            $expression,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        );

        $new_tokens = [];
        $open_quote = false;
        $temp_token = [];
        foreach($tokens as $token){
            if($token == "'"){
                if(!$open_quote){
                    $open_quote = true;
                    array_push($temp_token, $token);
                } else {
                    $open_quote = false;
                    array_push($temp_token, $token);
                    array_push($new_tokens, implode("", $temp_token));
                    $temp_token = [];
                }
            } else {
                if($open_quote){
                    array_push($temp_token, $token);
                } else {
                    array_push($new_tokens, $token);
                }
            }
        }
        return $new_tokens;
    }

    /**
     * @param array $tokens
     * @return array|string
     */
    private function parse_expression($tokens)
    {
        //echo "parse_expression: " . print_r($tokens, true) . "\n";
        if (count($tokens) == 1) {
            return $this->get_value($tokens[0]);
        }

        // Check if it's a function
        //this part is obsolete and can be removed, because function parsing is done now in the pre-processor
        if (count($tokens) > 2 && $tokens[1] == '(' && end($tokens) == ')') {
            $function_name = $tokens[0];
            $arguments = array_slice($tokens, 2, -1);
            $argument = $this->parse_expression($arguments);
            $result = $this->call_function($function_name, $argument);
            return $result;
        }
        //end obsolete part

        $and_pos = array_search('and', $tokens);
        if ($and_pos !== false) {
            $left = array_slice($tokens, 0, $and_pos);
            $right = array_slice($tokens, $and_pos + 1);
            return [
                'operator' => 'and',
                'left' => $this->parse_expression($left),
                'right' => $this->parse_expression($right),
            ];
        }

        $or_pos = array_search('or', $tokens);
        if ($or_pos !== false) {
            $left = array_slice($tokens, 0, $or_pos);
            $right = array_slice($tokens, $or_pos + 1);
            return [
                'operator' => 'or',
                'left' => $this->parse_expression($left),
                'right' => $this->parse_expression($right),
            ];
        }
        $row_values = [];
        foreach ($tokens as $token) {
            $row_values[] = $this->get_value($token);
        }
        return $row_values;
    }

    /**
     * @param array $tokens
     * @return array|bool
     */
    private function parse_filter_expression($tokens)
    {
        // Handle parentheses first
        $open_paren_pos = array_search('(', $tokens);
        $close_paren_pos = array_search(')', $tokens);

        if ($open_paren_pos !== false && $close_paren_pos !== false) {
            $sub_expression = array_slice($tokens, $open_paren_pos + 1, $close_paren_pos - $open_paren_pos - 1);
            $sub_expression_result = $this->parse_filter_expression($sub_expression);
            // Replace the sub-expression with its result in the token list
            $tokens = array_merge(array_slice($tokens, 0, $open_paren_pos), [$sub_expression_result], array_slice($tokens, $close_paren_pos + 1));
            return $this->parse_filter_expression($tokens);
        }

        // Handle 'or'
        $or_pos = array_search('or', $tokens);
        if ($or_pos !== false) {
            $left = array_slice($tokens, 0, $or_pos);
            $right = array_slice($tokens, $or_pos + 1);
            return [
                'operator' => 'or',
                'left' => $this->parse_filter_expression($left),
                'right' => $this->parse_filter_expression($right)
            ];
        }

        // Handle 'and'
        $and_pos = array_search('and', $tokens);
        if ($and_pos !== false) {
            $left = array_slice($tokens, 0, $and_pos);
            $right = array_slice($tokens, $and_pos + 1);
            return [
                'operator' => 'and',
                'left' => $this->parse_filter_expression($left),
                'right' => $this->parse_filter_expression($right)
            ];
        }
        // Handle 'in'
        $in_pos = array_search('in', $tokens);
        if ($in_pos !== false) {
            $left = array_slice($tokens, 0, $in_pos);
            $right = array_slice($tokens, $in_pos + 1);
            return [
                'operator' => 'in',
                'left' => $this->parse_expression($left),
                'right' => $this->parse_expression($right)
            ];
        }

        // Handle '='
        $eq_pos = array_search('=', $tokens);
        if ($eq_pos !== false) {
            $left = array_slice($tokens, 0, $eq_pos);
            $right = array_slice($tokens, $eq_pos + 1);
            return [
                'operator' => '=',
                'left' => $this->parse_expression($left),
                'right' => $this->parse_expression($right)
            ];
        }
       // Handle '>'
       $eq_pos = array_search('>', $tokens);
       if ($eq_pos !== false) {
           $left = array_slice($tokens, 0, $eq_pos);
           $right = array_slice($tokens, $eq_pos + 1);
           return [
               'operator' => '>',
               'left' => $this->parse_expression($left),
               'right' => $this->parse_expression($right)
           ];
       }
       // Handle '<'
       $eq_pos = array_search('<', $tokens);
       if ($eq_pos !== false) {
           $left = array_slice($tokens, 0, $eq_pos);
           $right = array_slice($tokens, $eq_pos + 1);
           return [
               'operator' => '<',
               'left' => $this->parse_expression($left),
               'right' => $this->parse_expression($right)
           ];
       }
      // Handle '<='
      $eq_pos = array_search('<=', $tokens);
      if ($eq_pos !== false) {
          $left = array_slice($tokens, 0, $eq_pos);
          $right = array_slice($tokens, $eq_pos + 1);
          return [
              'operator' => '<=',
              'left' => $this->parse_expression($left),
              'right' => $this->parse_expression($right)
          ];
      }
      // Handle '>='
      $eq_pos = array_search('>=', $tokens);
      if ($eq_pos !== false) {
          $left = array_slice($tokens, 0, $eq_pos);
          $right = array_slice($tokens, $eq_pos + 1);
          return [
              'operator' => '>=',
              'left' => $this->parse_expression($left),
              'right' => $this->parse_expression($right)
          ];
      }

        // If no operators are found, it must be a simple value
        return $this->parse_expression($tokens);
    }
    /**
     * @param array|bool $parsed_expression
     * @return bool
     */
    private function evaluate($parsed_expression): bool
    {
        //echo "evaluate: " . print_r($parsed_expression, true) . "\n";
        if (is_bool($parsed_expression)) {
            //echo "evaluate: is_bool true\n";
            return $parsed_expression;
        }

        if (!is_array($parsed_expression)) {
            //echo "evaluate: is_array false\n";
            return false; // Invalid expression
        }

        // Handle operators
        $operator = $parsed_expression['operator'];
        switch ($operator) {
            case 'in':
                //echo "evaluate: in-operator\n";
                $left = $this->get_value($parsed_expression['left']);
                $right = $this->get_value($parsed_expression['right']);
                if (is_array($right)){
                    //echo print_r($right);
                    //echo "error right!";
                    //echo print_r($this->term_map, true);
                    $right = "";
                }
                if (is_array($left)){
                    //echo print_r($left);
                    //echo "error left!";
                    //echo print_r($this->term_map, true);
                    $left = "";
                }
                try{
                    $result = str_contains($right, $left);
                }catch(Exception $e){ echo "error-in: $left:$right";}
                //echo "evaluate $left in $right: result: $result\n";
                return $result;
            case '=':
                //echo "evaluate: =-operator\n";
                $left = $this->get_value($parsed_expression['left']);
                $right = $this->get_value($parsed_expression['right']);

                $result = $left == $right;
                //echo "evaluate $left = $right: result: $result\n";
                return $result;
            case '>':
                //echo "evaluate: >-operator\n";
                $left = $this->get_value($parsed_expression['left']);
                $right = $this->get_value($parsed_expression['right']);

                $result = $left > $right;
                //echo "evaluate $left >= $right: result: $result\n";
                return $result;
            case '<':
                //echo "evaluate: <-operator\n";
                $left = $this->get_value($parsed_expression['left']);
                $right = $this->get_value($parsed_expression['right']);

                $result = $left < $right;
                //echo "evaluate $left < $right: result: $result\n";
                return $result;
            case '<=':
                //echo "evaluate: <=-operator\n";
                $left = $this->get_value($parsed_expression['left']);
                $right = $this->get_value($parsed_expression['right']);

                $result = $left <= $right;
                //echo "evaluate $left <= $right: result: $result\n";
                return $result;
            case '>=':
                //echo "evaluate: >=-operator\n";
                $left = $this->get_value($parsed_expression['left']);
                $right = $this->get_value($parsed_expression['right']);

                $result = $left >= $right;
                //echo "evaluate $left >= $right: result: $result\n";
                return $result;
            case 'and':
                //echo "evaluate: and-operator\n";

                $left =  $this->evaluate($parsed_expression['left']);

                $right = $this->evaluate($parsed_expression['right']);
                $result = $left && $right;
                $left_bool = $left?"true":"false";
                $right_bool = $right?"true":"false";
               //echo "evaluate: result: $left_bool && $right_bool : $result \n";
                return $left && $right;
            case 'or':
                //echo "evaluate: or-operator\n";

                $left = $this->evaluate($parsed_expression['left']);
                $right = $this->evaluate($parsed_expression['right']);
                $result = $left || $right;
                $left_bool = $left?"true":"false";
                $right_bool = $right?"true":"false";
                //echo "evaluate: result: $left_bool || $right_bool : $result\n";
                return $left || $right;
            default:
                //echo "evaluate: default\n";
                return false; // Unknown operator
        }
    }
    /**
     * @param $token
     * @return string
     */
    private function get_value($token)
    {
        //echo "get_value: " . $token . "\n";

        if(is_array($token)){
            //echo "get_value: array\n";
            return $token;
        }
        if (array_key_exists($token, $this->term_map)) {
            //replace entire expression with term_map-value
            $token = $this->term_map[$token];
            foreach ($this->term_map as $key=>$value){
                //check if function-key(s) must be replaced
                if (strpos($token, $key) !== false){
                    $token = str_replace($key, $value, $token);
                }
            }
            return $this->get_column_value($this->row, $token);
        }

        //add spaces that were replaced with placeholder
        foreach ($this->term_tokens as $key=>$value) {
            $token = str_replace($key, $value, $token);
        }

        if (str_contains($token, "'")) {
            return str_replace("'", "", $token);
        }

        if (str_contains($token, "\"")) {
            return str_replace("\"", "", $token);
        }

        if (in_array($token, array_keys($this->column_indexes))) {

            $value = $this->column_indexes[$token];
            $result = "";
            $value = $this->get_column_value($this->row, $token);
            if (!$value){
                $value = $token;
            }
            $result = $value;
            //echo "field value: " . $token . "\n";
            return $result;
        }

        return $token;
    }
    /**
     * @param $function_name
     * @param $argument
     * @return mixed|string
     */
    private function call_function($function_name, $argument)
    {
        //echo "call_function: $function_name, $argument\n";
        if (array_key_exists($function_name, $this->function_dictionary)) {
            return $this->function_dictionary[$function_name]["callback"]($argument);
        } else {
            return "Function $function_name not found";
        }
    }
    /**
     * @param array $row
     * @param $filter_expression
     * @return bool
     */
    public function filter_row(array $row, $filter_expression): bool
    {
        $this->row = $row;
        $filter_expression = str_replace("&gt", ">", str_replace("&lt", "<", $filter_expression));
        //echo $filter_expression;
        $filter_expression = $this->preprocess_expression($filter_expression);
        //echo print_r($this->term_map, true);
        //echo $filter_expression;
        $tokens = $this->tokenize($filter_expression);
        $parsed_expression = $this->parse_filter_expression($tokens);
        //echo "evaluate: " . print_r($parsed_expression, true) . "\n";
        return $this->evaluate($parsed_expression);
    }

    //end logical expression evaluation

}

?>

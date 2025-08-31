<?php
/**
 * Class TablePressQuery
 *
 * Handles querying, filtering, formatting, and displaying data from TablePress tables.
 * This class is the core engine behind the [tablepress-query] shortcode,
 * allowing for complex data manipulation and presentation.
 *
 * It supports:
 * - Fetching TablePress table data by table name.
 * - Parsing column selection strings, including functions and expressions.
 * - Filtering rows based on specified conditions (TBD).
 * - Applying custom column names.
 * - Sorting the resulting data.
 * - Formatting output in different styles (e.g., 'table', 'card').
 * - Providing help documentation for the shortcode.
 */
class TablePressQuery
{
    // --- Constants for replacing special characters in expressions to avoid parsing conflicts ---

    /** @var string Placeholder for spaces within quoted strings or function arguments. */
    const SPACE_PLACEHOLDER = "ssxxaxxss";
    /** @var string Placeholder for equals signs within quoted strings or function arguments. */
    const EQUAL_PLACEHOLDER = "ssxxbxxss";
    /** @var string Placeholder for opening parentheses within quoted strings or function arguments. */
    const OPEN_PAREN_PLACEHOLDER = "ssxxcxxss";
    /** @var string Placeholder for closing parentheses within quoted strings or function arguments. */
    const CLOSE_PAREN_PLACEHOLDER = "ssxxdxxss";
    /** @var string Placeholder for less-than signs within quoted strings or function arguments. */
    const SMALLER_PLACEHOLDER = "ssxxexxss";
    /** @var string Placeholder for greater-than signs within quoted strings or function arguments. */
    const BIGGER_PLACEHOLDER = "ssxxfxxss";
    /** @var string Placeholder for less-than-or-equal signs within quoted strings or function arguments. */
    const SMALLER_EQUAL_PLACEHOLDER = "ssxxgxxss";
    /** @var string Placeholder for greater-than-or-equal signs within quoted strings or function arguments. */
    const BIGGER_EQUAL_PLACEHOLDER = "ssxxhxxss";
    /** @var string Placeholder for not-equal signs within quoted strings or function arguments. */
    const UNEQUAL_PLACEHOLDER = "ssxxixxss";
    /** @var string Placeholder for double quotes within quoted strings. */
    const DOUBLE_QUOTE_PLACEHOLDER = "ssxxjxxss";
    //const SINGLE_QUOTE_PLACEHOLDER = "ssxxkxxss"; // Placeholder for single quotes (currently commented out)

    // --- Properties ---

    /** @var int|null The ID of the TablePress table being queried. */
    private $table_id;
    /** @var array|null The raw data fetched from the TablePress table. Typically an array of rows, where each row is an array of cell values. */
    private $table_data;
    /** @var array|null Associative array mapping column names (headers) to their zero-based index in the original table. */
    public $column_indexes;
    /** @var array|null Parsed representation of the columns to be displayed, including any functions or expressions. */
    private $columns_to_print;
    /** @var array|null The subset of table rows that match the filter conditions and are selected for display. */
    private $rows_to_print;
    /** @var string|null The original title (name) of the TablePress table. */
    private $table_title;
    /** @var array|null Custom column names (headers) to be used for the output. */
    private $column_names;
    /** @var mixed|null Parsed filter conditions used to select rows. Structure depends on parsing logic (TBD). */
    private $filter_conditions;
    /** @var string|null Stores any error message encountered during processing. */
    public $error;
    /** @var array|null Tracks columns that are entirely empty across all rows to print, potentially for layout adjustments. */
    private $empty_columns;
    /** @var string|null Custom title for the query result, provided via the shortcode. */
    private $title;
    /** @var string|null The desired output format (e.g., 'table', 'card'). */
    private $format;
    /** @var string|null Custom CSS class(es) to apply to the output wrapper. */
    private $css_class;
    /** @var string|null The sort condition string (e.g., "column_name asc"). */
    private $sort_condition;
    /** @var array Stores information about PDF files related to the query, if applicable (purpose TBD). */
    private $pdf_files; // Consider adding type hint if structure is known, e.g., array<int, array<string, string>>
    /** @var string Description for download links, if applicable (purpose TBD). */
    private $download_description;
    /** @var mixed|null Stores the row closest to a certain condition, if applicable (purpose TBD based on parse_select_expression). */
    private $closest_row; // Consider adding type hint if structure is known, e.g., array
    /** @var array A map used internally for term replacement during parsing (placeholders to actual characters). */
    private $term_map = []; // Should be initialized in constructor, seems it is but the type hint is general here.
    /** @var array Dictionary of available functions (name => [callback, description]) that can be used in the 'columns' attribute. */
    private $function_dictionary;
    /** @var array|null Mapping of term placeholders to their original characters (e.g., SPACE_PLACEHOLDER => ' '). */
    private $term_tokens;
    /** @var array|null Represents the current row being processed within certain methods (e.g., during expression evaluation). */
    private $row; // Consider adding type hint if structure is known

    /**
     * Constructor for TablePressQuery.
     *
     * Initializes the query object, fetches table data, and processes
     * the various parameters provided through shortcode attributes.
     *
     * @param string|null $tablename The name (title) of the TablePress table to query.
     * @param string|null $columns_str The comma-separated string defining columns to display, including functions/expressions.
     * @param string|null $filter_str The string defining filter conditions (syntax TBD).
     * @param string|null $column_names_str The comma-separated string for custom output column headers.
     * @param string|null $title_query A custom title for the displayed query result.
     * @param string|null $sort The sort condition string (e.g., "column_name asc").
     * @param string|null $new_css_class Custom CSS class(es) for the output.
     * @param string|null $format The desired output format (e.g., "card", "table").
     * @param string|null $select_expression An expression for more advanced row selection logic (purpose TBD).
     */
    public function __construct(string $tablename = null, string $columns_str = null,
    string $filter_str = null, string $column_names_str = null, string $title_query = null,
    string $sort = null, string $new_css_class = null, string $format = null, string $select_expression = null)
    {
        // Initialize the mapping of placeholders back to their original characters.
        // This is used for safely handling special characters within user-provided expressions.
        $this->term_tokens = [
            self::SPACE_PLACEHOLDER => ' ',
            self::EQUAL_PLACEHOLDER => '=',
            self::OPEN_PAREN_PLACEHOLDER => '(',
            self::CLOSE_PAREN_PLACEHOLDER => ')',
            self::SMALLER_PLACEHOLDER => '<',
            self::BIGGER_PLACEHOLDER => '>',
            self::SMALLER_EQUAL_PLACEHOLDER => '<=',
            self::BIGGER_EQUAL_PLACEHOLDER => '>=',
            self::UNEQUAL_PLACEHOLDER => '<>', // Standard SQL not-equal operator
            self::DOUBLE_QUOTE_PLACEHOLDER => '"',
            //self::SINGLE_QUOTE_PLACEHOLDER => "'", // Single quote placeholder (currently disabled)
        ];

       // Initialize the dictionary of available functions that can be applied to columns.
       // Each function has a 'callback' (the method to execute) and a 'description' for help text
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
     public function get_table_data(int $table_id): ?array
    {
        // Get the table post
        $table_post = get_post($table_id);

        // Check if the table post exists and is of the correct type
        if (!$table_post || $table_post->post_type !== 'tablepress_table') {
            error_log("TablePressQuery: Table with id " . $table_id . " not found or not a TablePress table.");
            return null;
        }

        // Get the table data from post content.
        $table_data_json = $table_post->post_content;

        // Check if data is present
        if (empty($table_data_json)) {
            error_log("TablePressQuery: Missing table data for table id " . $table_id . ".");
            return null;
        }

        // Decode the JSON string to an array.
        // TablePress' post_content is meestal al een JSON array, dus zonder 'true' voor assoc is prima.
        $raw_table_data = json_decode($table_data_json);

        // Check for JSON decoding errors
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("TablePressQuery: Error json-decoding table data for table id " . $table_id . ". Error: " . json_last_error_msg());
            return null;
        }

        // Ensure the decoded data is an array (array of rows)
        if (!is_array($raw_table_data)) {
            error_log("TablePressQuery: Table data for table id " . $table_id . " is not an array after JSON decoding.");
            return null;
        }

        // --- Filter Runner via WordPress Hooks ---
        // Haal de lijst van filter-callbacks op via een WordPress filter hook.
        // De tweede parameter van apply_filters is een array van standaard filters.
        $filter_callbacks = apply_filters('tpq_cell_data_filters', $this->get_default_cell_data_filters(), $table_id, $this);
                                        // ^ Hook naam          ^ Standaard filters      ^ Extra args voor de hook

        $processed_table_data = [];
        foreach ($raw_table_data as $row_index => $row_data) {
            // Check of de rij zelf wel een array is (array van cellen)
            if (!is_array($row_data)) {
                // Optie: log een waarschuwing en sla de rij over, of behoud de niet-array rij
                error_log("TablePressQuery: Row $row_index in table $table_id is not an array. Skipping filtering for this row.");
                $processed_table_data[$row_index] = $row_data; // Behoud de originele niet-array rij
                continue;
            }

            $processed_row = [];
            foreach ($row_data as $cell_index => $cell_value) {
                $filtered_cell_value = $cell_value; // Start met de originele celwaarde

                if (!empty($filter_callbacks) && is_array($filter_callbacks)) {
                    foreach ($filter_callbacks as $filter_callback) {
                        if (is_callable($filter_callback)) {
                            // Geef de $filtered_cell_value door, zodat filters geketend kunnen worden.
                            // Geef ook de originele celwaarde, context, etc. mee als dat nuttig is voor filters.
                            $filtered_cell_value = call_user_func($filter_callback, $filtered_cell_value, $cell_value, $row_index, $cell_index, $table_id, $this);
                        } else {
                            error_log("TablePressQuery: A registered cell data filter is not callable: " . print_r($filter_callback, true));
                        }
                    }
                }
                $processed_row[$cell_index] = $filtered_cell_value;
            }
            $processed_table_data[$row_index] = $processed_row;
        }
        // --- Einde Filter Runner ---

        return $processed_table_data;
    }

    /**
     * Returns an array of default filter callbacks for cell data.
     * These are used if no filters are added/modified via the 'tpq_cell_data_filters' hook.
     *
     * @return array An array of callable filter functions.
     */
    protected function get_default_cell_data_filters(): array
    {
        return [
            // Voeg hier methodenamen van deze class toe, of globale functienamen
            // die je als standaard filters wilt toepassen.
            [$this, 'filter_br_to_newline_for_textarea'],
            // Voorbeeld: 'some_global_utility_trim_function',
            // Zorg ervoor dat deze methoden/functies bestaan en de juiste parameters accepteren.
            // De parameters die ze ontvangen zijn: $current_filtered_value, $original_cell_value, $row_idx, $cell_idx, $table_id, $query_object

            //[$this, 'filter_backtick_to_apostrophe'], // Vervangt ` door '
        ];
    }

    /**
     * Example Filter: Converts <br> tags to newline characters (\n).
     */
    public function filter_br_to_newline_for_textarea($filtered_value, $original_value = null, $row_index = null, $cell_index = null, $table_id = null, $query_object = null)
    {
        if (!is_string($filtered_value)) {
            return $filtered_value;
        }
        return preg_replace('#<br\s*/?>#i', "\n", $filtered_value);
    }

/**
     * Filter: Converts backticks (`) to apostrophes (').
     *
     * @param mixed $filtered_value The current value after previous filters.
     * @param mixed $original_value The original cell value (niet gebruikt in dit simpele filter).
     * @param int   $row_index      The index of the current row (niet gebruikt).
     * @param int   $cell_index     The index of the current cell (niet gebruikt).
     * @param int   $table_id       The ID of the table (niet gebruikt).
     * @param self  $query_object   The TablePressQuery object instance (niet gebruikt).
     * @return mixed The processed string, or original data if not a string.
     */
    public function filter_backtick_to_apostrophe($filtered_value, $original_value = null, $row_index = null, $cell_index = null, $table_id = null, $query_object = null)
    {
        // $filtered_value is de waarde die je moet aanpassen.
        if (!is_string($filtered_value)) {
            return $filtered_value;
        }
        return str_replace('`', "'", $filtered_value);
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
                        $this->pdf_files = tpq_get_pdf_list($pdf_dir);
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

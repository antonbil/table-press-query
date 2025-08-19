# TablePress Query

**Author:** [Anton Bil](https://familiebil.nl/anton)
**Tags:** tablepress, query, shortcode, filter, display, contact, email
**Requires at least:** 6.8.0
**Tested up to:** (WordPress versie waarmee je getest hebt, bijv. 6.5)
**Requires PHP:** 8.0
**Stable tag:** 1.0.0
**License:** GPLv2 or later
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html
**Plugin URI:** https://your-plugin-website.com/
**Requires Plugins:** tablepress

Extends TablePress by allowing advanced queries on its tables and displaying results via shortcodes. Enables seamless integration of filtered, customized TablePress table content, and contact forms derived from table data into WordPress pages and posts.

## Description

TablePress Query enhances the functionality of the popular TablePress plugin by providing powerful new ways to interact with and display your table data. This plugin introduces two main shortcodes:

1.  `[tablepress-query]`: Allows you to perform complex queries on your TablePress tables and display the filtered results directly within your WordPress pages or posts. You can specify which columns to show, how to filter rows, and how to order the data, offering a highly flexible way to present specific views of your tables.
2.  `[tablepress-generic-contact]`: Enables you to extract contact information (like name and email) from a TablePress table based on specified criteria. It then displays this information and provides a clickable link or button that opens a dynamic contact form, allowing website visitors to send an email directly to the contact person listed in that table row.

This plugin is ideal for users who need to:
*   Display subsets of large TablePress tables.
*   Create customized views of table data without duplicating tables.
*   Integrate dynamic contact lists from TablePress tables with direct email functionality.

## Installation

1.  **Prerequisites:** Ensure you have the [TablePress plugin](https://wordpress.org/plugins/tablepress/) installed and activated, as this plugin extends its functionality.
2.  Upload the `tablepress-query` folder to the `/wp-content/plugins/` directory.
3.  Activate the plugin through the 'Plugins' menu in WordPress.
4.  (Optional) Place your translation `.mo` files in the `/wp-content/plugins/tablepress-query/languages/` directory.

## Usage

### 1. `[tablepress-query]` Shortcode

This shortcode allows you to display a filtered, sorted, and customized version of a TablePress table, with various formatting options and functions to manipulate column data.

**Syntax:**

`[tablepress-query tablename="your-table-name" columns="{column1,column2,function(column3)}" column_names="{Header1,Header2}" filter="your-filter-condition" cssclass="custom-class" format="card" sort="column_to_sort_by asc" title="My Custom Title"]`

**Parameters:**

*   `tablename` (string, **required**): The name (title) of the TablePress table you want to query.
*   `columns` (string, optional): A comma-separated list of column names (as they appear in the TablePress table header), expressions, functions, or constant text to display, enclosed in curly braces.
    *   Examples: `{Name,Email}`, `{h3(ProductName),'Description: '+Description,Price}`
    *   If no columns are specified, all columns from the table will be used.
*   `column_names` (string, optional): A comma-separated list of custom column headers for the output, enclosed in curly braces. The order must correspond to the `columns` attribute. An empty value (e.g., `,,Header3`) will skip setting a custom header for that specific column, potentially falling back to the original TablePress header or an empty header if the column is new/derived.
    *   Example: `{Product,Info,Cost}`
    *   If not provided, the original TablePress table headers will be used where applicable.
*   `filter` (string, optional): A filter condition to select specific rows from the table. *(The exact syntax for filter conditions is To Be Determined (TBD) and will be documented once finalized. It might involve simple `ColumnName:Value` pairs or a more expressive query language.)*
*   `cssclass` (string, optional): A custom CSS class name to be added to the main wrapper of the shortcode output, allowing for custom styling.
*   `format` (string, optional): Specifies the display format for the results.
    *   Supported values: `card`, `table` (and potentially others in the future).
    *   Default format is TBD.
*   `sort` (string, optional): Sorts the data by a specified column and direction.
    *   Format: `'column-name asc'` or `'column-name desc'`.
    *   Example: `sort="ProductName asc"` or `sort="DateAdded desc"`.
*   `title` (string, optional): A custom title to be displayed above the query results.

**Available Functions for the `columns` Parameter:**

These functions can be used within the `columns` attribute to transform the data of a specific column before display. The `column_name` argument within the function should be an actual column name from your TablePress table.

*   `bulleted_list(column_name)`: Converts a string containing newlines (`\n`) or `<br>` tags into an HTML unordered (bulleted) list (`<ul><li>...</li></ul>`).
*   `date_description(column_name)`: Converts a date-id (presumably a specific format your plugin understands) into a more readable date format.
*   `bold(column_name)`: Wraps the column's value in `<strong>` tags to make it bold.
*   `days_plus(column_name)`: Expects `column_name` to contain a number of days. It adds this number of days to the current date and returns the new date in `yymmdd` format.
*   `italics(column_name)`: Wraps the column's value in `<em>` tags to make it italic.
*   `h3(column_name)`: Wraps the column's value in `<h3>` tags.
*   `uppercase(column_name)`: Converts the column's text to uppercase.
*   `lowercase(column_name)`: Converts the column's text to lowercase.
*   `trim(column_name, length)`: Trims the string from `column_name` to a specified `length`.
*   `comma(column_name)`: Returns a literal comma (`,`). *(Usage context for this might need clarification - perhaps for constructing complex strings).*

**Examples:**

1.  **Displaying contact information in a card format:**
`[tablepress-query tablename="contacten" columns="{naam,bijzonderheden,bold(adres),telefoon,email,webpage}" column_names="{,,Adres,Telefoon,Email,}" cssclass="contacten-css-class" format="card" ] `
*This example displays specific columns from the "contacten" table. The 'adres' column will be bolded. Custom column names are provided for 'Adres', 'Telefoon', and 'Email', while the original headers (or no header) will be used for 'naam', 'bijzonderheden', and 'webpage'. The output will use the "card" format and have the CSS class "contacten-css-class".*

2.  **Displaying webmaster procedures in a card format with formatting:**
`[tablepress-query tablename="procedures-webmeester" columns="{h3(naam),frequentie,verantwoordelijke,bold(beschrijving),bulleted_list(procedure)}" column_names="{,frequentie,verantwoordelijke,,}" cssclass="procedures-css-class" format="card" ]`
*This example shows data from "procedures-webmeester". 'naam' is formatted as an H3 heading, 'beschrijving' is bolded, and 'procedure' (which contains multiple steps separated by newlines) is converted into a bulleted list. Custom headers are provided for 'frequentie' and 'verantwoordelijke'.*

### 2. `[tablepress-generic-contact]` Shortcode

This shortcode displays contact information from a TablePress table and provides a link/button to email the contact.

**Basic Usage:**
`[tablepress-generic-contact table_id="your_table_id" name_expression="{FullName}" email_column="EmailAddress" filter_expression="{Department:Support}"]`
`[tablepress-generic-contact table_id="taakgroep" name_expression="Naam:{Voornaam}+' '+{Achternaam}" email_column="Email" filter_expression="{Taakgroep:Communicatie},{Functie:webmeester}" columns_to_print="Voornaam+' '+Achternaam,Email"]`

**Attributes:**

*   `table_id` (string, **required**): The ID of the TablePress table (e.g., "contacts").
*   `filter_expression` (string, optional): Filters to select specific rows from the table. Format: `{ColumnName1:Value1,ColumnName2:Value2}`. Example: `{TaskGroup:Board Members,Function:Chairperson}`.
*   `name_expression` (string, **required**): An expression or column name to construct the display name of the contact.
    *   Simple column: `{ContactName}`
    *   Expression: `{FirstName}+' '+{LastName}` (The parts within `{}` should be actual column names from your TablePress table).
*   `email_column` (string, **required**): The exact name of the column in your TablePress table that contains the email address. Example: `Email`, `WorkEmail`.
*   `phone_column` (string, optional): The exact name of the column for the phone number, if you wish to display it. Example: `PhoneNumber`.
*   `columns_to_print` (string, optional - Advanced): Directly specify the complete "columns to print" string for the underlying query, similar to the `[tablepress-query]` shortcode. If provided, it overrides `name_expression`, `email_column`, and `phone_column`. This gives more control over what data is fetched and potentially displayed before the contact link. Example: `{DisplayName:{NameExpression},ContactEmail:{EmailColumnName},OfficePhone:{PhoneColumn}}`. The system will look for "DisplayName" (or the first part before ':') for the person's name and "ContactEmail" (or the part associated with your email column) for the email address to use in the mailto functionality.
*   `link_text` (string, optional): Custom text for the contact link/button. Default: "Contact [Name]".
*   `show_phone` (boolean, optional): Set to `true` to display the phone number if `phone_column` is provided and data exists. Default: `false` (or based on `phone_column` presence).

**Example:**
To display contact information for "John Doe" from table "5", where "Name" is "John Doe" and "Department" is "Marketing", using the "PrimaryEmail" column for the email:
`[tablepress-generic-contact table_id="5" name_expression="{Name}" email_column="PrimaryEmail" filter_expression="{Name:John Doe,Department:Marketing}"]`

This will output something like:
"John Doe - [Contact John Doe]"
Clicking the link will open an AJAX-powered contact form pre-filled to email John Doe.

## AJAX Contact Form

When a user clicks the contact link generated by `[tablepress-generic-contact]`, a contact form is dynamically loaded via AJAX. This form allows the user to send an email directly to the selected contact person. The form includes:
*   Sender's Name
*   Sender's Email
*   Subject
*   Message
*   Security (Nonce protection)

Emails are sent using the standard `wp_mail()` function. For better deliverability, it's highly recommended to configure an SMTP plugin (e.g., WP Mail SMTP, FluentSMTP) to send emails via a professional email service (like SendGrid, Mailgun, Brevo, etc.) rather than relying on your web server's default mail function.

## Frequently Asked Questions (FAQ)

**Q: Do I need TablePress installed?**
A: Yes, this plugin is an extension for TablePress and requires TablePress to be installed and activated.

**Q: How are the queries performed?**
A: The plugin includes a custom query engine (`TablePressSurvey` class or similar logic) that processes your shortcode attributes to filter and select data directly from the TablePress table structures in the WordPress database.

**Q: Can I style the output?**
A: Yes. The output tables from `[tablepress-query]` and the contact information display can be styled using CSS. You can use the `table_class` attribute in the `[tablepress-query]` shortcode to add custom classes for easier targeting. The contact form elements also have specific CSS classes (e.g., `kcm-contact-form`, `kcm-form-input`).

**Q: What happens if no results are found for my query?**
A: The shortcodes will typically display a message like "No results found" or simply output nothing, depending on the specific implementation.

**Q: Are the contact form submissions secure?**
A: The AJAX contact form uses WordPress nonces to protect against CSRF attacks. All user input is sanitized before processing and before being included in emails.

## Changelog

### 1.0.0 - 2025-08-11
*   Initial release.
*   Introduced `[tablepress-query]` shortcode for displaying filtered TablePress data.
*   Introduced `[tablepress-generic-contact]` shortcode for displaying contact information and an AJAX email form.
*   Basic internationalization support with `.pot` file.

## Contributing

Contributions are welcome! If you have suggestions, bug reports, or want to contribute code, please feel free to:
1.  Open an issue on the GitHub repository (if you create one).
2.  Submit a pull request.

*(Provide a link to your GitHub repository if you have one.)*

---

**Disclaimer:** This plugin interacts with data stored by TablePress. Always ensure you have backups of your WordPress site and database before installing new plugins or making significant changes.

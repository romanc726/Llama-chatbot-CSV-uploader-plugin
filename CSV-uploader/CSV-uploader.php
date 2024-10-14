<?php
/*
Plugin Name: CSV Uploader and Monitor
Description: Uploads CSV files and stores them in the questions table. Monitors answers folder for new files and stores them in the answers table.
Version: 6.3
Author: Roman Cherkasov
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Create the questions and answers tables on plugin activation
function csv_uploader_create_tables() {
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();

    // Questions table with ID, question, and status fields
    $table_name_questions = $wpdb->prefix . 'questions';
    $sql_questions = "CREATE TABLE $table_name_questions (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        question text NOT NULL,
        status varchar(50) NOT NULL,
        date_added datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY (id)
    ) $charset_collate;";

    // Answers table with ID, answer, and status fields
    $table_name_answers = $wpdb->prefix . 'answers';
    $sql_answers = "CREATE TABLE $table_name_answers (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        answer text NOT NULL,
        status varchar(50) NOT NULL,
        date_added datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql_questions);
    dbDelta($sql_answers);
}
register_activation_hook(__FILE__, 'csv_uploader_create_tables');

// Admin menu for CSV Upload
function csv_uploader_menu() {
    add_menu_page('CSV Uploader', 'CSV Uploader', 'manage_options', 'csv-uploader', 'csv_uploader_page');
}
add_action('admin_menu', 'csv_uploader_menu');

// Display the upload form and handle file uploads for questions
function csv_uploader_page() {
    if (isset($_POST['submit']) && !empty($_FILES['csv_file'])) {
        csv_uploader_handle_file_upload($_FILES['csv_file']);
    }

    ?>
    <div class="wrap">
        <h1>Upload CSV File</h1>
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="csv_file" required>
            <input type="submit" name="submit" value="Upload CSV" class="button button-primary">
        </form>
    </div>
    <?php
}

// Handle CSV file upload for questions
function csv_uploader_handle_file_upload($file) {
    if ($file['type'] !== 'text/csv') {
        wp_die('Only CSV files are allowed.');
    }

    // Move uploaded CSV to questions folder
    $upload_dir = WP_CONTENT_DIR . '/questions/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    // Create filename question-000000001.csv format
    $files = glob($upload_dir . 'question-*.csv');
    $next_file_number = count($files) + 1;
    $file_name = sprintf('question-%09d.csv', $next_file_number);

    $file_path = $upload_dir . $file_name;
    move_uploaded_file($file['tmp_name'], $file_path);

    // Store contents of CSV in the questions table
    csv_uploader_store_csv_in_db($file_path, 'questions');
}

// Monitor the answers folder for new CSV files and store them in the answers table
function csv_uploader_monitor_answers_folder() {
    $answer_folder = WP_CONTENT_DIR . '/answers/';

    if (!file_exists($answer_folder)) {
        mkdir($answer_folder, 0755, true);
    }

    // Get list of already processed files
    $processed_files = get_option('csv_uploader_processed_files', []);

    // Fetch files with full path
    $files = glob($answer_folder . 'answer-*.csv');

    foreach ($files as $file) {
        // Log each file being checked
        error_log("Checking file: " . $file);

        if (!in_array($file, $processed_files)) {
            // Log new file being processed
            error_log("Processing new file: " . $file);

            // Process the new file and store in answers table
            $result = csv_uploader_store_csv_in_db($file, 'answers');

            // Log the result of the file processing
            if ($result === true) {
                // Mark file as processed
                $processed_files[] = $file;
            } else {
                error_log("Error processing file: " . $file);
            }
        } else {
            error_log("File already processed: " . $file);
        }
    }

    // Update the processed files list
    update_option('csv_uploader_processed_files', $processed_files);
}

// Store CSV content in the database (with logging)
function csv_uploader_store_csv_in_db($file_path, $table_name) {
    global $wpdb;

    // Log the file path being processed
    error_log("Opening file: " . $file_path);

    if (($handle = fopen($file_path, "r")) !== FALSE) {
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            // Expecting the CSV to have 3 columns: ID, answer, status
            if (count($data) < 3) {
                error_log("Invalid CSV format in file: " . $file_path);
                continue; // Skip rows that don't match the format
            }

            $id = sanitize_text_field($data[0]);
            $content = sanitize_text_field($data[1]); // Can be question or answer
            $status = sanitize_text_field($data[2]);

            // Log the data being inserted
            error_log("Inserting data: ID = $id, Content = $content, Status = $status");

            // Insert into the specified table (questions or answers)
            $wpdb->replace(
                $wpdb->prefix . $table_name,
                array(
                    'id' => $id,
                    $table_name === 'questions' ? 'question' : 'answer' => $content,
                    'status' => $status,
                ),
                array('%d', '%s', '%s')
            );
        }
        fclose($handle);
        return true;
    } else {
        // Log file open failure
        error_log("Failed to open file: " . $file_path);
        return false;
    }
}

// Schedule the folder monitoring to run every minute
if (!wp_next_scheduled('csv_uploader_monitor_answers_event')) {
    wp_schedule_event(time(), 'minute', 'csv_uploader_monitor_answers_event');
}
add_action('csv_uploader_monitor_answers_event', 'csv_uploader_monitor_answers_folder');

// Clear scheduled events on plugin deactivation
function csv_uploader_deactivation() {
    wp_clear_scheduled_hook('csv_uploader_monitor_answers_event');
}
register_deactivation_hook(__FILE__, 'csv_uploader_deactivation');

?>

<?php
/*
Plugin Name: YFP Spam Protection
Version: 1.3.0
Plugin URI: https://github.com/OPunWide/yfp-spam-protection
Description: A plug-in to stop robot-generated spam from posting on a comment.
Author: Paul Blakelock
Author URI: http://SplendidSpider.com
Requires PHP: 5.4
*/

/**
 * This uses just two filter functions on the standard comment form.
 * Use the filter comment_form_default_fields to add three fields to
 * the form if the user is not logged in.
 *
 * Use the filter to verify that those fields were filled in properly,
 * again only if the user is not logged in.
 *
 * The form data that the user enters is not saved and does not modify the
 * database. It is just a filter that requires the fields to be filled in.
 *
 * All new fields are required, and have specific input values. Easy for
 * a human, difficult for a bot.
 *
 * All of the other plugin code is to allow the form to be modified by an admin.
 * It allows the standard answers to be changed, in case the spammers
 * ever decide that this is a plugin worth trying to bypass.
 *
 * When the user is logged in, custom_fields are hidden and are not required.
 *
 * The plugin name in the header was previously "yfp-spam-protection".
 */

/**
 * This class is used for all of the interaction with the comment form's filters.
 * It does not handle any of the admin tasks.
 */
class YFP_Spam_Protection
{
    const DEFAULT_PHONE = '555-5555';
    const DEFAULT_TITLE = 'bad';
    const DEFAULT_RATING = '1';

    // ID and name fields, and therefore POST keys. The prefix is to
    //  distinguish IDs from DB keys.
    const FNAME_PHONE = 'in_phone';
    const FNAME_TITLE = 'in_title';
    const FNAME_RATING = 'in_rating';

    // These are used to pick which data to return.
    const TYPE_PHONE = 1;
    const TYPE_TITLE = 2;
    const TYPE_RATING = 3;

    // These are used in wp_options:
    // The key this plugin uses for its options table data.
    const WP_OPTION_KEY = 'yfp_spam_protection';
    // Data is saved using these keys within the one database option variable.
    const WPO_KEY_PHONE = 'ph';
    const WPO_KEY_RATING = 'ra';
    const WPO_KEY_TITLE = 'ti';

    // Text for the forms and error messages.
    const TPL_INPUT_PRE =
            '<p class="%s"><label for="%s">%s <span class="required">*</span></label>';
    const TPL_INPUT_TAG = '<input id="%s" name="%s" type="text" size="30" />';
    const TPL_ERR_PRE_PART = 'Error: Please %s.';
    const TPL_ERR_POST_PART =
            '%s, it is part of the comment spam filter. Hit the BACK button on ' .
            'your browser and resubmit your comment.';

    // The current value of the option.
    private $cur_val_phone;
    private $cur_val_title;
    private $cur_val_rating;

    // Radio buttons are build during construction and saved here.
    private $tplt_rating_stars = '';

    /**
     * If $key exists in $arr and it is a non-empty string return it, else
     * return $default.
     *
     * @param array $arr
     * @param string $key
     * @param string $default
     * @return string
     */
    private function array_val_or_default($arr, $key, $default) {

        $ret = $default;
        if ( is_array($arr) && array_key_exists($key, $arr) ) {

            $val = $arr[$key];
            if (is_string($val) && strlen(trim($val)) > 0) {
                $ret = trim($val);
            }
        }

        return $ret;
    }

    /**
     * Get the expected value for the field for part of a message, adding the
     * expected HTML tags.
     *
     * @param mixed $fieldtype - one of the class' TYPE_ constants
     * @return string
     */
    private function get_expected_value_in_bold( $fieldtype ) {

        return '<b>' . $this->get_expected_value( $fieldtype ) . '</b>';
    }

    /**
     * Get the expected value for the field to verify.
     *
     * @param mixed $fieldtype - one of the class' TYPE_ constants
     * @return string
     */
    private function get_expected_value( $fieldtype ) {

        $text = 'invalid';
        switch ( $fieldtype ) {

            case self::TYPE_PHONE:
                $text = $this->cur_val_phone;
                break;

            case self::TYPE_TITLE:
                $text = $this->cur_val_title;
                break;

            case self::TYPE_RATING:
                $text = $this->cur_val_rating;
                break;
        }

        return $text;
    }


    /**
     * Used once to create most of the HTML for the ratings radio button
     * section.
     *
     * @return string
     */
    private function build_ratings_inputs_html() {

        // Build the rating text, a sequence of radio button inputs.
        $tpl_rating_input =
                '<span class="commentrating"><input type="radio" name="' .
                self::FNAME_RATING . '" value="%s" />%s</span>';
        $parts = [];
        for( $i=1; $i <= 5; $i++ ) {
            $parts[] = sprintf($tpl_rating_input, $i, $i);
        }

        return implode("\n", $parts) . "\n";
    }


    /**
     * Set the initial values for things that cannot be constants.
     *
     */
    public function __construct() {

        // Get options from the WP database.
        $optionsData = get_option(self::WP_OPTION_KEY);
        $this->cur_val_phone = $this->array_val_or_default(
                $optionsData, self::WPO_KEY_PHONE, self::DEFAULT_PHONE);
        $this->cur_val_title = $this->array_val_or_default(
                $optionsData, self::WPO_KEY_TITLE, self::DEFAULT_TITLE);
        $this->cur_val_rating = $this->array_val_or_default(
                $optionsData, self::WPO_KEY_RATING, self::DEFAULT_RATING);
        // Build the rating text, a sequence of radio button inputs.
        $this->tplt_rating_stars = $this->build_ratings_inputs_html();
    }

    /**
     * Verify that the expected value was entered into the field. Uppercase
     * comparison is done because the label that gives the "correct" answer
     * may have been transformed to upper. That means that there would be no
     * way to indicate the correct answer.
     *
     * @param string $fieldtype
     * @param string $val
     * @return type
     */
    public function is_expected_value( $fieldtype, $val ) {

        return strtoupper($this->get_expected_value( $fieldtype )) === strtoupper($val);
    }

    /**
     * If a field had the wrong data, this is called to get a message about that
     * field. It is the part of the failure that is specific to a single field.
     *
     * @param mixed $fieldtype - one of the class' TYPE_ constants
     * @return string
     */
    public function get_verify_failure_message( $fieldtype ) {

        $htmParts = [];
        switch ( $fieldtype ) {

            case self::TYPE_PHONE:
                $htmParts[] = __(sprintf( self::TPL_ERR_PRE_PART, 'enter the Phone Number' ));
                $htmParts[] = __(sprintf( self::TPL_ERR_POST_PART, ' Enter "' .
                        $this->get_expected_value_in_bold(self::TYPE_PHONE) .
                        '" without the quotes' ));
                break;

            case self::TYPE_TITLE:
                $htmParts[] = __(sprintf( self::TPL_ERR_PRE_PART, 'enter the Comment Title' ));
                $htmParts[] = __(sprintf( self::TPL_ERR_POST_PART, ' Enter "' .
                        $this->get_expected_value_in_bold(self::TYPE_TITLE) .
                        '" without the quotes' ));
                break;

            case self::TYPE_RATING:
                $htmParts[] = __(sprintf( self::TPL_ERR_PRE_PART, 'enter the Rating' ));
                $htmParts[] = __(sprintf( self::TPL_ERR_POST_PART, ' It must be rated as a ' .
                        $this->get_expected_value_in_bold(self::TYPE_RATING) ));
                break;
        }

        return implode('', $htmParts) . "\n";
    }

    /**
     * Gets the HTML contents for a field on the comment form.
     * Ignore bad field type values. Should an error be thrown instead?
     *
     * @param mixed $fieldtype - one of the class' TYPE_ constants
     * @return string
     */
    public function get_field_html( $fieldtype ) {

        $parts = [];

        switch ( $fieldtype ) {

            case self::TYPE_PHONE:
                $tlate = __(sprintf( 'Phone (must use number: %s)', $this->cur_val_phone));
                // wrapper class name; for= id name; the html message for the input.
                $parts[] = sprintf( self::TPL_INPUT_PRE, 'comment-form-phone',
                        self::FNAME_PHONE, $tlate ) . "\n";
                // ID; name;
                $parts[] = sprintf( self::TPL_INPUT_TAG, self::FNAME_PHONE, self::FNAME_PHONE );
                $parts[] = '</p>';
                break;

            case self::TYPE_TITLE:
                $tlate = __(sprintf( 'Comment Title (must use text: %s)', $this->cur_val_title));
                $parts[] = sprintf( self::TPL_INPUT_PRE, 'comment-form-title',
                        self::FNAME_TITLE, $tlate ) . "\n";
                $parts[] = sprintf( self::TPL_INPUT_TAG, self::FNAME_TITLE, self::FNAME_TITLE );
                $parts[] = '</p>';
                break;

            case self::TYPE_RATING:
                $tlate = __(sprintf( 'Rating (must select: %s)', $this->cur_val_rating));
                $parts[] = sprintf( self::TPL_INPUT_PRE, 'comment-form-rating',
                        self::FNAME_RATING, $tlate);
                $parts[] = "<br />\n";
                // The input tag was build in the constructor.
                $parts[] = '<span class="commentratingbox">' . "\n" .
                        $this->tplt_rating_stars . '</span>';
                $parts[] = '</p>';
                break;
        }

        return implode('', $parts) . "\n";
    }
}


// Add a Settings link to the Plugins page in admin.
add_filter('plugin_action_links_' . plugin_basename(__FILE__),
        'fyp_spampro_add_plugin_page_settings');
function fyp_spampro_add_plugin_page_settings($links) {
    // Add to settings screen
    $url = 'options-general.php?page=' . YFP_Spam_Settings_Page::SLUG_MENU_ADMIN;
	$settings_link = '<a href="' . admin_url($url) . '">' . __('Settings') . '</a>';
    // Put the settings link before the others.
    array_unshift( $links, $settings_link );
	return $links;
}

// Add custom fields to the default comment form
// Default comment form elements are hidden when the user is logged in.
add_filter('comment_form_default_fields', 'spam_protect_custom_fields');
function spam_protect_custom_fields($fields) {

    $yfpsp = new YFP_Spam_Protection();
    // Add 3 fields, all are required.
    $fields[ YFP_Spam_Protection::FNAME_PHONE ] =
            $yfpsp->get_field_html(YFP_Spam_Protection::TYPE_PHONE);
    $fields[ YFP_Spam_Protection::FNAME_TITLE ] =
            $yfpsp->get_field_html(YFP_Spam_Protection::TYPE_TITLE);
    $fields[ YFP_Spam_Protection::FNAME_RATING ] =
            $yfpsp->get_field_html(YFP_Spam_Protection::TYPE_RATING);
    return $fields;
}

// Add the filter to check if the comment verification data has been filled.
add_filter( 'preprocess_comment', 'verify_comment_meta_data' );

/**
 * Pass the comment data through the filter unchanged if all of the fields were
 * properly set. No checking is done if the user is logged in because a logged in
 * user is trusted.
 *
 * If an error is encountered, an error message is displayed and execution stops.
 * The user must use the browser's Back button to recover. This will exit.
 *
 * @param string $commentData
 * @return string;
 */
function verify_comment_meta_data( $commentData ) {

    // The data is only checked if the user is not logged in.
    if ( !is_user_logged_in() ) {

        // The object is needed to get the expected values and failure messages.
        $yfpsp = new YFP_Spam_Protection();

        // The $_POST key and the YFP_Spam_Protection class key constant.
        $pairs = [
            YFP_Spam_Protection::FNAME_PHONE => YFP_Spam_Protection::TYPE_PHONE,
            YFP_Spam_Protection::FNAME_TITLE => YFP_Spam_Protection::TYPE_TITLE,
            YFP_Spam_Protection::FNAME_RATING => YFP_Spam_Protection::TYPE_RATING,
        ];

        // Each item must be in the post and have the expected value.
        $dieMsgs = [];
        foreach($pairs as $key => $objKey) {

            if ( !isset( $_POST[$key] ) || !$yfpsp->is_expected_value($objKey, $_POST[$key]) ) {

                // The user must use the browser's Back button to recover.
                $dieMsgs[] = $yfpsp->get_verify_failure_message( $objKey );
            }
        }

        // If there were any errors...
        if ( count($dieMsgs) ) {

            // Debug messages: Get options from the WP database and show the data.
            if (1) {
                $optionsData = get_option(YFP_Spam_Protection::WP_OPTION_KEY);
                $dieMsgs[] = '$optionsData<br />' . print_r( $optionsData, true );
                $dieMsgs[] = '$_POST<br />' . print_r( $_POST, true );
            }

            // Make the error HTML.
            $msg = implode( '<br /><br />', $dieMsgs ) . "<br />\n";
            // Give the message and exit.
            wp_die( $msg );
        }
    }

    // If there were no errors, return the unchanged comment data.
    return $commentData;
}


/**
 * Debug aid for printing an array using var_dump.
 *
 * @param array $dataArr
 * @return string
 */
function _yfp_dump_arr_to_html($dataArr) {
    ob_start();
    var_dump($dataArr);
    $data = ob_get_clean();
    return htmlspecialchars($data);
}



/**
 * Taken mostly from the WP sample page, so I'm sure it could be improved. This
 * is the admin interface.
 */
class YFP_Spam_Settings_Page
{
    // This plugin's Options page slug - part of the admin url.
    const SLUG_MENU_ADMIN = 'yfp-spam-settings-admin';
    // There is only one section, so any ID will do.
    const SECTION_ID_1 = 'yfp_only_section_id';
    // The standard text INPUT element HTML template.
    const TPLT_INPUT_ELEMENT = '<input type="text" id="%s" name="%s" value="%s" />';

    // The object that is saved and retrieved from the options_ functions.
    protected $options;
    // The name of the key to use for all of this plugin's options.
    protected $wp_options_key_name = null;
    protected $yfp_settings_group = null;

    /**
     * Typing shortcut to turn a key from the options into text that can be used
     * as the value in a name parameter of an input element.
     *
     * @param string $key
     * @return string
     */
    private function to_input_file_name($key) {

        return $this->wp_options_key_name . '[' . $key . ']';
    }


    /**
     * Start up: add the settings page and register settings values.
     */
    public function __construct() {

        // The option name as used in the get_option() call.
        $this->wp_options_key_name = YFP_Spam_Protection::WP_OPTION_KEY;
        // Make it a different name, more consistent with other plugins.
        $this->yfp_settings_group = $this->wp_options_key_name . '_settings_group';
        // Get and save the current options, but why?
        $this->options = get_option( $this->wp_options_key_name );

        add_action( 'admin_menu', [ $this, 'add_plugin_page' ] );
        add_action( 'admin_init', [ $this, 'yfp_options_init' ] );
    }

    /**
     * Add the plugin's options page to the Settings section in the admin area.
     */
    public function add_plugin_page() {

        // This function is a simple wrapper for a call to add_submenu_page().
        add_options_page(
            'YFP Spam Protection Settings', // Title for the browser's title tag.
            'YFP Spam Protection', // Menu text, show under Settings.
            'manage_options', // Which users can use this.
            self::SLUG_MENU_ADMIN, // Menu slug
            [ $this, 'admin_page_html' ] // Callback that makes the HTML for the page.
        );
    }


    /**
     * Options page callback, this creates the Settings page content.
     */
    public function admin_page_html() {

        // The key used matches the Option name in register_settings.
        $this->options = get_option( $this->wp_options_key_name );

        // Set class property
        ?>
        <div class="wrap">
            <h2>YFP Spam Protection settings</h2>
            <p>The defaults will work for most people. Any of the values can be
            changed to different strings. That will then be the required "answer".
            The value for Rating must be a number, between 1 and 5.</p>

            <form method="post" action="options.php">
            <?php
                // The option group. This should match the group name used in register_setting().
                settings_fields( $this->yfp_settings_group );
                // This prints out all hidden setting fields for the page.
                // This will output the section titles wrapped in h3 tags and the settings fields wrapped in tables.
                do_settings_sections( self::SLUG_MENU_ADMIN );
                submit_button();
                //print _yfp_dump_arr_to_html($this->options);
            ?>
            </form>
        </div>
        <?php
    }

    /**
     * DRY for adding a field to the only section.
     *
     * @param string $theId - ID, needs to be unique within a section
     * @param string $theDesc - text displayed for the field
     * @param string $cbName - callback method that prints the input elem HTML
     */
    private function add_sec1_field($theId, $theDesc, $cbName) {

        add_settings_field($theId, $theDesc, [$this, $cbName],
            self::SLUG_MENU_ADMIN, // Options page slug
            self::SECTION_ID_1 // Section for this field
        );
    }

    /**
     * Register and add settings. Updates in V1.3 make this require WP >= 4.7.
     * This is a callback for "admin_init".
     */
    public function yfp_options_init() {

        $this->options = get_option( $this->wp_options_key_name );

        // Adds an entry for this plugin. Parm #3 changed to array in WP 4.7.
        register_setting(
            $this->yfp_settings_group, // Option group
            $this->wp_options_key_name, // the option name as used in the get_option() call.
            [
                'description' => 'All data for spam protection.', // Used by REST API, not enabled.
                'sanitize_callback' => [ $this, 'sanitize_submission' ], // Sanitize the submitted data.
            ]
        );

        // There is only one section, so any ID will do.
        add_settings_section(
            self::SECTION_ID_1, // ID
            'All settings', // Title
            [ $this, 'print_section_info' ], // Callback
            self::SLUG_MENU_ADMIN // Options page slug, used in do_settings_sections.
        );

        // Need a field for each option that can be set.
        $this->add_sec1_field(
            'rating1', // ID, only needs to be unique within a section.
            'Rating (1-5)', // Title
            'rating_callback' // Callback method name
        );

        $this->add_sec1_field(
            'phone1',
            'Phone number',
            'phone_callback'
        );

        $this->add_sec1_field(
            'title1',
            'Comment title',
            'title_callback'
        );
    }

    /**
     * Sanitize each setting field as needed. If a key does not get returned
     * it won't be saved. Because all of the used data is in a single options
     * key, that means that values will return to the default. So code was
     * added to fix that.
     * 
     * The code became long and complicated enough that it made sense to move
     * it into a separate class for clarity. That class does not modify the
     * data, only validates it.
     *
     * @param array $input - Contains all settings fields as array keys
     * @returns array - validated data ready for database modification.
     */
    public function sanitize_submission( $input ) {

        // Errors and or debug.
        $dbgMessages = [];
        // New installation or saving for the first time.
        if (!is_array($this->options)) {
            // Will allow updating of everything because the keys will not exist.
            $this->options = [];
        }

        //$dbgMessages[] = 'raw input: ' . _yfp_dump_arr_to_html($input);
        //$dbgMessages[] = 'pre update db values: ' . _yfp_dump_arr_to_html($this->options) . ' | ';
        // Parse and validate the inputs.
        $oValidator = new Yfp_Spam_Form_Validator($this->options, $input);

        // Only get it once to make sure the same thing goes to the debug msg.
        $new_input = $oValidator->get_sanitized();
        //$dbgMessages[] = '$oValidator "sanitize" values: ' . _yfp_dump_arr_to_html($new_input);

        // Debug or actual parse messages
        $messages = array_merge($dbgMessages, $oValidator->get_messages());
        if ( count($messages) ) {

            $message = implode("<br />\n", $messages) . "\n";
            // WP: this is designed for a message that it will wrap p tag.
            add_settings_error(
                'unusedUniqueIdentifyer',
                esc_attr( 'settings_updated' ),
                $message,
                // WP 5.3 Possible values include 'error', 'success', 'warning', 'info'
                $oValidator->get_settings_error_type()
            );
        }

        return $new_input;

    }

    /**
     * Print the Section text. There is only one section.
     */
    public function print_section_info() {

        _e('All of the settings are here. Enter any changes below:');
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function rating_callback() {

        $key = YFP_Spam_Protection::WPO_KEY_RATING;
        printf( self::TPLT_INPUT_ELEMENT,
            __('updated rating'),
            $this->to_input_file_name($key),
            isset( $this->options[$key] ) ? esc_attr( $this->options[$key]) :
                YFP_Spam_Protection::DEFAULT_RATING
        );
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function title_callback() {

        $key = YFP_Spam_Protection::WPO_KEY_TITLE;
        printf( self::TPLT_INPUT_ELEMENT,
            __('updated title'),
            $this->to_input_file_name($key),
            isset( $this->options[$key] ) ? esc_attr( $this->options[$key]) :
                YFP_Spam_Protection::DEFAULT_TITLE
        );
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function phone_callback() {

        $key = YFP_Spam_Protection::WPO_KEY_PHONE;
        printf( self::TPLT_INPUT_ELEMENT,
            __('updated phone'),
            $this->to_input_file_name($key),
            isset( $this->options[$key] ) ? esc_attr( $this->options[$key]) :
                YFP_Spam_Protection::DEFAULT_PHONE
        );
        //print 'current options: ' . _yfp_dump_arr_to_html( $this->options);
    }
}


/**
 * A separate form data validator class because the single function one was too
 * complicated. This validates the admin page data. It does not access the
 * database, but operates only on the input data.
 */
class Yfp_Spam_Form_Validator
{
    // Make some typing shortcuts for the database data keys:
    const KDB_TITLE = YFP_Spam_Protection::WPO_KEY_TITLE;
    const KDB_RATING = YFP_Spam_Protection::WPO_KEY_RATING;
    const KDB_PHONE = YFP_Spam_Protection::WPO_KEY_PHONE;

    // Defined by WP's add_settings_error() function.
    const TYPE_ERR_CODE = 'error';
    // The original database data.
    private $dbArr = null;
    // Data sent by the form submission.
    private $formInArr = null;
    // The sanitized output data.
    private $saneInput = [];
    // Messages generated during parsing; array of strings.
    private $messages = [];
    // 'updated' may be an old example value. Not listed as valid now, but works.
    private $typeAddSettingsError = 'updated';

    /**
     * A common error condition handler to determine the output value. This is
     * only called when an error has occurred that should modify the WP
     * add_settings_error function's "type".
     *
     * @param string $curKey
     * @param string $default
     */
    private function handle_error_input($curKey, $default) {
        $this->typeAddSettingsError = self::TYPE_ERR_CODE;
        $this->saneInput[$curKey] = (array_key_exists($curKey, $this->dbArr) ?
                // Keep the current db setting or use the default.
                $this->dbArr[$curKey] : $default
        );
    }

    /**
     * Only called if the title key was set.
     *
     * @param string $inData - the data provided by the form.
     */
    private function parse_title( $inData ) {
        
        // The keys in all 3 arrays are the same; this is the title key.
        $curKey = self::KDB_TITLE;

        $val = sanitize_text_field( $inData );
        $chars = strlen( $val );
        if ( 0 !== $chars && 20 >= $chars ) {
            $this->saneInput[$curKey] = $val;
            // Only update the message if the value has changed.
            if ( !array_key_exists($curKey, $this->dbArr) ||
                    $this->dbArr[$curKey] !== $this->saneInput[$curKey] ) {
                $this->messages[] = __('The Title field was updated. ');
            }
        }
        // Input data did not meet the validation filter.
        else {
            $this->messages[] = __('Title cannot be empty or contain html, and ' .
                    'must be 20 or fewer characters. ');
            $this->handle_error_input($curKey, YFP_Spam_Protection::DEFAULT_TITLE);
        }
    }

    /**
     * Only called if the Phone key was set.
     *
     * @param string $inData - the data provided by the form.
     */
    private function parse_phone( $inData ) {
        
        // The keys in all 3 arrays are the same; this is the phone key.
        $curKey = self::KDB_PHONE;

        $val = sanitize_text_field( $inData );
        $chars = strlen( $val );
        if ( 0 !== $chars && 15 >= $chars ) {
            $this->saneInput[$curKey] = $val;
            // Only update the message if the value has changed.
            if ( !array_key_exists($curKey, $this->dbArr) ||
                    $this->dbArr[$curKey] !== $this->saneInput[$curKey] ) {
                $this->messages[] = __('The Phone number was updated. ');
            }
        }
        else {
            $this->messages[] = __('Phone cannot be empty or contain html, ' .
                    'and must be 15 characters or less. ');
            $this->handle_error_input($curKey, YFP_Spam_Protection::DEFAULT_PHONE);
        }
    }

    /**
     * Only called if the Rating key was set. The limits are used in multiple
     * places, so changing it from "5" here is not enough.
     *
     * @param string $inData - the data provided by the form.
     */
    private function parse_rating( $inData ) {
        
        // The keys in all 3 arrays are the same; this is the Rating key.
        $curKey = self::KDB_RATING;
        $val = absint( $inData );
        if ( 0 < $val && 5 >= $val ) {

            $this->saneInput[$curKey] = strval($val);
            // Only update the message if the value has changed.
            if ( !array_key_exists($curKey, $this->dbArr) ||
                    $this->dbArr[$curKey] !== $this->saneInput[$curKey] ) {
                $this->messages[] = __('The Rating field was updated. ');
            }
        }
        else {
            $this->messages[] = __('Rating must be a number from 1 to 5. ');
            $this->handle_error_input($curKey, YFP_Spam_Protection::DEFAULT_RATING);
        }
    }

    /**
     * Check each element for new data and parse it if a value was set.
     */
    private function parse_inputs() {

        if( isset( $this->formInArr[self::KDB_RATING] ) ) {
            //$this->messages[] = 'Rating is set: ' . $this->formInArr[self::KDB_RATING];
            $this->parse_rating( $this->formInArr[self::KDB_RATING] );
        }

        if( isset( $this->formInArr[self::KDB_TITLE] ) ) {
            //$this->messages[] = 'Ttile is set: ' . $this->formInArr[self::KDB_TITLE];
            $this->parse_title( $this->formInArr[self::KDB_TITLE] );
        }

        if( isset( $this->formInArr[self::KDB_PHONE] ) ) {
            //$this->messages[] = 'Phone is set: ' . $this->formInArr[self::KDB_PHONE];
            $this->parse_phone( $this->formInArr[self::KDB_PHONE] );
        }
    }

    /**
     * Both input arrays have exactly the same keys (when the keys exist). This
     * class does validation checks for each type of data supplied by the form.
     * 
     * @param array $existingDbArr - data read from the database.
     * @param array $formInputArr - data supplied by the form.
     */
    function __construct($existingDbArr, $formInputArr) {
        
        $this->dbArr = $existingDbArr;
        $this->formInArr = $formInputArr;
        $this->parse_inputs();
    }


    /**
     * Messages generated during parsing. May be empty if no changes were made
     * and no errors were encountered.
     * 
     * @return array - list of string messages, if any.
     */
    public function get_messages() {

        return $this->messages;
    }
    
    /**
     * The sanitized data array. Creating this is the purpose of this class.
     * @return array
     */
    public function get_sanitized() {

        return $this->saneInput;
    }

    /**
     * If an error occurred during parsing, this will be set to "error".
     * @return string
     */
    public function get_settings_error_type() {
        
        return $this->typeAddSettingsError;
    }
}


// Launch the class that will setup hooks and admin data only if this is admin mode.
if( is_admin() ) {

    // The variable is not used, but eliminates a warning.
    $my_settings_page = new YFP_Spam_Settings_Page();
}

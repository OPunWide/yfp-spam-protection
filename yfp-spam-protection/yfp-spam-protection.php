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
 */
class YFP_Spam_Protection
{
    const DEFAULT_PHONE = '555-5555';
    const DEFAULT_TITLE = 'bad';
    const DEFAULT_RATING = '1';

    // These are used to pick which data to return.
    const TYPE_PHONE = 1;
    const TYPE_TITLE = 2;
    const TYPE_RATING = 3;

    // These are used in wp_options: The key for the table and the array keys is saves.
    const WP_OPTION_KEY = 'yfp_spam_protection';
    const WPO_KEY_PHONE = 'ph';
    const WPO_KEY_RATING = 'ra';
    const WPO_KEY_TITLE = 'ti';

    // The current value of the option.
    private $cur_val_phone;
    private $cur_val_title;
    private $cur_val_rating;

    // Text for the forms and error messages.
    const TPL_INPUT_PRE = '<p class="%s"><label for="%s">%s <span class="required">*</span></label>';
    const TPL_INPUT_TAG = '<input id="%s" name="%s" type="text" size="30" />';
    private $tplt_rating_stars = '';
    private $tplt_err_pre = 'Error: You did not %s.';
    private $tplt_err_post = '%s, it is part of the spam filter. Hit the BACK button on your browser and resubmit your comment.';

    /**
     * If $key exists in $arr and it is a non-empty string return it, else return $default.
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

        // Set up the rating text, a sequence of radio button inputs.
        $tpl_rating_input = '<span class="commentrating"><input type="radio" name="rating" value="%s" />%s</span>';
        $parts = [];
        for( $i=1; $i <= 5; $i++ ) {
            $parts[] = sprintf($tpl_rating_input, $i, $i);
        }
        $this->tplt_rating_stars = implode("\n", $parts) . "\n";
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
     * Get the expected value for the field to verify.
     *
     * @param mixed $fieldtype - one of the class' TYPE_ constants
     * @return string
     */
    public function get_verify_failure_message( $fieldtype ) {

        $htm = '';
        switch ( $fieldtype ) {

            case self::TYPE_PHONE:
                $htm .= __(sprintf( $this->tplt_err_pre, 'enter your phone number' ));
                $htm .= __(sprintf( $this->tplt_err_post, ' Enter "' .
                        $this->get_expected_value_in_bold(self::TYPE_PHONE) .
                        '" without the quotes' ));
                break;

            case self::TYPE_TITLE:
                $htm .= __(sprintf( $this->tplt_err_pre, 'enter a Comment Title' ));
                $htm .= __(sprintf( $this->tplt_err_post, ' Enter "' .
                        $this->get_expected_value_in_bold(self::TYPE_TITLE) .
                        '" without the quotes' ));
                break;

            case self::TYPE_RATING:
                $htm .= __(sprintf( $this->tplt_err_pre, 'enter a rating' ));
                $htm .= __(sprintf( $this->tplt_err_post, ' It must be rated as a ' .
                        $this->get_expected_value_in_bold(self::TYPE_RATING) ));
                break;
        }

        return $htm;
    }

    /**
     * Gets the contents for a field on the form.
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
                $parts[] = sprintf( self::TPL_INPUT_PRE, 'comment-form-phone', 'phone', $tlate ) . "\n";
                $parts[] = sprintf( self::TPL_INPUT_TAG, 'phone', 'phone' );
                $parts[] = '</p>';
                break;

            case self::TYPE_TITLE:
                $tlate = __(sprintf( 'Comment Title (must use text: %s)', $this->cur_val_title));
                $parts[] = sprintf( self::TPL_INPUT_PRE, 'comment-form-title', 'title', $tlate ) . "\n";
                $parts[] = sprintf( self::TPL_INPUT_TAG, 'title', 'title' );
                $parts[] = '</p>';
                break;

            case self::TYPE_RATING:
                $tlate = __(sprintf( 'Rating (must select: %s)', $this->cur_val_rating));
                $parts[] = sprintf( self::TPL_INPUT_PRE, 'comment-form-rating', 'rating', $tlate);
                $parts[] = "<br />\n";
                $parts[] = '<span class="commentratingbox">' . "\n" . $this->tplt_rating_stars . '</span>';
                $parts[] = '</p>';
                break;
        }

        return implode('', $parts) . "\n";
    }
}


// Add a Settings link to the Plugins page in admin.
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'fyp_spampro_add_plugin_page_settings');
function fyp_spampro_add_plugin_page_settings($links) {
    // Add to settings screen
    $url = 'options-general.php?page=' . YFP_Spam_Settings_Page::SLUG_MENU_ADMIN;
	$settings_link = '<a href="' . admin_url($url) . '">' . __('Settings') . '</a>';
    // Put the settings link before the others.
    array_unshift( $links, $settings_link );
	return $links;
}

// Add custom fields to the default comment form
// Default comment form elements are hidden when user is logged in
add_filter('comment_form_default_fields', 'spam_protect_custom_fields');
function spam_protect_custom_fields($fields) {

    $yfpsp = new YFP_Spam_Protection();
    // Add 3 fields, all are required.
    $fields[ 'phone' ] = $yfpsp->get_field_html(YFP_Spam_Protection::TYPE_PHONE);
    $fields[ 'title' ] = $yfpsp->get_field_html(YFP_Spam_Protection::TYPE_TITLE);
    $fields[ 'rating' ] = $yfpsp->get_field_html(YFP_Spam_Protection::TYPE_RATING);
    return $fields;
}


// Add the filter to check if the comment verification data has been filled.
add_filter( 'preprocess_comment', 'verify_comment_meta_data' );

/**
 * Pass the comment data through the filter unchanged if all of the fields were
 * properly set. No checking is done if the user is logged in because a logged in
 * user is trusted.
 *
 * @param string $commentdata
 * @return string;
 */
function verify_comment_meta_data( $commentdata ) {

    // The data is only checked if the user is not logged in.
    if ( !is_user_logged_in() ) {

        // The object is needed to get the expected values and failure messages.
        $yfpsp = new YFP_Spam_Protection();

        // The $_POST key and the YFP_Spam_Protection class key constant.
        $pairs = [
            'phone' => YFP_Spam_Protection::TYPE_PHONE,
            'rating' => YFP_Spam_Protection::TYPE_RATING,
            'title' => YFP_Spam_Protection::TYPE_TITLE,
        ];

        // Each item must be in the post and have the expected value.
        foreach($pairs as $key => $objKey) {

            if ( !isset( $_POST[$key] ) || !$yfpsp->is_expected_value($objKey, $_POST[$key]) ) {

                // The user must use the browser's Back button to recover.
                wp_die( $yfpsp->get_verify_failure_message($objKey) );
            }
        }
    }

    return $commentdata;
}



function debug_options_arr($dataArr) {
    ob_start();
    var_dump($dataArr);
    $data = ob_get_clean();
    return htmlspecialchars($data);
}



/**
 * Taken mostly from the WP sample page, so I'm sure it could be improved.
 */
class YFP_Spam_Settings_Page
{
    /**
     * Holds the values to be used in the fields callbacks
    */
    const TPLT_INPUT_ELEMENT = '<input type="text" id="%s" name="%s" value="%s" />';
    // This plugin's Options page slug - part of the admin url.
    const SLUG_MENU_ADMIN = 'yfp-spam-settings-admin';
    // There is only one section, so any ID will do.
    const SECTION_ID_1 = 'yfp_only_section_id';

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
        $this->yfp_settings_group = $this->wp_options_key_name;
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
            The Ratings value must be a number, between 1 and 5.</p>

            <form method="post" action="options.php">
            <?php
                // The option group. This should match the group name used in register_setting().
                settings_fields( $this->yfp_settings_group );
                // This prints out all hidden setting fields for the page.
                // This will output the section titles wrapped in h3 tags and the settings fields wrapped in tables.
                do_settings_sections( self::SLUG_MENU_ADMIN );
                submit_button();
                //print debug_options_arr($this->options);
            ?>
            </form>
        </div>
        <?php
    }

    /**
     * Register and add settings
     */
    public function yfp_options_init() {

        $this->options = get_option( $this->wp_options_key_name );
        register_setting(
            $this->yfp_settings_group, // Option group
            $this->wp_options_key_name, // the option name as used in the get_option() call.
            [ $this, 'sanitize' ] // Sanitize
        );

        // There is only one section, so any ID will do.
        add_settings_section(
            self::SECTION_ID_1, // ID
            'All settings', // Title
            [ $this, 'print_section_info' ], // Callback
            self::SLUG_MENU_ADMIN // Options page slug, used in do_settings_sections.
        );

        // Need a field for each option that can be changed.
        add_settings_field(
            'rating', // ID
            'Rating (1-5)', // Title
            [ $this, 'rating_callback' ], // Callback
            self::SLUG_MENU_ADMIN, // Options page slug
            self::SECTION_ID_1 // Section for this field
        );

        add_settings_field(
            'phone',
            'Phone number',
            [ $this, 'phone_callback' ],
            self::SLUG_MENU_ADMIN, // Options page slug
            self::SECTION_ID_1 // Section for this field
        );

        add_settings_field(
            'title',
            'Comment title',
            [ $this, 'title_callback' ],
            self::SLUG_MENU_ADMIN, // Options page slug
            self::SECTION_ID_1 // Section for this field
        );
    }

    /**
     * Sanitize each setting field as needed. If a key does not get returned
     * it won't be saved. Because all of the used data is in a single options
     * key, that means that values will return to the default. So code was
     * added to fix that.
     *
     * @param array $input Contains all settings fields as array keys
     */
    public function sanitize( $input ) {

        $new_input = [];
        // Make some typing shortcuts:
        $key_title = YFP_Spam_Protection::WPO_KEY_TITLE;
        $key_rating = YFP_Spam_Protection::WPO_KEY_RATING;
        $key_phone = YFP_Spam_Protection::WPO_KEY_PHONE;
        // New installation or saving for the first time.
        if (!is_array($this->options)) {
            // Will allow updating of everything because the keys will not exist.
            $this->options = [];
        }

        // Determines the type of message displayed, will be changed if there is an error.
        $type = 'updated';
        //$data = debug_options_arr($input);
        $message = '';
        //$message .= 'pre update values: ' . debug_options_arr($this->options) . ' | ';

        // Verify it is between 1 and 5, but save as a string.
        if( isset( $input[$key_rating] ) ) {

            $val = absint( $input[$key_rating] );
            if ( 0 < $val && 5 >= $val ) {

                $new_input[$key_rating] = strval($val);
                // Only update the message if the value has changed.
                if ( !array_key_exists($key_rating, $this->options) ||
                        $this->options[$key_rating] !== $new_input[$key_rating] ) {
                    $message .= __('The Rating field was updated. ');
                }
            }
            else {
                $type = 'error';
                $message .= __('Rating must be a number from 1 to 5. ');
                if ( array_key_exists($key_rating, $this->options) ) {
                    // Keep the current setting.
                    $new_input[$key_rating] = $this->options[$key_rating];
                }
                else {
                    // Use the default.
                    $new_input[$key_rating] = YFP_Spam_Protection::DEFAULT_RATING;
                }
            }
        }

        if( isset( $input[$key_title] ) ) {
            $val = sanitize_text_field( $input[$key_title] );
            $chars = strlen( $val );
            if ( 0 !== $chars && 20 >= $chars ) {
                $new_input[$key_title] = $val;
                // Only update the message if the value has changed.
                if ( !array_key_exists($key_title, $this->options) ||
                        $this->options[$key_title] !== $new_input[$key_title] ) {
                    $message .= __('The Title field was updated. ');
                }
            }
            else {
                $type = 'error';
                $message .= __('Title cannot be empty or contain html, and must be 20 or fewer characters. ');
                if ( array_key_exists($key_title, $this->options) ) {
                    // Keep the current setting.
                    $new_input[$key_title] = $this->options[$key_title];
                }
                else {
                    // Use the default.
                    $new_input[$key_title] = YFP_Spam_Protection::DEFAULT_TITLE;
                }
            }
        }

        if( isset( $input[$key_phone] ) ) {
            $val = sanitize_text_field( $input[$key_phone] );
            $chars = strlen( $val );
            if ( 0 !== $chars && 15 >= $chars ) {
                $new_input[$key_phone] = $val;
                // Only update the message if the value has changed.
                if ( !array_key_exists($key_phone, $this->options) ||
                        $this->options[$key_phone] !== $new_input[$key_phone] ) {
                    $message .= __('The Phone number was updated. ');
                }
            }
            else {
                $type = 'error';
                $message .= __('Phone cannot be empty or contain html, and must be 15 characters or less. ');
                if ( array_key_exists($key_phone, $this->options) ) {
                    // Keep the current setting.
                    $new_input[$key_phone] = $this->options[$key_phone];
                }
                else {
                    // Use the default.
                    $new_input[$key_phone] = YFP_Spam_Protection::DEFAULT_PHONE;
                }
            }
        }

        //$message .= ' | debug: ' . debug_options_arr($new_input);
        if ('' !== $message) {

            add_settings_error(
                'unusedUniqueIdentifyer',
                esc_attr( 'settings_updated' ),
                $message,
                $type
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
        //print 'current options: ' . debug_options_arr( $this->options);
    }
}

if( is_admin() ) {

    $my_settings_page = new YFP_Spam_Settings_Page();
}

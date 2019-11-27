<?php
/*
Plugin Name: yfp-spam-protection
Version: 1.2.1
Plugin URI: http://SplendidSpider.com
Description: A plug-in to stop spam that is coming from the comment form.
Author: Paul Blakelock, but see below
Author URI: http://SplendidSpider.com
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
* All of the other plugin code is to allow the form to be modifed by an admin.
* It allows the standard answers to be changed, in case the spammers
* ever decide that this is a plugin worth trying to bypass.
*
* When the user is logged in, custom_fields are hidden and are not required.
*
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
    protected $cur_val_phone;
    protected $cur_val_title;
    protected $cur_val_rating;

    // Text for the forms and error messages.
    protected $tplt_input_pre = '<p class="%s"><label for="%s">%s <span class="required">*</span></label>';
    protected $tplt_input_tag = '<input id="%s" name="%s" type="text" size="30" />';
    protected $tplt_rating_stars = '';
    protected $tplt_err_pre = 'Error: You did not %s.';
    protected $tplt_err_post = '%s, it is part of the spam filter. Hit the BACK button of your Web browser and resubmit your comment.';

    /**
    * If $key exists in $arr and it is a non-empty string return it, else return $default.
    *
    * @param array $arr
    * @param string $key
    * @param string $default
    * @return string
    */
    protected function array_val_or_default($arr, $key, $default) {

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
    * Set the initial values for things that cannot be constants.
    *
    */
    public function __construct() {

        // Do a reset until the admin panel is done.
        //$ok = update_option(self::WP_OPTION_KEY, array());

        // Get options from the WP database.
        $optionsData = get_option(self::WP_OPTION_KEY);
        $this->cur_val_phone = $this->array_val_or_default($optionsData, self::WPO_KEY_PHONE, self::DEFAULT_PHONE);
        $this->cur_val_title = $this->array_val_or_default($optionsData, self::WPO_KEY_TITLE, self::DEFAULT_TITLE);
        $this->cur_val_rating = $this->array_val_or_default($optionsData, self::WPO_KEY_RATING, self::DEFAULT_RATING);

        // Set up the rating text.
        $this->tplt_rating_stars = '';
        for( $i=1; $i <= 5; $i++ ) {
            $this->tplt_rating_stars .= '<span class="commentrating"><input type="radio" name="rating" value="'. $i .'" />'. $i .'</span>' . "\n";
        }
    }

    /**
    * Get the expected value for the field to verify.
    *
    * @param mixed $fieldtype - one of the class' TYPE_ constants
    * @return string
    */
    public function get_expected_value( $fieldtype ) {

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
                $htm .= __(sprintf( $this->tplt_err_post, 'Enter "<b>' .
                        $this->get_expected_value(self::TYPE_PHONE) .
                        '</b>" without the quotes' ));
                break;

            case self::TYPE_TITLE:
                $htm .= __(sprintf( $this->tplt_err_pre, 'enter a Comment Title' ));
                $htm .= __(sprintf( $this->tplt_err_post, 'Enter "<b>' .
                        $this->get_expected_value(self::TYPE_TITLE) .
                        '</b>" without the quotes' ));
                break;

            case self::TYPE_RATING:
                $htm .= __(sprintf( $this->tplt_err_pre, 'enter a rating' ));
                $htm .= __(sprintf( $this->tplt_err_post, 'It must be rated as a <b>' .
                        $this->get_expected_value(self::TYPE_RATING) .
                        '</b>' ));
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

        $htm = '';

        switch ( $fieldtype ) {

            case self::TYPE_PHONE:
                $tlate = __(sprintf( 'Phone (must use number: %s)', $this->cur_val_phone));
                $htm .= sprintf( $this->tplt_input_pre, 'comment-form-phone', 'phone', $tlate ) . "\n";
                $htm .= sprintf( $this->tplt_input_tag, 'phone', 'phone' );
                $htm .= '</p>';
                break;

            case self::TYPE_TITLE:
                $tlate = __(sprintf( 'Comment Title (must use text: %s)', $this->cur_val_title));
                $htm .= sprintf( $this->tplt_input_pre, 'comment-form-title', 'title', $tlate ) . "\n";
                $htm .= sprintf( $this->tplt_input_tag, 'title', 'title' );
                $htm .= '</p>';
                break;

            case self::TYPE_RATING:
                $tlate = __(sprintf( 'Rating (must select: %s)', $this->cur_val_rating));
                $htm .= sprintf( $this->tplt_input_pre, 'comment-form-rating', 'rating', $tlate);
                $htm .= "<br />\n";
                $htm .= '<span class="commentratingbox">' . "\n" . $this->tplt_rating_stars . '</span>';
                $htm .= '</p>';
                break;
        }

        return $htm;
    }
}



// Add custom fields to the default comment form
// Default comment form elements are hidden when user is logged in
add_filter('comment_form_default_fields', 'custom_fields');
function custom_fields($fields) {

    $yfpsp = new YFP_Spam_Protection();
    // Add 3 fields, all are required.
    $fields[ 'phone' ] = $yfpsp->get_field_html(YFP_Spam_Protection::TYPE_PHONE);
    $fields[ 'title' ] = $yfpsp->get_field_html(YFP_Spam_Protection::TYPE_TITLE);
    $fields[ 'rating' ] = $yfpsp->get_field_html(YFP_Spam_Protection::TYPE_RATING);
    return $fields;
}


// Add the filter to check if the comment meta data has been filled or not
add_filter( 'preprocess_comment', 'verify_comment_meta_data' );
function verify_comment_meta_data( $commentdata ) {

    $yfpsp = new YFP_Spam_Protection();

    if ( !is_user_logged_in() ) {

        if ( ! isset( $_POST['phone'] ) || $_POST['phone'] !== $yfpsp->get_expected_value(YFP_Spam_Protection::TYPE_PHONE) ) {

            wp_die( $yfpsp->get_verify_failure_message(YFP_Spam_Protection::TYPE_PHONE) );
        }

        if ( ! isset( $_POST['rating'] ) || $_POST['rating'] !== $yfpsp->get_expected_value(YFP_Spam_Protection::TYPE_RATING) ) {

            wp_die( $yfpsp->get_verify_failure_message(YFP_Spam_Protection::TYPE_RATING) );
        }

        if ( ! isset( $_POST['title'] ) || $_POST['title'] !== $yfpsp->get_expected_value(YFP_Spam_Protection::TYPE_TITLE)) {

            wp_die( $yfpsp->get_verify_failure_message(YFP_Spam_Protection::TYPE_TITLE) );
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
    // The object that is saved and retrieved from the options_ functions.
    protected $options;
    // The name of the key to use for all of this plugin's options.
    protected $wp_options_key_name = YFP_Spam_Protection::WP_OPTION_KEY;
    protected $tplt_input_element = '<input type="text" id="%s" name="%s" value="%s" />';
    protected $yfp_settings_group = null;

    /**
     * Start up
     */
    public function __construct() {

        // the option name as used in the get_option() call.
        $this->wp_options_key_name = YFP_Spam_Protection::WP_OPTION_KEY;
        // Get and save the current options, but why?
        $this->options = get_option( $this->wp_options_key_name );
        $this->yfp_settings_group = $this->wp_options_key_name;

        add_action( 'admin_menu', array( $this, 'add_plugin_page' ) );
        add_action( 'admin_init', array( $this, 'yfp_options_init' ) );
    }

    /**
     * Add the options page to the Settings menu.
     */
    public function add_plugin_page() {

        // This page will be under "Settings"  in the admin area.
        // This function is a simple wrapper for a call to add_submenu_page().
        add_options_page(
            'Settings Admin', // Title for the browser's title tag.
            'YFP Spam Protection', // Menu text, show under Settings.
            'manage_options', // Which users can use this.
            'yfp-spam-settings-admin', // Menu slug
            array( $this, 'create_admin_page' )
        );
    }


    /**
     * Options page callback, this creates the page content.
     */
    public function create_admin_page() {

        // The key used matches the Option name in register_settings.
        $this->options = get_option( $this->wp_options_key_name );

        // Set class property
        ?>
        <div class="wrap">
            <h2>YFP Spam Protection settings</h2>
            <p>The defaults will work for most people, but any of the values can be
            changed to different strings. The ratings value must be between 1 and 5.</p>

            <form method="post" action="options.php">
            <?php
                // The option group. This should match the group name used in register_setting().
                settings_fields( $this->yfp_settings_group );
                // This prints out all hidden setting fields for the page.
                // This will output the section titles wrapped in h3 tags and the settings fields wrapped in tables.
                do_settings_sections( 'yfp-spam-settings-admin' );
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
            array( $this, 'sanitize' ) // Sanitize
        );

        // There is only one section, so any ID will do.
        add_settings_section(
            'yfp_only_section_id', // ID
            'All settings', // Title
            array( $this, 'print_section_info' ), // Callback
            'yfp-spam-settings-admin' // Options page slug, used in do_settings_sections.
        );

        // Need a field for each option that can be changed.
        add_settings_field(
            'rating', // ID
            'Rating (1-5)', // Title
            array( $this, 'rating_callback' ), // Callback
            'yfp-spam-settings-admin', // Options page slug
            'yfp_only_section_id' // Section for this field
        );

        add_settings_field(
            'phone',
            'Phone number',
            array( $this, 'phone_callback' ),
            'yfp-spam-settings-admin', // Options page slug
            'yfp_only_section_id' // Section for this field
        );

        add_settings_field(
            'title',
            'Comment title',
            array( $this, 'title_callback' ),
            'yfp-spam-settings-admin', // Options page slug
            'yfp_only_section_id' // Section for this field
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

        $new_input = array();
        // Make some typing shortcuts:
        $key_title = YFP_Spam_Protection::WPO_KEY_TITLE;
        $key_rating = YFP_Spam_Protection::WPO_KEY_RATING;
        $key_phone = YFP_Spam_Protection::WPO_KEY_PHONE;
        // New installation or saving for the first time.
        if (!is_array($this->options)) {
            // Will allow updating of everything because the keys will not exist.
            $this->options = array();
        }

        // Determines the type of message displayed, will be changed if there is an error.
        $type = 'updated';
        //$data = debug_options_arr($input);
        $message = '';
        //$message .= 'pre update values: ' . debug_options_arr($this->options) . ' | ';

        // Veryify it is between 1 and 5, but save as a string.
        if( isset( $input[$key_rating] ) ) {

            $val = absint( $input[$key_rating] );
            if ( 0 < $val && 5 >= $val ) {

                $new_input[$key_rating] = strval($val);
                // Only update the message if the value has changed.
                if ( !array_key_exists($key_rating, $this->options) ||
                        $this->options[$key_rating] !== $new_input[$key_rating] ) {
                    $message .= __('Rating field updated. ');
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
                    $message .= __('Title field updated. ');
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
                    $message .= __('Phone field updated. ');
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
     * Print the Section text
     */
    public function print_section_info() {

        _e('All setting for the plugin are on this page. Enter your settings below:');
        //print debug_options_arr($this->options);
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function rating_callback() {

        $key = YFP_Spam_Protection::WPO_KEY_RATING;
        printf( $this->tplt_input_element,
            __('updated rating'),
            $this->wp_options_key_name . '[' . $key . ']',
            isset( $this->options[$key] ) ? esc_attr( $this->options[$key]) :
                YFP_Spam_Protection::DEFAULT_RATING
        );
    }

    /**
     * Get the settings option array and print one of its values
     */
    public function title_callback() {

        $key = YFP_Spam_Protection::WPO_KEY_TITLE;
        printf( $this->tplt_input_element,
            __('updated title'),
            $this->wp_options_key_name . '[' . $key . ']',
            isset( $this->options[$key] ) ? esc_attr( $this->options[$key]) :
                YFP_Spam_Protection::DEFAULT_TITLE
        );
    }
    /**
     * Get the settings option array and print one of its values
     */
    public function phone_callback() {

        $key = YFP_Spam_Protection::WPO_KEY_PHONE;
        printf( $this->tplt_input_element,
            __('updated phone'),
            $this->wp_options_key_name . '[' . $key . ']',
            isset( $this->options[$key] ) ? esc_attr( $this->options[$key]) :
                YFP_Spam_Protection::DEFAULT_PHONE
        );
        //print 'current options: ' . debug_options_arr( $this->options);
    }
}

if( is_admin() ) {

    $my_settings_page = new YFP_Spam_Settings_Page();
}

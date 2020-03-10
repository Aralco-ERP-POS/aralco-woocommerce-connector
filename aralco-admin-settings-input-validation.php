<?php

defined( 'ABSPATH' ) or die(); // Prevents direct access to file.

/**
 * Interface Aralco_Input_Validator
 *
 * Interface for implementing validation for each option input field
 */
interface Aralco_Input_Validator {
    public function __construct( $setting );
    public function is_valid( $input );
}

/**
 * This class is responsible for validating numbers
 *
 * @implements Aralco_Input_Validator
 */
class Number_Validator implements Aralco_Input_Validator{

    /**
     * Slug title of relevant setting
     *
     * @access private
     */
    private $setting;

    /**
     * Constructor
     *
     * @param string $setting the settings slug title
     */
    public function __construct($setting){
        $this->setting = $setting;
    }

    /**
     * Returns true if the setting inputted is a valid number
     *
     * @param string $input the input number
     * @return bool true if the input is valid; otherwise false
     */
    public function is_valid($input){
        if (!is_numeric($input)) {
            $this->add_error('invalid-number', 'You must provide a valid number.');
            return false;
        }

        $input = intval(round(doubleval($input)));

        if ($input <= 0) {
            $this->add_error('invalid-number', 'You must provide a number greater then 0.');
            return false;
        }

        if ($input > PHP_INT_MAX ) {
            $this->add_error('invalid-number', 'You must provide a number less then ' .
                number_format(PHP_INT_MAX) . '.');
            return false;
        }

        return true;

    }

    /**
     * Adds an error if the validation fails
     *
     * @access private
     * @param string $key a unique idetifier for the specific message
     * @param string $message the actual message
     */
    private function add_error($key, $message){

        add_settings_error(
            $this->setting,
            $key,
            __($message, ARALCO_SLUG),
            'error'
        );

    }

}

/**
 * This class is responsible for validating strings
 *
 * @implements Aralco_Input_Validator
 */
class String_Validator implements Aralco_Input_Validator{

    /**
     * Slug title of relevant setting
     *
     * @access private
     */
    private $setting;

    /**
     * Constructor
     *
     * @param string $setting the settings slug title
     */
    public function __construct($setting){
        $this->setting = $setting;
    }

    /**
     * Returns true if the setting inputted is a valid string
     *
     * @param string $input the input number
     * @return bool true if the input is valid; otherwise false
     */
    public function is_valid($input){
        if (!is_numeric($input)) {
            $this->add_error('invalid-number', 'You must provide a valid number.');
            return false;
        }

        $input = intval(round(doubleval($input)));

        if ($input <= 0) {
            $this->add_error('invalid-number', 'You must provide a number greater then 0.');
            return false;
        }

        if ($input > PHP_INT_MAX ) {
            $this->add_error('invalid-number', 'You must provide a number less then ' .
                                               number_format(PHP_INT_MAX) . '.');
            return false;
        }

        return true;

    }

    /**
     * Adds an error if the validation fails
     *
     * @access private
     * @param string $key a unique idetifier for the specific message
     * @param string $message the actual message
     */
    private function add_error($key, $message){

        add_settings_error(
            $this->setting,
            $key,
            __($message, ARALCO_SLUG),
            'error'
        );

    }

}


/**
 * @param $input
 * @return mixed|void
 */
function aralco_validate_config($input) {
    $options = get_option(ARALCO_SLUG . '_options');
    $output = array();
    $valid = true;

//    if (!(new Number_Validator(ARALCO_SLUG . '_field_update_interval'))->is_valid($input[ARALCO_SLUG . '_field_update_interval'])) $valid = false;

    // Make the inputted data tag safe
    foreach($input as $key => $value){
        if(isset($input[$key])){
            $output[$key] = strip_tags(stripslashes($input[$key]));
        }
    }

    if(!$valid){
        add_settings_error(
            ARALCO_SLUG . '_messages',
            ARALCO_SLUG . '_messages_1',
            __("An error occurred. Please see below for details"),
            'error'
        );
        $output = $options;
    } else {
        $output[ARALCO_SLUG . '_field_api_location'] = trim($output[ARALCO_SLUG . '_field_api_location']);
        $output[ARALCO_SLUG . '_field_api_token'] = trim($output[ARALCO_SLUG . '_field_api_token']);
        $output[ARALCO_SLUG . '_field_update_interval'] = intval(round(doubleval($output[ARALCO_SLUG . '_field_update_interval'])));
    }

    return apply_filters('aralco_validate_config', $output, $input);
}

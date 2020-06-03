<?php

defined( 'ABSPATH' ) or die(); // Prevents direct access to file.

/**
 * Interface Aralco_Input_Validator
 *
 * Interface for implementing validation for each option input field
 */
interface Aralco_Input_Validator {
    public function __construct($setting, $args = array());
    public function is_valid($input);
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
    private $min;
    private $max;

    /**
     * Constructor
     *
     * @param string $setting the settings slug title
     * @param array $args settings for validation
     */
    public function __construct($setting, $args = array()){
        $this->setting = $setting;
        $this->min = (isset($args['min'])) ? $args['min'] : 0;
        $this->max = (isset($args['max'])) ? $args['max'] : PHP_INT_MAX;
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

        if ($input < $this->min) {
            $this->add_error('number-out-of-bounds-positive', 'You must provide a number greater then ' . ($this->min - 1) . '.');
            return false;
        }

        if ($input > $this->max) {
            $this->add_error('number-out-of-bounds-negative', 'You must provide a number less or equal to ' .
                number_format($this->max) . '.');
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
     * @param array $args settings for validation
     */
    public function __construct($setting, $args = array()){
        $this->setting = $setting;
    }

    /**
     * Returns true if the setting inputted is a valid string
     *
     * @param string $input the input number
     * @return bool true if the input is valid; otherwise false
     */
    public function is_valid($input){
        if (!is_string($input)) {
            $this->add_error('invalid-string', 'You must provide a valid string.');
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

    if ((new Number_Validator(
        ARALCO_SLUG . '_field_sync_interval',
        array(
            'min' => 1,
            'max' => 9999
        )
    ))->is_valid($input[ARALCO_SLUG . '_field_sync_interval']) == false) $valid = false;

    $input[ARALCO_SLUG . '_field_sync_enabled'] = (isset($input[ARALCO_SLUG . '_field_sync_enabled'])) ? '1' : '0';

    try{
        if($valid && $input[ARALCO_SLUG . '_field_sync_interval'] * $input[ARALCO_SLUG . '_field_sync_unit'] < 5){
            $valid = false;
            add_settings_error(ARALCO_SLUG . '_field_sync_interval',
                'time-out-of-bounds',
                __('You must provide a interval at least 5 minutes long.', ARALCO_SLUG),
                'error'
            );
        }
    } catch (Exception $e) {
        // Do nothing
    }

    // Make the inputted data tag safe
    foreach($input as $key => $value){
        if(isset($input[$key])){
            if(is_array($input[$key])){
                $array_out = array();
                foreach ($input[$key] as $item){
                    array_push($array_out, strip_tags(stripslashes($item)));
                }
                $output[$key] = $array_out;
            } else {
                $output[$key] = strip_tags(stripslashes($input[$key]));
            }
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
    }

    return apply_filters('aralco_validate_config', $output, $input);
}

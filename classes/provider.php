<?php

interface enrollment_factory {
    // Returns a semester_processor
    function semester_source();

    // Returns a course_processor
    function course_source();

    // Returns a teacher_processor
    function teacher_source();

    // Retunrs a student_processor
    function student_source();
}

abstract class enrollment_provider implements enrollment_factory {
    // Override for special behavior hooks
    function preprocess() {
        return true;
    }

    function postprocess() {
        return true;
    }

    /**
     * Return key / value pair of potential $CFG->$name_key_$key values
     * The values become default values. Entries are assumed to be textboxes
     */
    function settings() {
        return array();
    }

    /**
     * $settings is the $ADMIN tree, so users can override for
     * special admin config elements
     */
    function adv_settings(&$settings) {
    }
}

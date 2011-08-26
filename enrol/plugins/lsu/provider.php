<?php

require_once dirname(__FILE__) . '/processors.php';

class lsu_enrollment_provider extends enrollment_provider {
    var $url;
    var $wsdl;
    var $username;
    var $password;

    function init() {
        global $CFG;

        $path = pathinfo($this->wsdl);

        // Path checks
        if (!file_exists($this->wsdl)) {
            throw new Exception('no_file');
        }

        if ($path['extension'] != 'wsdl') {
            throw new Exception('bad_file');
        }

        if (!preg_match('/^[http|https]/', $this->url)) {
            throw new Exception('bad_url');
        }

        require_once $CFG->libdir . '/filelib.php';

        $curl = new curl(array('cache' => true));
        $resp = $curl->get($this->url);

        list($username, $password) = explode("\n", $resp);

        if (empty($username) or empty($password)) {
            throw new Exception('bad_resp');
        }

        $this->username = $username;
        $this->password = $password;
    }

    function __construct($init_on_create = true) {
        global $CFG;

        $this->url = $this->get_setting('credential_location');

        $this->wsdl = $CFG->dataroot . '/'. $this->get_setting('wsdl_location');

        if ($init_on_create) {
            $this->init();
        }
    }

    public static function settings() {
        return array(
            'credential_location' => 'https://secure.web.lsu.edu/credentials.php',
            'wsdl_location' => 'webService.wsdl'
        );
    }

    function source_params() {
        return array(
            'username' => $this->username,
            'password' => $this->password,
            'wsdl' => $this->wsdl
        );
    }

    function semester_source() {
        $source = new lsu_semesters();
        return $source->set_params($this->source_params());
    }

    function course_source() {
        $source = new lsu_courses();
        return $source->set_params($this->source_params());
    }

    function teacher_source() {
        $source = new lsu_teachers();
        return $source->set_params($this->source_params());
    }

    function student_source() {
        $source = new lsu_students();
        return $source->set_params($this->source_params());
    }

    function student_data_source() {
        $source = new lsu_student_data();
        return $source->set_params($this->source_params());
    }

    function postprocess() {
        $semesters_in_session = cps_semester::in_session();

        $source = $this->student_data_source();

        foreach ($semesters_in_session as $semester) {
            $datas = $source->student_data($semester);

            foreach ($datas as $data) {
                try {
                    $params = array('idnumber' => $user->idnumber);

                    $user = cps_user::upgrade_and_get($data, $params);

                    if (empty($user->id)) {
                        continue;
                    }

                    $user->save();
                } catch (Exception $e) {
                    $this->errors[] = $e->getMessage();
                }
            }
        }
    }
}
<?php

defined('MOODLE_INTERNAL') or die();

class enrol_cps_plugin extends enrol_plugin {
    const PENDING = 'pending';
    const PROCESSED = 'processed';

    // Section is created
    const MANIFESTED = 'manifested';
    const SKIPPED = 'skipped';

    // Teacher / Student manifestation
    const ENROLLED = 'enrolled';
    const UNENROLLED = 'unenrolled';

    /** Typical errolog for cron run */
    var $errors = array();

    var $provider;

    function __construct() {
        global $CFG;

        try {
            $this->provider = self::create_provider();

            $lib = self::base('classes/dao');

            require_once $lib . '/lib.php';
            require_once $lib . '/daos.php';
            require_once $CFG->dirroot . '/group/lib.php';

        } catch (Exception $e) {
            $a = self::translate_error($e);

            $this->errors[] = self::_s('provider_cron_problem', $a);
        }
    }

    public function is_cron_required() {
        //TODO: Make sure we first start at 2:30 or 3:00 AM
        /**
         * $now = (int)date('H');
         * if ($now >= 2 and $now <= 3) {
         *     return parent::is_cron_required();
         * }
         *
         * return false;
         */
        $is_enabled = $this->setting('cron_run');
        return ($is_enabled and parent::is_cron_required());
    }

    public function cron() {

        // TODO: clock processes
        if ($this->provider) {
            $this->full_process();
        }

        if (!empty($this->errors)) {
            //TODO: report errors
        }
    }

    public function setting($key) {
        return get_config('enrol_cps', $key);
    }

    public function full_process() {

        $this->provider->preprocess();

        $this->process_all();

        $this->handle_pending_sections();

        $this->handle_processed_sections();

        $this->provider->postprocess();
    }

    public function process_all() {
        $now = strftime('%Y-%m-%d', time());

        // Only mark sections that *were* manifested to be pending
        // The provisioning process will mark those that were skipped to
        // be processed if necessary
        cps_section::update(
            array('status' => $this::PENDING),
            array('status' => $this::MANIFESTED)
        );

        $semesters = $this->provider->semester_source()->semesters($now);

        $processed_semesters = $this->process_semesters($semesters);

        foreach ($processed_semesters as $semester) {

            $courses = $this->provider->course_source()->courses($semester);

            $process_courses = $this->process_courses($semester, $courses);

            foreach ($process_courses as $course) {
                $this->process_enrollment($semester, $course);
            }
        }
    }

    public function process_semesters($semesters) {
        $processed = array();

        foreach ($semesters as $semester) {
            try {
                $params = array(
                    'year' => $semester->year,
                    'name' => $semester->name,
                    'campus' => $semester->campus,
                    'session_key' => $semester->session_key
                );

                $cps = cps_semester::upgrade_and_get($semester, $params);

                $cps->save();

                events_trigger('cps_semester_process', $cps);

                $processed[] = $cps;
            } catch (Exception $e) {
                $this->errors[] = $e->error;
            }
        }

        return $processed;
    }

    public function process_courses($semester, $courses) {
        $processed = array();

        foreach ($courses as $course) {
            try {
                $params = array(
                    'department' => $course->department,
                    'cou_number' => $course->cou_number
                );

                $cps_course = cps_course::upgrade_and_get($course, $params);

                $cps_course->save();

                events_trigger('cps_course_process', $cps_course);

                $processed_sections = array();
                foreach ($cps_course->sections as $section) {
                    $params = array(
                        'courseid' => $cps_course->id,
                        'semesterid' => $semester->id,
                        'sec_number' => $section->sec_number
                    );

                    $cps_section = cps_section::upgrade_and_get($section, $params);

                    $cps_section->courseid = $cps_course->id;
                    $cps_section->semesterid = $semester->id;
                    $cps_section->status = $this::PENDING;

                    $cps_section->save();

                    $processed_sections[] = $cps_section;
                }

                // Mutating sections tied to course
                $cps_course->sections = $processed_sections;

                $processed[] = $cps_course;
            } catch (Exception $e) {
                $this->errors[] = $e->error;
            }
        }

        return $processed;
    }

    /**
     * Could be used to process a single course upon request
     */
    public function process_enrollment($semester, $course) {
        $teacher_source = $this->provider->teacher_source();

        $student_source = $this->provider->student_source();

        foreach ($course->sections as $section) {
            $teachers = $teacher_source->teachers($semester, $course, $section);

            $students = $student_source->students($semester, $course, $section);

            try {
                $this->process_teachers($section, $teachers);

                $this->process_students($section, $students);

                // Process section only if teachers can be processed
                // take into consideration outside forces manipulating
                // processed numbers through event handlers
                $by_processed = array(
                    'status' => $this::PROCESSED,
                    'sectionid' => $section->id
                );

                $processed_teachers = cps_teacher::count($by_processed);

                if (!empty($processed_teachers)) {
                    $section->status = $this::PROCESSED;
                    $section->save();

                    events_trigger('cps_section_process', $section);
                }

            } catch (Exception $e) {
                $this->errors[] = $e->error;
            }
        }
    }

    public function process_teachers($section, $users) {
        return $this->fill_role('teacher', $section, $users, function($user) {
            return array('primary_flag' => $user->primary_flag);
        });
    }

    public function process_students($section, $users) {
        return $this->fill_role('student', $section, $users);
    }

    private function handle_pending_sections() {
        $sections = cps_section::get_all(array('status' => $this::PENDING));

        foreach ($sections as $section) {
            if ($section->is_manifested()) {
                $course = $section->moodle();

                $course->visible = 0;

                $DB->update_record('course', $course);

                events_trigger('cps_course_severed', $course);

                $section->idnumber = null;
            }
            $section->status = 'skipped';

            $section->save();
        }
    }

    private function handle_processed_sections() {
        $sections = cps_section::get_all(array('status' => $this::PROCESSED));

        foreach ($sections as $section) {
            $semester = $section->semester();

            $course = $section->course();

            $success = $this->manifestation($semester, $course, $section);

            if ($success) {
                $section->status = $this::MANIFESTED;
                $section->save();
            }
        }
    }

    private function manifestation($semester, $course, $section) {
        // Check for instructor changes
        $teacher_params = array(
            'sectionid' => $section->id,
            'primary_flag' => 1
        );

        $new_primary = cps_teacher::get($teacher_params + array(
            'status' => $this::PROCESSED
        ));

        $old_primary = cps_teacher::get($teacher_params + array(
            'status' => $this::PENDING
        ));

        // Campuses may want to handle primary instructor changes differently
        if ($new_primary and $old_primary) {
            events_trigger('cps_primary_change', array(
                'section' => $section,
                'old_teacher' => $old_teacher,
                'new_teacher' => $new_teacher
            ));
        }

        // For certain we are working with a real course
        $moodle_course = $this->manifest_course($semester, $course, $section);

        $this->manifest_course_enrollment($moodle_course, $course, $section);

        return true;
    }

    private function manifest_course_enrollment($moodle_course, $course, $section) {
        $group = $this->manifest_group($moodle_course, $course, $section);

        $general_params = array('sectionid' => $section->id);

        $actions = array(
            $this::PENDING => 'unenroll',
            $this::PROCESSED => 'enroll'
        );

        foreach (array('teacher', 'student') as $type) {
            $class = 'cps_' . $type;

            foreach ($actions as $status => $action) {
                $action_params = $general_params + array('status' => $status);
                $action_count = $class::count($action_params);

                if ($action_count) {
                    $to_action = $class::get_all($action_params);
                    $this->{$action . '_users'}($group, $to_action);
                }
            }
        }

        global $DB;

        $count_params = array('groupid' => $group->id);
        if (!$DB->count_records('groups_members', $count_params)) {
            $event_params = array(
                'section' => $section,
                'group' => $group->id
            );
            events_trigger('cps_group_emptied', $event_params);
        }
    }

    private function enroll_users($group, $users) {
        $instance = $this->get_instance($group->courseid);

        foreach ($users as $user) {
            $shortname = $this->determine_role($user);
            $roleid = $this->setting($shortname . '_role');

            $this->enrol_user($instance, $user->userid, $roleid);

            groups_add_member($group->id, $user->userid);

            $user->status = $this::ENROLLED;
            $user->save();

            $event_params = array(
                'group' => $group,
                'cps_user' => $user
            );

            events_trigger('cps_' . $shortname . '_enroll', $event_params);
        }
    }

    private function unenroll_users($group, $users) {
        $instance = $this->get_instance($group->courseid);

        foreach ($users as $user) {
            $shortname = $this->determine_role($user);
            $roleid = $this->setting($shortname . '_role');

            $this->unenrol_user($instance, $user->userid, $roleid);

            groups_remove_member($group->id, $user->userid);

            $user->status = $this::UNENROLLED;
            $user->save();

            $event_params = array(
                'group' => $group,
                'cps_user' => $user
            );

            events_trigger('cps_' . $shortname . '_enroll', $event_params);
        }
    }

    private function manifest_group($moodle_course, $course, $section) {
        global $DB;

        $group_params = array(
            'courseid' => $moodle_course->id,
            'name' => "{$course->department} {$course->cou_number} {$section->sec_number}"
        );

        if (!$group = $DB->get_records('groups', $group_params)) {
            $group = (object) $group_params;
            $group->id = groups_create_group($group);
        }

        return $group;
    }

    private function manifest_course($semester, $course, $section) {
        global $DB;

        $teacher_params = array(
            'sectionid' => $section->id,
            'primary_flag' => 1,
            'status' => $this::PROCESSED
        );

        $primary_teacher = cps_teacher::get($teacher_params);

        $assumed_idnumber = $semester->year . $semester->name .
            $course->department . $semester->session_key . $course->cou_number .
            $primary_teacher->userid;

        // Take into consideration of outside forces manipulating idnumbers
        // Therefore we must check the section's idnumber before creating one
        // Possibility the course was deleted externally

        $idnumber = $section->idnumber ? $section->idnumber : $assumed_idnumber;

        $course_params = array('idnumber' => $idnumber);

        $moodle_course = $DB->get_record('course', $course_params);

        if (!$moodle_course) {
            $user = $primary_teacher->user();

            $session = empty($semester->session_key) ? '' :
                '(' . $semester->session_key . ')';

            $assumed_fullname = sprintf('%s %s %s %s %s for %s', $semester->year,
                $semester->name, $course->department, $session, $course->cou_number,
                fullname($user));

            $category = $this->manifest_category($course);

            $a = new stdclass;
            $a->year = $semester->year;
            $a->name = $semester->name;
            $a->department = $course->department;
            $a->course_number = $course->cou_number;
            $a->fullname = fullname($user);

            $shortname = $this->setting('course_shortname');

            foreach (get_object_vars($a) as $key => $value) {
                $shortname = preg_replace('/\{' . $key . '\}/', $value, $shortname);
            }

            $moodle_course->idnumber = $idnumber;
            $moodle_course->shortname = $shortname;
            $moodle_course->fullname = $assumed_fullname;
            $moodle_course->category = $category->id;
            $moodle_course->summary = $course->fullname;
            $moodle_course->startdate = $semester->classes_start;
            $moodle_course->visible = $this->setting('course_visible');
            //TODO: make more general course creation settings

            $moodle_course = create_course($moodle_course);

            $this->add_instance($moodle_course);

            events_trigger('cps_course_create', $moodle_course);
        }

        if (!$section->idnumber) {
            $section->idnumber = $moodle_course->idnumber;
            $section->save();
        }

        return $moodle_course;
    }

    private function manifest_category($course) {
        global $DB;

        $cat_params = array('name' => $course->department);
        $category = $DB->get_record('course_categories', $cat_params);

        if (!$category) {
            $category = new stdClass;

            $category->name = $course->department;
            $category->sortorder = 999;
            $category->parent = 0;
            $category->description = 'Courses under ' . $course->department;
            $category->id = $DB->insert_record('course_categories', $category);
        }

        return $category;
    }

    private function create_user($u) {
        $by_idnumber = array('idnumber' => $u->idnumber);

        $by_username = array('username' => $u->username);

        $exact_params = $by_idnumber + $by_username;

        $user = cps_user::upgrade($u);

        if ($prev = cps_user::get($exact_params, true)) {
            $user->id = $prev->id;
        } else if ($prev = cps_user::get($by_idnumber, true)) {
            $user->id = $prev->id;
        } else if ($prev = cps_user::get($by_username, true)) {
            $user->id = $prev->id;
        } else {
            $user->email = $user->username . $this->setting('user_email');
            $user->confirmed = $this->setting('user_confirm');
            $user->city = $this->setting('user_city');
            $user->country = $this->setting('user_country');
            $user->firstaccess = time();

            $created = true;
        }

        $user->save();

        // TODO: should we fire updated ???
        if (!empty($created)) {
            events_trigger('user_created', $user);
        } else if ($prev and
            (fullname($prev) != fullname($user) and
            $prev->username != $user->username and
            $prev->idnumber != $user->idnumber)) {

            events_trigger('user_updated', $user);
        }

        return $user;
    }

    private function fill_role($type, $section, $users, $extra_params = null) {
        $class = 'cps_'.$type;

        foreach ($users as $user) {
            $cps_user = $this->create_user($user);

            $params = array(
                'sectionid' => $section->id,
                'userid' => $cps_user->id
            );

            if ($extra_params) {
                $params += $extra_params($cps_user);
            }

            $cps_type = $class::upgrade($cps_user);

            unset($cps_type->id);

            if ($prev = $class::get($params, true)) {
                $cps_type->id = $prev->id;
            }

            $cps_type->userid = $cps_user->id;
            $cps_type->sectionid = $section->id;
            $cps_type->status = $this::PROCESSED;

            $cps_type->save();

            events_trigger($class . '_process', $cps_type);
        }

    }

    private function get_instance($courseid) {
        global $DB;

        $instances = enrol_get_instances($courseid, true);

        $attempt = array_filter($instances, function($in) {
            return $in->enrol == 'cps';
        });

        // Cannot enrol without an instance
        if (empty($attempt)) {
            $course_params = array('id' => $courseid);
            $course = $DB->get_record('course', $course_params, MUST_EXIST);

            $id = $this->add_instance($course);

            return $DB->get_record('enrol', array('id' => $id));
        } else {
            return current($attempt);
        }

    }

    private function determine_role($user) {
        if (isset($user->primary_flag)) {
            $role = $user->primary_flag ? 'editingteacher' : 'teacher';
        } else {
            $role = 'student';
        }

        return $role;
    }

    public static function gen_str() {
        return function ($key, $a=null) {
            return get_string($key, 'enrol_cps', $a);
        };
    }

    public static function _s($key, $a=null) {
        return get_string($key, 'enrol_cps', $a);
    }

    public static function plugin_base() {
        return self::base('plugins');
    }

    public static function base($dir='') {
        return dirname(__FILE__) . (empty($dir) ? '' : '/'.$dir);
    }

    public static function list_plugins() {

        $base = self::plugin_base();

        $all_files_folders = scandir($base);

        $plugins = array_filter($all_files_folders, function ($file) use ($base) {
            return is_dir($base . '/' . $file) and !preg_match('/^\./', $file);
        });

        if (empty($plugins)) {
            return array();
        }

        $provide_append = function ($name) {
            return enrol_cps_plugin::_s("{$name}_name");
        };

        return array_combine($plugins, array_map($provide_append, $plugins));
    }

    public static function provider_class() {
        $provider_name = get_config('enrol_cps', 'enrollment_provider');

        if (!$provider_name) {
            return false;
        }

        $class_file = self::plugin_base() . '/' . $provider_name . '/provider.php';

        if (!file_exists($class_file)) {
            return false;
        }

        // Require library code
        $lib_base = self::base('classes');
        require_once $lib_base . '/provider.php';
        require_once $lib_base . '/processors.php';

        // Require client code
        require_once $class_file;

        $provider_class = "{$provider_name}_enrollment_provider";

        return $provider_class;
    }

    public static function create_provider() {
        $provider_class = self::provider_class();

        return $provider_class ? new $provider_class() : false;
    }

    public static function translate_error($e) {
        $provider_class = self::provider_class();
        $provider_name = $provider_class::get_name();

        $problem = self::_s($provider_name . '_' . $e->getMessage());

        $a = new stdClass;
        $a->pluginname = self::_s($provider_name.'_name');
        $a->problem = $problem;

        return $a;
    }
}

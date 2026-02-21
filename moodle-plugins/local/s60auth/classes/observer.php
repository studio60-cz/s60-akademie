<?php
namespace local_s60auth;

defined('MOODLE_INTERNAL') || die();

class observer {

    /**
     * Triggered on every user login.
     * 1. Enforces auth='oauth2' for SSO users
     * 2. Syncs course enrollments from BadWolf
     */
    public static function on_user_loggedin(\core\event\user_loggedin $event) {
        global $DB;

        $user = \core_user::get_user($event->userid);

        // Only process users who came in via S60Auth (have idnumber = S60Auth sub)
        if (empty($user->idnumber)) {
            return;
        }

        // ── 1. Enforce auth = 'oauth2' ────────────────────────────────────────
        // Imported users may have auth='manual' — fix it so they can't use password login
        if ($user->auth !== 'oauth2') {
            $DB->set_field('user', 'auth', 'oauth2', ['id' => $user->id]);
        }

        // ── 2. Sync enrollments from BadWolf ──────────────────────────────────
        $bw_url = get_config('local_s60auth', 'bw_api_url');
        $bw_key = get_config('local_s60auth', 'bw_api_key');

        if (empty($bw_url) || empty($bw_key)) {
            // Plugin not configured yet — skip enrollment sync
            return;
        }

        try {
            self::sync_enrollments($user, $bw_url, $bw_key);
        } catch (\Exception $e) {
            // Never block login — just log the error
            error_log('local_s60auth: enrollment sync failed for user ' . $user->idnumber . ': ' . $e->getMessage());
        }
    }

    /**
     * Sync user's Moodle enrollments with BadWolf.
     * BW is source of truth: adds missing, removes extra.
     */
    private static function sync_enrollments($user, $bw_url, $bw_key) {
        // ── Načti kurzy z BadWolf ──────────────────────────────────────────────
        // Endpoint: GET /api/online-courses/by-s60-user/:s60UserId
        // Response: [{ moodleId: 5 }, { moodleId: 12 }, ...]
        $endpoint = rtrim($bw_url, '/') . '/online-courses/by-s60-user/' . urlencode($user->idnumber);

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'X-API-Key: ' . $bw_key,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 5,
        ]);
        $response      = curl_exec($ch);
        $http_code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error    = curl_error($ch);
        curl_close($ch);

        if ($curl_error) {
            throw new \Exception('cURL error: ' . $curl_error);
        }
        if ($http_code !== 200) {
            throw new \Exception('BW API returned HTTP ' . $http_code);
        }

        $bw_data = json_decode($response, true);
        if (!is_array($bw_data)) {
            throw new \Exception('BW API returned invalid JSON');
        }

        // Sestav set Moodle course ID z BW (jen ty, které mají moodleId)
        $bw_moodle_ids = [];
        foreach ($bw_data as $item) {
            if (!empty($item['moodleId'])) {
                $bw_moodle_ids[] = (int) $item['moodleId'];
            }
        }

        // ── Načti aktuální Moodle enrollmenty uživatele ───────────────────────
        $current_courses = enrol_get_users_courses($user->id, true); // true = jen aktivní
        $current_moodle_ids = array_keys($current_courses);

        // ── Přidat chybějící (v BW, ale ne v Moodle) ─────────────────────────
        $to_enroll = array_diff($bw_moodle_ids, $current_moodle_ids);
        foreach ($to_enroll as $courseid) {
            self::enroll_user($user->id, $courseid);
        }

        // ── Odebrat přebytečné (v Moodle, ale ne v BW) ───────────────────────
        $to_unenroll = array_diff($current_moodle_ids, $bw_moodle_ids);
        foreach ($to_unenroll as $courseid) {
            self::unenroll_user($user->id, $courseid);
        }
    }

    /**
     * Zapíše uživatele do kurzu (role: student).
     */
    private static function enroll_user($userid, $courseid) {
        global $DB;

        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course) {
            error_log('local_s60auth: course ' . $courseid . ' not found in Moodle, skipping enroll');
            return;
        }

        $enrol     = enrol_get_plugin('manual');
        $instances = enrol_get_instances($courseid, true);

        foreach ($instances as $instance) {
            if ($instance->enrol === 'manual') {
                $enrol->enrol_user($instance, $userid, 5); // 5 = student role
                return;
            }
        }

        // Pokud manual instance neexistuje, vytvoříme ji
        $instanceid = $enrol->add_instance($course);
        $instance   = $DB->get_record('enrol', ['id' => $instanceid]);
        $enrol->enrol_user($instance, $userid, 5);
    }

    /**
     * Odepíše uživatele z kurzu.
     */
    private static function unenroll_user($userid, $courseid) {
        $enrol     = enrol_get_plugin('manual');
        $instances = enrol_get_instances($courseid, true);

        foreach ($instances as $instance) {
            if ($instance->enrol === 'manual') {
                $enrol->unenrol_user($instance, $userid);
                return;
            }
        }
    }
}

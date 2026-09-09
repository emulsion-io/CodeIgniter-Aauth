<?php
// Standalone regression tests: php tests/login_fast.php
define('BASEPATH', __DIR__);
require __DIR__ . '/../application/libraries/Aauth.php';

function log_message($level, $message) {
    $GLOBALS['audit'][] = $message;
}

class FastLoginSession {
    public $data = ['id' => 9, 'loggedin' => true];
    public $rotations = 0;
    public function userdata($key) { return $this->data[$key] ?? null; }
    public function set_userdata($data) { $this->data = array_merge($this->data, $data); }
    public function unset_userdata($keys) {
        foreach ($keys as $key) { unset($this->data[$key]); }
    }
    public function sess_regenerate($destroy) {
        check($destroy === true, 'Old session must be invalidated');
        $this->rotations++;
    }
}

class FastLoginHarness extends Aauth {
    public $users;
    public $admin = true;
    public $updates = [];
    public function __construct() {
        $this->CI = (object) [
            'session' => new FastLoginSession(),
            'lang' => new class {
                public function line($key) { return $key; }
            },
        ];
        $this->config_vars = ['remove_successful_attempts' => false];
        foreach ([9, 1] as $id) {
            $this->users[$id] = (object) [
                'id' => $id, 'username' => 'user' . $id,
                'email' => $id . '@example.com', 'banned_at' => null,
                'email_verified_at' => '2026-09-09', 'totp_secret' => 'enabled',
            ];
        }
    }
    public function is_admin($user_id = false) {
        return $this->admin && $this->CI->session->userdata('id') === 9;
    }
    public function get_user($user_id = false) {
        return $this->users[$user_id === false ? $this->CI->session->userdata('id') : $user_id] ?? false;
    }
    public function error($message = '', $flashdata = false) { $this->errors[] = $message; }
    public function update_last_login($user_id = false) { $this->updates[] = ['login', $user_id]; }
    public function update_activity($user_id = false) { $this->updates[] = ['activity', $user_id]; }
}

function check($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
}

$cases = [
    'anonymous' => function ($a) { $a->CI->session->data['loggedin'] = false; },
    'non-admin' => function ($a) { $a->admin = false; },
    'pending TOTP' => function ($a) { $a->CI->session->data['totp_required'] = true; },
    'missing admin' => function ($a) { unset($a->users[9]); },
    'banned admin' => function ($a) { $a->users[9]->banned_at = '2026-09-09'; },
    'unverified admin' => function ($a) { $a->users[9]->email_verified_at = null; },
    'missing target' => function ($a) { unset($a->users[1]); },
    'banned target' => function ($a) { $a->users[1]->banned_at = '2026-09-09'; },
    'unverified target' => function ($a) { $a->users[1]->email_verified_at = null; },
];
foreach ($cases as $name => $prepare) {
    $a = new FastLoginHarness();
    $prepare($a);
    $before = $a->CI->session->data;
    check($a->login_fast(1) === false, $name . ' must fail');
    check($a->CI->session->data === $before && $a->CI->session->rotations === 0 && !$a->updates, $name . ' must preserve session and timestamps');
}
foreach ([0, -1, false, true, null, [], new stdClass(), 1.5, '', '1abc', '1 OR 1=1'] as $id) {
    $a = new FastLoginHarness();
    check($a->login_fast($id) === false, 'Invalid ID must fail');
    check($a->CI->session->userdata('id') === 9 && $a->CI->session->rotations === 0, 'Invalid ID must preserve session');
}
foreach ([1, '1'] as $id) {
    $a = new FastLoginHarness();
    $a->CI->session->data['totp_user_id'] = 7;
    check($a->login_fast($id) === true, 'Admin switch must succeed');
    check($a->CI->session->data === ['id' => 1, 'loggedin' => true, 'username' => 'user1', 'email' => '1@example.com'], 'Target identity and pending TOTP cleanup');
    check($a->CI->session->rotations === 1, 'Session must rotate once');
    check($a->updates === [['login', 1], ['activity', 1]], 'Target timestamps must update');
    check(str_contains(end($GLOBALS['audit']), 'administrator 9 switched to user 1'), 'Audit must identify both accounts');
    check($a->login_fast(9) === false, 'Switched non-admin must lose admin ability');
}
echo "login_fast: 23 scenarios passed\n";

<?php
// Standalone integration tests: php -d extension=sqlite3 tests/bulk_pm.php
define('BASEPATH', __DIR__ . '/../demo/test/system/');
require BASEPATH . 'database/DB.php';
require __DIR__ . '/../application/libraries/Aauth.php';

function log_message($level, $message) {}
function check($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
}

class BulkPmHarness extends Aauth {
    public $admin = false;
    public function is_admin($user_id = false) {
        check($user_id === 9, 'Admin authorization must check the caller, not the target');
        return $this->admin;
    }
    public function __construct() {
        $this->CI = (object) ['session' => new class {
            public $data = ['id' => 9, 'loggedin' => true];
            public function userdata($key) { return $this->data[$key] ?? null; }
        }];
        $this->config_vars = ['pms' => 'messages'];
        $this->aauth_db = DB(['dbdriver' => 'sqlite3', 'database' => ':memory:', 'db_debug' => false]);
        $this->aauth_db->query('CREATE TABLE messages (
            id INTEGER PRIMARY KEY, sender_id INTEGER, receiver_id INTEGER,
            date_read TEXT, pm_deleted_sender INTEGER, pm_deleted_receiver INTEGER
        )');
        foreach ([
            [1, 2, 9, null, null, null],
            [2, 9, 2, null, null, null],
            [3, 2, 9, '2020-01-01 00:00:00', null, null],
            [4, 2, 9, null, null, 1],
            [5, 9, 2, null, 1, null],
            [6, 2, 3, null, null, null],
            [7, 2, 9, null, 1, null],
            [8, 9, 2, null, null, 1],
        ] as $row) {
            $this->aauth_db->query('INSERT INTO messages VALUES (?, ?, ?, ?, ?, ?)', $row);
        }
    }
    public function rows() {
        return $this->aauth_db->order_by('id')->get('messages')->result_array();
    }
}

foreach (['set_as_read_all_pm', 'delete_all_pm'] as $method) {
    foreach ([null, true, 0, -1, 2.5, '2abc', '2 OR 1=1', '', [], new stdClass(), '999999999999999999999999'] as $id) {
        $a = new BulkPmHarness();
        $a->admin = true;
        $before = $a->rows();
        check($a->$method($id) === false && $a->rows() === $before, $method . ' must reject invalid target IDs');
    }
    $a = new BulkPmHarness();
    $before = $a->rows();
    check($a->$method(2) === false && $a->rows() === $before, 'Non-admin cannot target another user');
    foreach ([9, '9', false] as $id) {
        $a = new BulkPmHarness();
        check($a->$method($id) === true, 'Non-admin can explicitly target own mailbox');
    }
    $a = new BulkPmHarness();
    $a->admin = true;
    $a->CI->session->data['loggedin'] = false;
    $before = $a->rows();
    check($a->$method(2) === false && $a->rows() === $before, 'Admin must be logged in');
    foreach ([['id' => 9, 'loggedin' => false], ['loggedin' => true], ['id' => 0, 'loggedin' => true]] as $session) {
        $a = new BulkPmHarness();
        $a->CI->session->data = $session;
        $before = $a->rows();
        check($a->$method() === false && $a->rows() === $before, $method . ' must reject invalid sessions');
    }
}

foreach ([2, '2'] as $id) {
    $a = new BulkPmHarness();
    $a->admin = true;
    $before = array_column($a->rows(), null, 'id');
    check($a->set_as_read_all_pm($id) === true, 'Admin can mark target inbox read');
    $after = array_column($a->rows(), null, 'id');
    foreach ([2, 5] as $messageId) {
        check($after[$messageId]['date_read'] !== null, 'Target unread messages become read');
    }
    foreach ([1, 3, 4, 6, 7, 8] as $messageId) {
        check($after[$messageId] === $before[$messageId], 'Messages outside target visible inbox remain unchanged');
    }

    $a = new BulkPmHarness();
    $a->admin = true;
    check($a->delete_all_pm($id) === true, 'Admin can delete target mailbox');
    $after = array_column($a->rows(), null, 'id');
    check(!isset($after[4]) && !isset($after[5]), 'Target deletion removes messages already deleted by counterpart');
    foreach ([1, 3, 6] as $messageId) {
        check($after[$messageId]['pm_deleted_sender'] == 1 && $after[$messageId]['pm_deleted_receiver'] === null,
            'Target outbox deletion preserves counterpart inbox');
    }
    check($after[2]['pm_deleted_receiver'] == 1 && $after[2]['pm_deleted_sender'] === null,
        'Target inbox deletion preserves counterpart outbox');
    foreach ([7, 8] as $messageId) {
        check($after[$messageId] === $before[$messageId], 'Already hidden target messages remain unchanged');
    }
}

$a = new BulkPmHarness();
$before = array_column($a->rows(), null, 'id');
check($a->set_as_read_all_pm() === true, 'Mark all read succeeds');
$after = array_column($a->rows(), null, 'id');
foreach ([1, 7] as $id) {
    check($after[$id]['date_read'] !== null, 'Visible unread inbox messages become read');
}
foreach ([2, 3, 4, 5, 6, 8] as $id) {
    check($after[$id] === $before[$id], 'Other messages and existing read dates remain unchanged');
}
check($a->count_unread_pms() === 0, 'Unread count becomes zero');
check($a->set_as_read_all_pm() === true, 'Already-read mailbox succeeds');

$a = new BulkPmHarness();
$before = array_column($a->rows(), null, 'id');
check($a->delete_all_pm() === true, 'Delete all succeeds');
$after = array_column($a->rows(), null, 'id');
check(!isset($after[7]) && !isset($after[8]), 'Messages deleted by both participants are removed');
foreach ([1, 3] as $id) {
    check($after[$id]['pm_deleted_receiver'] == 1 && $after[$id]['pm_deleted_sender'] === null,
        'Inbox deletion preserves sender copy');
}
check($after[2]['pm_deleted_sender'] == 1 && $after[2]['pm_deleted_receiver'] === null,
    'Outbox deletion preserves receiver copy');
foreach ([4, 5, 6] as $id) {
    check($after[$id] === $before[$id], 'Hidden messages and other users remain unchanged');
}
check($a->count_unread_pms() === 0, 'Deleted mailbox has no unread messages');
check($a->delete_all_pm() === true && array_column($a->rows(), null, 'id') === $after,
    'Repeated deletion is a successful no-op');

$a = new BulkPmHarness();
$before = $a->rows();
$a->aauth_db->query("CREATE TRIGGER fail_delete BEFORE UPDATE ON messages
    WHEN OLD.id = 3 BEGIN SELECT RAISE(ABORT, 'injected failure'); END");
// SQLite emits a warning for the intentionally failed query; CI returns false.
check(@$a->delete_all_pm() === false, 'Database failure is reported');
check($a->rows() === $before, 'Failure rolls back preceding deletions');

$a = new BulkPmHarness();
$a->aauth_db->query('DROP TABLE messages');
check(@$a->delete_all_pm() === false, 'Selection failure is reported');

echo "Bulk PM integration tests passed.\n";

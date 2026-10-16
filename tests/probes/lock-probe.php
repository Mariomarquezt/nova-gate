<?php
/**
 * Branch probe for includes/lock.php with a fake $wpdb (no WordPress, no database).
 * Usage: php tests/probes/lock-probe.php includes/lock.php   (exit 0 = all branches behave).
 */
namespace { define('ABSPATH', '/'); function get_current_blog_id() { return 1; } function wp_cache_delete() {} function get_option($n, $d) { return 'from-get_option'; } function maybe_unserialize($v) { return $v; }
class FakeDb { public $last_error = ''; public $options = 'wp_options'; public $mode; public $released = 0; public $row;
  function suppress_errors($s) { return false; } function prepare($q, ...$a) { return $q; }
  function get_var($q) { switch ($this->mode) { case 'ok': $this->last_error=''; return '1'; case 'busy': $this->last_error=''; return '0'; case 'null': $this->last_error=''; return null; case 'err': $this->last_error='Lost connection'; return null; case 'sqlite': $this->last_error='no such function: GET_LOCK'; return null; } }
  function query($q) { $this->released++; $this->last_error=''; }
  function get_row($q) { if ($this->row === 'err') { $this->last_error = 'Deadlock'; return null; } $this->last_error=''; return $this->row; } }
}
namespace NovaGate { require $argv[1];
  global $wpdb; $wpdb = new \FakeDb(); $ok = 0; $bad = 0;
  $t = function ($label, $exp, $got) use (&$ok, &$bad) { if ($exp === $got) { $ok++; } else { $bad++; echo "FAIL $label: expected $exp got $got\n"; } };
  $run = fn() => with_lock('keys', fn() => 'RAN', fn() => 'BUSY');
  foreach (['ok' => 'RAN', 'busy' => 'BUSY', 'null' => 'BUSY', 'err' => 'BUSY', 'sqlite' => 'RAN'] as $m => $exp) { $wpdb->mode = $m; $t("lock $m", $exp, $run()); }
  $wpdb->mode = 'ok'; $wpdb->released = 0; $run(); $t('released after run', 1, $wpdb->released);
  $wpdb->mode = 'busy'; $wpdb->released = 0; $run(); $t('no release when busy', 0, $wpdb->released);
  $wpdb->row = null; $t('absent row -> null', null, fresh_option('x'));
  $wpdb->row = (object) ['option_value' => 'v']; $t('row -> value', 'v', fresh_option('x'));
  $wpdb->row = 'err'; $wpdb->mode = 'ok'; $wpdb->released = 0;
  $t('failed read inside lock -> busy', 'BUSY', with_lock('tries', fn() => fresh_option('x') ?? 'EMPTY', fn() => 'BUSY'));
  $t('lock released after failed read', 1, $wpdb->released);
  $wpdb->mode = 'sqlite'; $t('failed read unlocked path -> busy', 'BUSY', with_lock('tries', fn() => fresh_option('x') ?? 'EMPTY', fn() => 'BUSY'));
  echo "probe: $ok ok, $bad failed\n"; exit($bad ? 1 : 0); }

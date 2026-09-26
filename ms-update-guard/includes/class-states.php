<?php
/**
 * Run states and allowed transitions (spec section 4).
 *
 * @package MSUpdateGuard
 */

namespace MSUpdateGuard;

defined( 'ABSPATH' ) || exit;

/**
 * State machine vocabulary.
 */
class States {

	const QUEUED         = 'QUEUED';
	const PREFLIGHT      = 'PREFLIGHT';
	const BACKUP_PENDING = 'BACKUP_PENDING';
	const BACKUP_READY   = 'BACKUP_READY';
	const UPDATE_RUNNING = 'UPDATE_RUNNING';
	const VERIFYING      = 'VERIFYING';
	const TESTING        = 'TESTING';

	const PASS    = 'PASS';
	const FAIL    = 'FAIL';
	const UNKNOWN = 'UNKNOWN';
	const BLOCKED = 'BLOCKED';

	const SEVERITY_INFO     = 'INFO';
	const SEVERITY_WARNING  = 'WARNING';
	const SEVERITY_ERROR    = 'ERROR';
	const SEVERITY_CRITICAL = 'CRITICAL';

	/**
	 * Allowed transitions. Terminal states have no outgoing edge; a new run is needed.
	 *
	 * @var array<string,string[]>
	 */
	const TRANSITIONS = array(
		self::QUEUED         => array( self::PREFLIGHT, self::BLOCKED ),
		self::PREFLIGHT      => array( self::BACKUP_PENDING, self::BLOCKED ),
		self::BACKUP_PENDING => array( self::BACKUP_READY, self::BLOCKED ),
		self::BACKUP_READY   => array( self::UPDATE_RUNNING, self::BLOCKED ),
		self::UPDATE_RUNNING => array( self::VERIFYING ),
		self::VERIFYING      => array( self::TESTING, self::UNKNOWN ),
		self::TESTING        => array( self::PASS, self::FAIL, self::UNKNOWN ),
	);

	/**
	 * Terminal states.
	 *
	 * @return string[]
	 */
	public static function terminal() {
		return array( self::PASS, self::FAIL, self::UNKNOWN, self::BLOCKED );
	}

	/**
	 * States in which the child site may be touched by this run.
	 *
	 * @return string[]
	 */
	public static function active() {
		return array( self::PREFLIGHT, self::BACKUP_PENDING, self::BACKUP_READY, self::UPDATE_RUNNING, self::VERIFYING, self::TESTING );
	}

	/**
	 * Whether a transition is allowed.
	 *
	 * @param string $from From state.
	 * @param string $to   To state.
	 * @return bool
	 */
	public static function can( $from, $to ) {
		return isset( self::TRANSITIONS[ $from ] ) && in_array( $to, self::TRANSITIONS[ $from ], true );
	}

	/**
	 * Whether a terminal state keeps the site locked until a person resolves it.
	 *
	 * BLOCKED happens before any update, so the site is unchanged and may be retried.
	 * FAIL and UNKNOWN follow an update attempt and need a human look.
	 *
	 * @param string $state State.
	 * @return bool
	 */
	public static function holds_site_lock( $state ) {
		return ! in_array( $state, array( self::PASS, self::BLOCKED ), true );
	}

	/**
	 * Whether the state is terminal.
	 *
	 * @param string $state State.
	 * @return bool
	 */
	public static function is_terminal( $state ) {
		return in_array( $state, self::terminal(), true );
	}
}

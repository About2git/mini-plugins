<?php
/**
 * Terminal result and alert priority of a run (spec 4 "Terminalstatus", spec 6 "Prioritäten").
 *
 * @package MSUpdateGuard
 */

namespace MSUpdateGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Pure function over the collected evidence. PASS needs positive proof on every axis;
 * anything missing or contradictory is UNKNOWN, never PASS.
 */
class Outcome {

	/**
	 * Compare two versions as the child reports them.
	 *
	 * @param string $actual Actual version.
	 * @param string $target Target version.
	 * @param string $from   Version before the update.
	 * @return string updated|unchanged|other|missing
	 */
	public static function version_verdict( $actual, $target, $from ) {
		if ( null === $actual || '' === (string) $actual ) {
			return 'missing';
		}
		if ( '' !== (string) $target && self::same_version( $actual, $target ) ) {
			return 'updated';
		}
		if ( '' !== (string) $from && self::same_version( $actual, $from ) ) {
			return 'unchanged';
		}
		return 'other';
	}

	/**
	 * Version equality that treats 5.1 and 5.1.0 as the same release.
	 *
	 * @param string $a Version.
	 * @param string $b Version.
	 * @return bool
	 */
	public static function same_version( $a, $b ) {
		$strip = function ( $v ) {
			return preg_replace( '/(\.0+)+$/', '', trim( (string) $v ) );
		};
		return 0 === version_compare( $strip( $a ), $strip( $b ) );
	}

	/**
	 * Evaluate.
	 *
	 * @param array $components Components with slug.
	 * @param array $update     status (responded|no_result|error), updated[], errors{slug:msg}, site_error.
	 * @param array $verify     status (ok|sync_failed), components{slug:{verdict,actual}}.
	 * @param array $test       status (result|no_result|trigger_failed), http, smoke, smoke_mandatory.
	 * @return array state, severity, reasons[]
	 */
	public static function evaluate( array $components, array $update, array $verify, array $test ) {
		$reasons = array();

		$http  = isset( $test['http'] ) ? $test['http'] : 'HTTP_UNKNOWN';
		$smoke = isset( $test['smoke'] ) ? $test['smoke'] : 'SMOKE_UNKNOWN';
		if ( ! isset( $test['status'] ) || 'result' !== $test['status'] ) {
			$http      = 'HTTP_UNKNOWN';
			$smoke     = 'SMOKE_UNKNOWN';
			$reasons[] = 'no_runner_result';
		}

		$update_status = isset( $update['status'] ) ? $update['status'] : 'no_result';
		$updated       = isset( $update['updated'] ) ? (array) $update['updated'] : array();
		$errors        = isset( $update['errors'] ) ? (array) $update['errors'] : array();
		if ( ! empty( $update['site_error'] ) ) {
			$reasons[] = 'update_site_error';
		}
		if ( 'responded' !== $update_status ) {
			$reasons[] = 'update_' . $update_status;
		}

		$verdicts = array();
		$sync_ok  = isset( $verify['status'] ) && 'ok' === $verify['status'];
		if ( ! $sync_ok ) {
			$reasons[] = 'version_resync_failed';
		}
		foreach ( $components as $component ) {
			$slug = $component['slug'];
			$v    = $sync_ok && isset( $verify['components'][ $slug ]['verdict'] ) ? $verify['components'][ $slug ]['verdict'] : 'missing';

			$verdicts[ $slug ] = $v;
			$claimed_ok        = in_array( $slug, $updated, true );
			$claimed_error     = isset( $errors[ $slug ] );

			if ( 'updated' !== $v ) {
				$reasons[] = 'version_' . $v . ':' . $slug;
			}
			if ( $claimed_ok && 'unchanged' === $v ) {
				$reasons[] = 'response_success_but_version_unchanged:' . $slug;
			}
			if ( $claimed_error && 'updated' === $v ) {
				$reasons[] = 'response_error_but_version_changed:' . $slug;
			}
			if ( $claimed_error ) {
				$reasons[] = 'update_error:' . $slug;
			}
			if ( 'responded' === $update_status && ! $claimed_ok && ! $claimed_error ) {
				$reasons[] = 'no_component_result:' . $slug;
			}
		}

		$all_updated   = ! empty( $verdicts ) && ! in_array(
			false,
			array_map(
				function ( $v ) {
					return 'updated' === $v;
				},
				$verdicts
			),
			true
		);
		$any_unchanged = in_array( 'unchanged', $verdicts, true );
		$divergent     = (bool) preg_grep( '/^response_error_but_version_changed:/', $reasons );
		$explicit_fail = ! empty( $errors ) || ! empty( $update['site_error'] ) || 'error' === $update_status;
		$smoke_ok      = 'SMOKE_PASS' === $smoke || ( 'SMOKE_SKIPPED' === $smoke && isset( $test['smoke_mandatory'] ) && false === $test['smoke_mandatory'] );

		if ( 'HTTP_FAIL' === $http ) {
			$state = States::FAIL;
		} elseif ( 'SMOKE_FAIL' === $smoke ) {
			$state = States::FAIL;
		} elseif ( $divergent ) {
			$state = States::UNKNOWN;
		} elseif ( $sync_ok && $any_unchanged ) {
			// Proven: the target version is not installed, whatever the response claimed.
			$state = States::FAIL;
		} elseif ( $explicit_fail && $sync_ok && ! $all_updated ) {
			$state = States::FAIL;
		} elseif ( $all_updated && ! $explicit_fail && 'responded' === $update_status && 'HTTP_PASS' === $http && $smoke_ok && ! preg_grep( '/^no_component_result:/', $reasons ) ) {
			$state = States::PASS;
		} else {
			$state = States::UNKNOWN;
		}

		if ( 'HTTP_PASS' !== $http ) {
			$severity = States::SEVERITY_CRITICAL;
		} elseif ( States::PASS === $state ) {
			$severity = States::SEVERITY_INFO;
		} else {
			$severity = States::SEVERITY_ERROR;
		}

		if ( 'HTTP_PASS' !== $http ) {
			$reasons[] = strtolower( $http );
		}
		if ( ! $smoke_ok ) {
			$reasons[] = strtolower( $smoke );
		}

		return array(
			'state'    => $state,
			'severity' => $severity,
			'reasons'  => array_values( array_unique( $reasons ) ),
			'verdicts' => $verdicts,
		);
	}
}

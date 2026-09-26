<?php
/**
 * Executes updates through MainWP's single-site update abilities.
 *
 * @package MSUpdateGuard
 */

namespace MSUpdateGuard\Adapters;

use MSUpdateGuard\Update_Path_Guard;
use MSUpdateGuard\Util;

defined( 'ABSPATH' ) || exit;

/**
 * One call per component type. The response is recorded as a claim only; the
 * Version_Verifier decides what is actually installed (F06).
 */
class MainWP_Update_Executor implements Update_Executor {

	/**
	 * Gateway.
	 *
	 * @var MainWP_Gateway
	 */
	private $gateway;

	/**
	 * Constructor.
	 *
	 * @param MainWP_Gateway $gateway Gateway.
	 */
	public function __construct( MainWP_Gateway $gateway ) {
		$this->gateway = $gateway;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $site_id    Site id.
	 * @param array  $components Components.
	 * @param string $run_id     Run id.
	 * @return array
	 */
	public function start( $site_id, array $components, $run_id ) {
		$groups = array(
			'core'   => array(),
			'plugin' => array(),
			'theme'  => array(),
		);
		foreach ( $components as $c ) {
			if ( isset( $groups[ $c['type'] ] ) ) {
				$groups[ $c['type'] ][] = $c['slug'];
			}
		}

		$out = array(
			'status'     => 'responded',
			'updated'    => array(),
			'errors'     => array(),
			'site_error' => '',
			'calls'      => array(),
		);

		Update_Path_Guard::begin( $site_id, $run_id );
		try {
			// Order: core first, then plugins, then themes - the same order MainWP's bulk runner uses.
			if ( $groups['core'] ) {
				$this->collect( $out, 'core', $this->gateway->ability( 'mainwp/update-site-core-v1', array( 'site_id_or_domain' => (int) $site_id ) ), array( 'wordpress' ) );
			}
			if ( $groups['plugin'] ) {
				$this->collect(
					$out,
					'plugin',
					$this->gateway->ability(
						'mainwp/update-site-plugins-v1',
						array(
							'site_id_or_domain' => (int) $site_id,
							'slugs'             => $groups['plugin'],
						)
					),
					$groups['plugin']
				);
			}
			if ( $groups['theme'] ) {
				$this->collect(
					$out,
					'theme',
					$this->gateway->ability(
						'mainwp/update-site-themes-v1',
						array(
							'site_id_or_domain' => (int) $site_id,
							'slugs'             => $groups['theme'],
						)
					),
					$groups['theme']
				);
			}
		} finally {
			Update_Path_Guard::end();
		}

		if ( '' !== $out['site_error'] ) {
			$out['status'] = 'error';
		}
		return $out;
	}

	/**
	 * Merge one ability response.
	 *
	 * @param array  $out    Accumulator.
	 * @param string $type   Component type.
	 * @param mixed  $result Ability result.
	 * @param array  $slugs  Requested slugs.
	 * @return void
	 */
	private function collect( array &$out, $type, $result, array $slugs ) {
		if ( is_wp_error( $result ) ) {
			$message = Util::short( $result->get_error_code() . ': ' . $result->get_error_message(), 300 );
			if ( 'core' === $type ) {
				$out['errors']['wordpress'] = $message;
			} else {
				$out['site_error'] = $message;
			}
			$out['calls'][] = array(
				'type'  => $type,
				'error' => $message,
			);
			return;
		}
		if ( 'core' === $type ) {
			if ( isset( $result['updated']['slug'] ) ) {
				$out['updated'][] = 'WordPress';
			}
			$out['calls'][] = array(
				'type'    => $type,
				'claimed' => isset( $result['updated']['new_version'] ) ? Util::short( $result['updated']['new_version'], 40 ) : '',
			);
			return;
		}
		foreach ( (array) ( isset( $result['updated'] ) ? $result['updated'] : array() ) as $row ) {
			if ( isset( $row['slug'] ) && in_array( $row['slug'], $slugs, true ) ) {
				$out['updated'][] = $row['slug'];
			}
		}
		foreach ( (array) ( isset( $result['errors'] ) ? $result['errors'] : array() ) as $row ) {
			$slug = isset( $row['slug'] ) ? (string) $row['slug'] : '';
			$msg  = Util::short( ( isset( $row['code'] ) ? $row['code'] . ': ' : '' ) . ( isset( $row['message'] ) ? $row['message'] : '' ), 300 );
			if ( '' === $slug ) {
				$out['site_error'] = $msg;
			} else {
				$out['errors'][ $slug ] = $msg;
			}
		}
		$out['calls'][] = array(
			'type'          => $type,
			'total_updated' => isset( $result['summary']['total_updated'] ) ? (int) $result['summary']['total_updated'] : null,
			'total_errors'  => isset( $result['summary']['total_errors'] ) ? (int) $result['summary']['total_errors'] : null,
		);
	}
}

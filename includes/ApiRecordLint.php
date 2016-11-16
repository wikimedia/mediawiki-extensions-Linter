<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

namespace MediaWiki\Linter;

use ApiBase;
use FormatJson;
use IPSet\IPSet;
use JobQueueGroup;
use Title;

/**
 * API module for an external service to record
 * a lint error
 */
class ApiRecordLint extends ApiBase {

	public function execute() {
		global $wgLinterSubmitterWhitelist;

		$ipSet = new IPSet(
			array_keys( array_filter( $wgLinterSubmitterWhitelist ) )
		);
		if ( !$ipSet->match( $this->getRequest()->getIP() ) ) {
			$this->dieUsage(
				'Your IP address has not been whitelisted to report lint errors',
				'invalid-ip'
			);
		}
		$params = $this->extractRequestParams();
		$data = FormatJson::decode( $params['data'], true );
		if ( !is_array( $data ) ) {
			$this->dieUsage( 'Invalid data', 'invalid-data' );
		}

		$errors = [];
		$title = Title::newFromText( $params['page'] );
		if ( !$title || !$title->getArticleID()
			|| $title->getLatestRevID() != $params['revision']
		) {
			$this->dieUsage( 'Invalid, non-existent, or outdated title', 'invalid-title' );
		}
		foreach ( $data as $info ) {
			$info['params']['location'] = array_slice( $info['dsr'], 0, 2 );
			if ( isset( $info['templateInfo'] ) && $info['templateInfo'] ) {
				$info['params']['templateInfo'] = $info['templateInfo'];
			}
			$errors[] = $info;
		}

		$job = new RecordLintJob( $title, [
			'errors' => $errors,
			'revision' => $params['revision'],
		] );
		JobQueueGroup::singleton()->push( $job );
		$this->getResult()->addValue( $this->getModuleName(), 'success', true );
	}

	public function isInternal() {
		return true;
	}

	public function getAllowedParams() {
		return [
			'data' => 'string',
			'page' => 'string',
			'revision' => 'int',
		];
	}
}

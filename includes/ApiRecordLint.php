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
use Wikimedia\IPSet;

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
			$this->dieWithError( 'apierror-linter-invalid-ip', 'invalid-ip' );
		}
		$params = $this->extractRequestParams();
		$data = FormatJson::decode( $params['data'], true );
		if ( !is_array( $data ) ) {
			$this->dieWithError( 'apierror-linter-invalid-data', 'invalid-data' );
		}
		if ( Hooks::onParserLogLinterData(
			$params['page'], $params['revision'], $data
		) ) {
			$this->getResult()->addValue( $this->getModuleName(), 'success', true );
		} else {
			$this->dieWithError( 'apierror-linter-invalid-title', 'invalid-title' );
		}
	}

	public function isInternal() {
		return true;
	}

	public function getAllowedParams() {
		return [
			'data' => [
				ApiBase::PARAM_TYPE => 'string',
				ApiBase::PARAM_REQUIRED => true,
			],
			'page' => [
				ApiBase::PARAM_TYPE => 'string',
				ApiBase::PARAM_REQUIRED => true,
			],
			'revision' => [
				ApiBase::PARAM_TYPE => 'integer',
				ApiBase::PARAM_REQUIRED => true,
			],
		];
	}
}

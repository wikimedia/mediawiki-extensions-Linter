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
use MediaWiki\MediaWikiServices;
use Wikimedia\IPSet;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * API module for an external service to record
 * a lint error
 */
class ApiRecordLint extends ApiBase {

	public function execute() {
		$mwServices = MediaWikiServices::getInstance();
		$linterSubmitterAllowlist = $mwServices->getMainConfig()->get( 'LinterSubmitterWhitelist' );
		$ipSet = new IPSet(
			array_keys( array_filter( $linterSubmitterAllowlist ) )
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

	/** @inheritDoc */
	public function getAllowedParams() {
		return [
			'data' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'page' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'revision' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
			],
		];
	}
}

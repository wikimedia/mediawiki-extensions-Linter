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

use MediaWiki\MediaWikiServices;

// PHP unit does not understand code coverage for this file
// as the @covers annotation cannot cover a specific file
// This is fully tested in CategoryManagerServiceWiringTest.php
// @codeCoverageIgnoreStart
/**
 * Linter wiring for MediaWiki services.
 */
return [
	'Linter.CategoryManager' => static function ( MediaWikiServices $services ): CategoryManager {
		return new CategoryManager(
			$services->getMainConfig()->get( 'LinterCategories' )
		);
	},
	'Linter.DatabaseFactory' => static function ( MediaWikiServices $services ): DatabaseFactory {
		$config = $services->getMainConfig();
		return new DatabaseFactory(
			[
				'writeNamespaceColumn' => $config->get( 'LinterWriteNamespaceColumnStage' ),
				'writeTagAndTemplateColumns' => $config->get( 'LinterWriteTagAndTemplateColumnsStage' ),
			],
			$services->get( 'Linter.CategoryManager' ),
			$services->getDBLoadBalancerFactory()
		);
	},
	'Linter.TotalsLookup' => static function ( MediaWikiServices $services ): TotalsLookup {
		$config = $services->getMainConfig();
		return new TotalsLookup(
			[
				'sampleFactor' => $config->get( 'LinterStatsdSampleFactor' ),
			],
			$services->getMainWANObjectCache(),
			$services->getStatsdDataFactory(),
			$services->get( 'Linter.CategoryManager' )
		);
	},
];
// @codeCoverageIgnoreEnd

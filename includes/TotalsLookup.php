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

use MediaWiki\Config\ServiceOptions;
use Wikimedia\ObjectCache\WANObjectCache;

/**
 * Lookup to find and cache the total amount of
 * lint errors in each category
 */
class TotalsLookup {
	public const CONSTRUCTOR_OPTIONS = [
		'LinterStatsdSampleFactor',
	];

	public function __construct(
		private readonly ServiceOptions $options,
		private readonly WANObjectCache $cache,
		private readonly CategoryManager $categoryManager,
		private readonly Database $database,
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	private function makeKey( string $cat ): string {
		return $this->cache->makeKey( 'linter', 'total', $cat );
	}

	/**
	 * Get the totals for every category in the database
	 *
	 * @return array<string,int>
	 */
	public function getTotals(): array {
		$cats = $this->categoryManager->getVisibleCategories();
		$fetchedTotals = false;
		$totals = [];
		foreach ( $cats as $cat ) {
			$totals[$cat] = $this->cache->getWithSetCallback(
				$this->makeKey( $cat ),
				WANObjectCache::TTL_INDEFINITE,
				function () use ( $cat, &$fetchedTotals ) {
					if ( $fetchedTotals === false ) {
						$fetchedTotals = $this->database->getTotals();
					}
					return $fetchedTotals[$cat];
				},
				[
					'checkKeys' => [
						$this->cache->makeKey( 'linter', 'totals' ),
						$this->makeKey( $cat ),
					],
					'lockTSE' => 30,
				]
			);
		}
		return $totals;
	}

	/**
	 * Update totals cache
	 *
	 * @param array $changes
	 */
	public function updateStats( array $changes ) {
		$linterStatsdSampleFactor = $this->options->get( 'LinterStatsdSampleFactor' );

		if ( $linterStatsdSampleFactor === false ) {
			// Update cache with $changes
			$raw = $changes['added'];
			foreach ( $changes['deleted'] as $cat => $count ) {
				if ( isset( $raw[$cat] ) ) {
					$raw[$cat] -= $count;
				} else {
					// Negative value
					$raw[$cat] = 0 - $count;
				}
			}

			foreach ( $raw as $cat => $count ) {
				if ( $count != 0 ) {
					// There was a change in counts, invalidate the cache
					$this->touchCategoryCache( $cat );
				}
			}
		} elseif ( mt_rand( 1, $linterStatsdSampleFactor ) == 1 ) {
			$this->touchAllCategoriesCache();
		}
	}

	/**
	 * Have a single category be recalculated
	 *
	 * @param string $cat category name
	 */
	public function touchCategoryCache( $cat ) {
		$this->cache->touchCheckKey( $this->makeKey( $cat ) );
	}

	/**
	 * Have all categories be recalculated
	 */
	public function touchAllCategoriesCache() {
		$this->cache->touchCheckKey( $this->cache->makeKey( 'linter', 'totals' ) );
	}
}

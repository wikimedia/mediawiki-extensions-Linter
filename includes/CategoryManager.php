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

/**
 * Functions for lint error categories
 */
class CategoryManager {

	const HIGH = 'high';
	const MEDIUM = 'medium';
	const LOW = 'low';

	/**
	 * Map of category names to their hardcoded
	 * numerical ids for use in the database
	 *
	 * @var int[]
	 */
	private $categoryIds = [
		'fostered' => 1,
		'obsolete-tag' => 2,
		'bogus-image-options' => 3,
		'missing-end-tag' => 4,
		'stripped-tag' => 5,
		'self-closed-tag' => 6,
		'deletable-table-tag' => 7,
		'misnested-tag' => 8,
		'pwrap-bug-workaround' => 9,
		'tidy-whitespace-bug' => 10
	];

	/**
	 * @var string[]
	 */
	private $categories = [
		self::HIGH => [],
		self::MEDIUM => [],
		self::LOW => [],
	];

	/**
	 * @var string[]
	 */
	private $parserMigrationCategories = [];

	public function __construct() {
		global $wgLinterCategories;
		foreach ( $wgLinterCategories as $name => $info ) {
			if ( $info['enabled'] ) {
				$this->categories[$info['priority']][] = $name;
			}
			if ( isset( $info['parser-migration'] ) ) {
				$this->parserMigrationCategories[$name] = true;
			}
		}

		sort( $this->categories[self::HIGH] );
		sort( $this->categories[self::MEDIUM] );
		sort( $this->categories[self::LOW] );
	}

	public function needsParserMigrationEdit( $name ) {
		return isset( $this->parserMigrationCategories[$name] );
	}

	/**
	 * @return string[]
	 */
	public function getHighPriority() {
		return $this->categories[self::HIGH];
	}

	/**
	 * @return string[]
	 */
	public function getMediumPriority() {
		return $this->categories[self::MEDIUM];
	}

	/**
	 * @return string[]
	 */
	public function getLowPriority() {
		return $this->categories[self::LOW];
	}

	/**
	 * Categories that are configured to be displayed to users
	 *
	 * @return string[]
	 */
	public function getVisibleCategories() {
		return array_merge(
			$this->categories[self::HIGH],
			$this->categories[self::MEDIUM],
			$this->categories[self::LOW]
		);
	}

	/**
	 * Whether this category has a hardcoded id and can be
	 * inserted into the database
	 *
	 * @param string $name
	 * @return bool
	 */
	public function isKnownCategory( $name ) {
		return isset( $this->categoryIds[$name] );
	}

	/**
	 * @param int $id
	 * @throws \RuntimeException if we can't find the name for the id
	 * @return string
	 */
	public function getCategoryName( $id ) {
		$flip = array_flip( $this->categoryIds );
		if ( isset( $flip[$id] ) ) {
			return $flip[$id];
		}

		throw new \RuntimeException( "Could not find name for id $id" );
	}

	/**
	 * @param string[] $names
	 * @return int[]
	 */
	public function getCategoryIds( array $names ) {
		$result = [];
		foreach ( $names as $name ) {
			$result[$name] = $this->getCategoryId( $name );
		}

		return $result;
	}

	/**
	 * Get the int id for the category in lint_categories table
	 *
	 * @param string $name
	 * @return int
	 * @throws \RuntimeException if we can't find the id for the name
	 */
	public function getCategoryId( $name ) {
		if ( isset( $this->categoryIds[$name] ) ) {
			return $this->categoryIds[$name];
		}

		throw new \RuntimeException( "Cannot find id for '$name'" );
	}
}

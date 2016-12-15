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

	const ERROR = 'error';
	const WARNING = 'warning';

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
	];

	/**
	 * @var string[]
	 */
	private $errors = [];

	/**
	 * @var string[]
	 */
	private $warnings = [];

	public function __construct() {
		global $wgLinterCategories;
		foreach ( $wgLinterCategories as $name => $info ) {
			if ( $info['enabled'] ) {
				if ( $info['severity'] === self::ERROR ) {
					$this->errors[] = $name;
				} elseif ( $info['severity'] === self::WARNING ) {
					$this->warnings[] = $name;
				}
			}
		}
		sort( $this->errors );
		sort( $this->warnings );
	}

	/**
	 * @return string[]
	 */
	public function getErrors() {
		return $this->errors;
	}

	/**
	 * @return string[]
	 */
	public function getWarnings() {
		return $this->warnings;
	}

	/**
	 * Categories that are configure to be displayed to users
	 *
	 * @return string[]
	 */
	public function getVisibleCategories() {
		return array_merge( $this->errors, $this->warnings );
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

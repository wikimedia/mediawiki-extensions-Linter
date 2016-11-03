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

/**
 * Functions for lint error categories
 */
class CategoryManager {

	/**
	 * @var array|bool
	 */
	private $map;
	/**
	 * @var \BagOStuff
	 */
	private $cache;
	/**
	 * @var string
	 */
	private $cacheKey;

	private function __construct() {
		$this->cache = MediaWikiServices::getInstance()->getLocalServerObjectCache();
		$this->cacheKey = $this->cache->makeKey( 'linter', 'categories' );
	}

	public static function getInstance() {
		static $self;
		if ( !$self ) {
			$self = new self();
		}

		return $self;
	}

	public function getCategories() {
		global $wgLinterCategories;
		return array_keys( array_filter( $wgLinterCategories ) );
	}

	/**
	 * @see getCategoryId
	 * @param string $name
	 * @return bool|int
	 */
	public function getAndMaybeCreateCategoryId( $name ) {
		return $this->getCategoryId( $name, true );
	}

	/**
	 * @param string $id
	 * @throws \RuntimeException
	 * @return int
	 */
	public function getCategoryName( $id ) {
		if ( !$this->map ) {
			$this->loadMapFromCache();
		}

		if ( $this->map ) {
			$flip = array_flip( $this->map );
			if ( isset( $flip[$id] ) ) {
				return $flip[$id];
			}
		}

		$this->loadMapFromDB();
		$flip = array_flip( $this->map );
		if ( isset( $flip[$id] ) ) {
			return $flip[$id];
		}

		throw new \RuntimeException( "Could not find name for id $id" );
	}

	public function getCategoryIds( array $names ) {
		$result = [];
		foreach ( $names as $name ) {
			$result[$name] = $this->getCategoryId( $name );
		}

		return $result;
	}

	private function loadMapFromCache() {
		$this->map = $this->cache->get( $this->cacheKey );
	}

	private function saveMapToCache() {
		$this->cache->set( $this->cacheKey, $this->map );
	}

	/**
	 * Get the int id for the category in lint_categories table
	 *
	 * @param string $name
	 * @param bool $create Whether to create an id if missing
	 * @return int|bool
	 */
	public function getCategoryId( $name, $create = false ) {
		// Check static cache
		if ( !$this->map ) {
			$this->loadMapFromCache();
		}

		if ( $this->map && isset( $this->map[$name] ) ) {
			return $this->map[$name];
		}

		// Cache miss, hit the DB (replica)
		$this->loadMapFromDB();
		if ( isset( $this->map[$name] ) ) {
			$this->saveMapToCache();
			return $this->map[$name];
		}

		if ( !$create ) {
			return false;
		}

		// Not in DB, create a new ID
		$dbw = wfGetDB( DB_MASTER );
		$dbw->insert(
			'lint_categories',
			[ 'lc_name' => $name ],
			__METHOD__,
			[ 'IGNORE' ]
		);
		if ( $dbw->affectedRows() ) {
			$id = $dbw->insertId();
		} else {
			// Raced out, get the inserted id
			$id = $dbw->selectField(
				'lint_categories',
				'lc_id',
				[ 'lc_name' => $name ],
				__METHOD__,
				[ 'LOCK IN SHARE MODE' ]
			);
		}

		$this->map[$name] = (int)$id;
		$this->saveMapToCache();
		return $this->map[$name];
	}

	private function loadMapFromDB() {
		$rows = wfGetDB( DB_REPLICA )->select(
			'lint_categories',
			[ 'lc_id', 'lc_name' ],
			[],
			__METHOD__
		);
		$this->map = [];
		foreach ( $rows as $row ) {
			$this->map[$row->lc_name] = (int)$row->lc_id;
		}
	}

}

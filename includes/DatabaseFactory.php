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

use Wikimedia\Rdbms\LBFactory;

/**
 * Create a Database helper specialized to a particular page id and namespace.
 */
class DatabaseFactory {
	private array $options;
	private CategoryManager $categoryManager;
	private LBFactory $dbLoadBalancerFactory;

	/**
	 * @param array $options
	 * @param CategoryManager $categoryManager
	 * @param LBFactory $dbLoadBalancerFactory
	 */
	public function __construct(
		array $options,
		CategoryManager $categoryManager,
		LBFactory $dbLoadBalancerFactory
	) {
		$this->options = $options;
		$this->categoryManager = $categoryManager;
		$this->dbLoadBalancerFactory = $dbLoadBalancerFactory;
	}

	/**
	 * Create a new Database helper.
	 * @param int $pageId
	 * @param ?int $namespaceId
	 * @return Database
	 */
	public function newDatabase( int $pageId, ?int $namespaceId = null ): Database {
		return new Database(
			$pageId,
			$namespaceId,
			$this->options,
			$this->categoryManager,
			$this->dbLoadBalancerFactory
		);
	}
}

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

use MediaWiki\Content\Renderer\ContentParseParams;
use MediaWiki\Content\TextContent;
use MediaWiki\Deferred\DataUpdate;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Revision\RenderedRevision;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use Wikimedia\Stats\StatsFactory;

class LintUpdate extends DataUpdate {

	private WikiPageFactory $wikiPageFactory;
	private StatsFactory $statsFactory;
	private RenderedRevision $renderedRevision;

	public function __construct(
		WikiPageFactory $wikiPageFactory,
		StatsFactory $statsFactory,
		RenderedRevision $renderedRevision
	) {
		parent::__construct();
		$this->wikiPageFactory = $wikiPageFactory;
		$this->statsFactory = $statsFactory;
		$this->renderedRevision = $renderedRevision;
	}

	public function doUpdate() {
		$rev = $this->renderedRevision->getRevision();
		$mainSlot = $rev->getSlot( SlotRecord::MAIN, RevisionRecord::RAW );

		$page = $this->wikiPageFactory->newFromTitle( $rev->getPage() );

		if ( $page->getLatest() !== $rev->getId() ) {
			// The given revision is no longer the latest revision.
			return;
		}

		$content = $mainSlot->getContent();
		if ( !$content instanceof TextContent ) {
			// Linting is only defined for text
			return;
		}

		$pOptions = $page->makeParserOptions( 'canonical' );
		$pOptions->setUseParsoid();
		$pOptions->setRenderReason( 'LintUpdate' );

		// XXX no previous output available on this code path
		$previousOutput = null;

		LoggerFactory::getInstance( 'Linter' )->debug(
			'{method}: Parsing {page}',
			[
				'method' => __METHOD__,
				'page' => $page->getTitle()->getPrefixedDBkey(),
				'touched' => $page->getTouched()
			]
		);

		// Don't update the parser cache, to avoid flooding it.
		// This matches the behavior of RefreshLinksJob.
		// However, unlike RefreshLinksJob, we don't parse if we already
		// have the output in the cache. This avoids duplicating the effort
		// of ParsoidCachePrewarmJob.
		$cpoParams = new ContentParseParams(
			$rev->getPage(),
			$rev->getId(),
			$pOptions,
			// no need to generate HTML
			false,
			$previousOutput
		);
		$output = $content->getContentHandler()->getParserOutput( $content, $cpoParams );

		// T371713: Temporary statistics collection code to determine
		// feasibility of Parsoid selective update
		$sampleRate = MediaWikiServices::getInstance()->getMainConfig()->get(
			MainConfigNames::ParsoidSelectiveUpdateSampleRate
		);
		$doSample = ( $sampleRate && mt_rand( 1, $sampleRate ) === 1 );
		if ( $doSample ) {
			$labels = [
				'source' => 'LintUpdate',
				'type' => 'full',
				'reason' => $pOptions->getRenderReason(),
				'parser' => 'parsoid',
				'opportunistic' => 'false',
			];
			$totalStat = $this->statsFactory
				->getCounter( 'parsercache_selective_total' );
			$timeStat = $this->statsFactory
				->getCounter( 'parsercache_selective_cpu_seconds' );
			foreach ( $labels as $key => $value ) {
				$totalStat->setLabel( $key, $value );
				$timeStat->setLabel( $key, $value );
			}
			$totalStat->increment();
			$timeStat->incrementBy( $output->getTimeProfile( 'cpu' ) );
		}
	}
}
